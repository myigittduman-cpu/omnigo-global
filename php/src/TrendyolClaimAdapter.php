<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent read queue with a saved scan position and an atomic published view. */
final class TrendyolClaimAdapter implements ScheduledReadAdapter
{
    public const JOB = 'Trendyol İade Talepleri';
    private const CAPACITY = 20000;
    private const LOOKBACK = 2 * TrendyolClaimClient::WINDOW;
    private int $delay = 60;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private TrendyolClaimClient $client = new TrendyolClaimClient(),
    ) {}

    public function nextDelay(): int { return $this->delay; }

    public static function run(Database $db, IntegrationVault $vault, int $deadline): void
    {
        if (!PazaramaCommissionStore::available($db)) return;
        $queue = new SyncQueue($db, [self::JOB=>new self($db, $vault)]);
        foreach ($db->all("SELECT company_id FROM records WHERE kind='integration_trendyol'") as $row) {
            $company = (int)$row['company_id'];
            try {
                if (!empty($vault->get($company, 'trendyol')['enabled'])) $queue->enqueue($company, self::JOB);
            } catch (\Throwable $error) {
                error_log('Omnigo Trendyol iade kurulumu: '.get_class($error));
            }
        }
        if (time() < $deadline) $queue->runOne(null, [self::JOB], $deadline);
    }

    public function syncReadOnly(int $companyId, int $deadline): void
    {
        $this->delay = 60;
        $connection = (new TrendyolClaimStore($this->db))->connection($companyId);
        $config = $this->vault->get($companyId, 'trendyol');
        if (!$connection || empty($config['enabled'])) throw new SyncFailure(403);

        $store = new FinanceStore($this->db);
        $before = $store->state($companyId, TrendyolClaimStore::KEY);
        $old = json_decode($before['payload'] ?? '{}', true, 56, JSON_THROW_ON_ERROR);
        $state = ($old['connection'] ?? null) === $connection ? $old : ['connection'=>$connection];
        $now = time()*1000;
        $start = min($now-self::LOOKBACK+1, isset($state['throughMs']) ? $state['throughMs']+1 : $now);
        $scan = $state['scan'] ?? ['start'=>$start, 'from'=>$start, 'through'=>$now,
            'page'=>0, 'created'=>false, 'seen'=>[], 'items'=>[]];
        $to = min($scan['from']+TrendyolClaimClient::WINDOW-1, $scan['through']);

        try {
            $result = $this->client->page($config, $scan['from'], $to, $scan['page'], $scan['created'], $deadline);
            $this->accumulate($scan, $result);
        } catch (SyncFailure $error) {
            if ($error->httpStatus === 409 && isset($state['scan'])) {
                unset($state['scan']);
                $this->commit($companyId, $connection, $store, $before, $state);
            }
            throw $error;
        }

        if (!$result['complete']) {
            $scan['page']++;
        } elseif (!$scan['created']) {
            // Trendyol documents an explicit Created query for new requests.
            $scan = [...$scan, 'created'=>true, 'seen'=>[], 'page'=>0];
        } elseif ($to < $scan['through']) {
            $scan = [...$scan, 'from'=>$to+1, 'created'=>false, 'seen'=>[], 'page'=>0];
        } else {
            $this->publish($state, $scan);
            $this->commit($companyId, $connection, $store, $before, $state);
            $this->delay = 600;
            return;
        }
        if ($result['complete']) unset($scan['total']);
        $state['scan'] = $scan;
        $this->commit($companyId, $connection, $store, $before, $state);
    }

    private function accumulate(array &$scan, array $result): void
    {
        $ids = array_fill_keys($result['ids'], true);
        if (array_intersect_key($ids, $scan['seen']) || ($scan['total'] ?? $result['total']) !== $result['total']) {
            throw new SyncFailure(409);
        }
        $scan['seen'] += $ids;
        $scan['total'] = $result['total'];
        foreach ($result['items'] as $item) {
            $current = $scan['items'][$item['id']] ?? null;
            if ($current && $item['sourceUpdatedMs'] === $current['sourceUpdatedMs'] && $item !== $current) {
                throw new SyncFailure(409);
            }
            if (!$current || $item['sourceUpdatedMs'] > $current['sourceUpdatedMs']) $scan['items'][$item['id']] = $item;
        }
        if (count($scan['items']) > self::CAPACITY) throw new SyncFailure(422);
    }

    private function publish(array &$state, array $scan): void
    {
        $items = array_values($scan['items']);
        foreach ($items as &$item) unset($item['sourceUpdatedMs']);
        unset($item);
        $date = static fn(int $value): string => gmdate(DATE_ATOM, intdiv($value, 1000));
        ReturnRequestSnapshot::publish($state, $items, $date($scan['start']), $date($scan['through']));
        if (!empty($state['returnRequestError'])) throw new SyncFailure(422);
        $state['throughMs'] = $scan['through'];
        unset($state['scan']);
    }

    private function commit(int $company, string $connection, FinanceStore $store, ?array $before, array $state): void
    {
        TransactionRetry::run($this->db, function () use ($company, $connection, $store, $before, $state) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(), [$company]);
            $current = $store->state($company, TrendyolClaimStore::KEY);
            if (($current['version'] ?? null) !== ($before['version'] ?? null)
                || (new TrendyolClaimStore($this->db))->connection($company) !== $connection) {
                throw new SyncFailure(409);
            }
            $store->writeState($company, TrendyolClaimStore::KEY, $state, $current);
        });
    }
}
