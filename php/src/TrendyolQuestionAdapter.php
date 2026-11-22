<?php
declare(strict_types=1);
namespace Omnigo;

/** Store-scoped question scans with one bounded read per queue lease. */
final class TrendyolQuestionAdapter implements ScheduledReadAdapter
{
    public const STATE_KEY = 'trendyol_questions';
    private const ANSWERED_PAGES = 6;
    private int $delay = 65;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private TrendyolQuestionClient $client = new TrendyolQuestionClient()
    ) {}

    public function nextDelay(): int { return $this->delay; }

    public static function schedule(Database $db, IntegrationVault $vault, SyncQueue $queue): void
    {
        $hasState = $db->mysql()
            ? $db->one("SHOW TABLES LIKE 'finance_sync'")
            : $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='finance_sync'");
        if (!$hasState || !(new InboxRepository($db))->ready()) return;

        foreach ($db->all("SELECT company_id FROM records WHERE kind='integration_trendyol'") as $row) {
            $company = (int)$row['company_id'];
            if (self::revision($vault->get($company, TrendyolQuestionClient::PROVIDER)) !== null) {
                $queue->enqueue($company, TrendyolQuestionClient::JOB);
            }
        }
    }

    public function syncReadOnly(int $companyId, int $deadline): void
    {
        $this->delay = 65;
        $config = $this->vault->get($companyId, TrendyolQuestionClient::PROVIDER);
        $revision = self::revision($config);
        if ($revision === null) throw new SyncFailure(403);

        $store = new FinanceStore($this->db);
        $before = $store->state($companyId, self::STATE_KEY);
        $previous = $before ? json_decode($before['payload'], true, 64, JSON_THROW_ON_ERROR) : [];
        $state = ($previous['revision'] ?? null) === $revision ? $previous : ['revision' => $revision];

        try {
            [$next, $threads, $unavailable, $complete] = $this->step($companyId, $config, $state, $deadline);
            unset($next['failure']);
            $next['phase'] = $complete ? 'ready' : 'syncing';
            $next['lastPageAt'] = gmdate('c');
            if ($complete) {
                $next['lastSuccessAt'] = $next['lastPageAt'];
                $this->delay = 300;
            }
            $this->commit($companyId, $before, $revision, $next, $threads, $unavailable);
        } catch (SyncFailure $error) {
            if ($error->httpStatus !== 409) {
                $state['failure'] = ['code' => $error->httpStatus];
                $this->commit($companyId, $before, $revision, $state, [], []);
            }
            throw $error;
        }
    }

    private function step(int $company, array $config, array $state, int $deadline): array
    {
        if (!empty($state['pending'])) {
            $id = (string)array_shift($state['pending']);
            try {
                return [$state, [$this->client->detail($config, $id, $deadline)], [], false];
            } catch (SyncFailure $error) {
                if (!in_array($error->httpStatus, [404, 410], true)) throw $error;
                return [$state, [], [$id], false];
            }
        }

        $stream = $state['stream'] ?? TrendyolQuestionClient::WAITING;
        $page = (int)($state['page'] ?? 0);
        $end = $state['through'] ?? time() * 1000;
        $state['through'] = $end;
        $batch = $this->client->page($config, $stream, $end - TrendyolQuestionClient::WINDOW, $end, $page, $deadline);
        $threads = $batch['threads'];
        $ids = array_column($threads, 'id');
        $signature = hash('sha256', implode(',', $ids));
        if ($ids && in_array($signature, $state['pages'] ?? [], true)) throw new SyncFailure(422);
        $state['pages'][] = $signature;
        if ($stream === TrendyolQuestionClient::WAITING) {
            $state['seen'] = ($state['seen'] ?? []) + array_fill_keys($ids, true);
        }
        if (!$batch['complete'] && ($stream !== TrendyolQuestionClient::ANSWERED || $page+1 < self::ANSWERED_PAGES)) {
            $state['page'] = $page + 1;
            return [$state, $threads, [], false];
        }
        if ($stream === TrendyolQuestionClient::ANSWERED) {
            unset($state['stream'], $state['page'], $state['through'], $state['seen'], $state['pending'], $state['pages']);
            return [$state, $threads, [], true];
        }

        $status=InboxStatus::sql();
        $waiting = $this->db->all("SELECT remote_id FROM inbox_threads t WHERE t.company_id=? AND t.provider=?
            AND ($status) IN ('waiting','approval_pending') AND ".InboxQuestionWindow::hasCurrentMessageSql(), [
            $company, TrendyolQuestionClient::PROVIDER,
        ]);
        $seen = $state['seen'];
        $state['pending'] = array_values(array_filter(array_column($waiting, 'remote_id'), static fn($id) => !isset($seen[$id])));
        unset($state['seen'], $state['pages']);
        $state['page'] = 0;
        $state['stream'] = TrendyolQuestionClient::ANSWERED;
        return [$state, $threads, [], false];
    }

    private function commit(int $company, ?array $before, string $revision, array $state, array $threads, array $unavailable): void
    {
        $this->db->transaction(function () use ($company, $before, $revision, $state, $threads, $unavailable) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(), [$company]);
            $store = new FinanceStore($this->db);
            $current = $store->state($company, self::STATE_KEY);
            $config = $this->vault->get($company, TrendyolQuestionClient::PROVIDER);
            if (($current['version'] ?? 0) !== ($before['version'] ?? 0) || self::revision($config) !== $revision) {
                throw new SyncFailure(409);
            }

            $inbox = new InboxRepository($this->db);
            foreach ($threads as $thread) $inbox->save($company, $thread, TrendyolQuestionClient::PROVIDER);
            foreach ($unavailable as $id) $inbox->markQuestionUnavailable($company, TrendyolQuestionClient::PROVIDER, $id);
            $store->writeState($company, self::STATE_KEY, $state, $current);
        });
    }

    private static function revision(?array $config): ?string
    {
        $revision = $config['revision'] ?? null;
        return !empty($config['enabled']) && is_string($revision) && $revision !== '' ? $revision : null;
    }
}
