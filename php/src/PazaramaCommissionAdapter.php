<?php
declare(strict_types=1);
namespace Omnigo;

/** Each queue lease reads one closed-date window and never creates payout records. */
final class PazaramaCommissionAdapter implements ScheduledReadAdapter
{
    public const JOB = 'Komisyon: Pazarama';
    private int $delay = 60;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private ?FinanceClient $finance = null,
        private ?PazaramaAuthClient $auth = null,
    ) {}

    public function nextDelay(): int { return $this->delay; }

    public static function schedule(Database $db, IntegrationVault $vault, SyncQueue $queue): void
    {
        if (!PazaramaCommissionStore::available($db)) return;
        $candidates = $db->all("SELECT r.company_id FROM records r
            LEFT JOIN jobs j ON j.company_id=r.company_id AND j.provider=?
            WHERE r.kind='integration_pazarama' AND j.id IS NULL ORDER BY r.company_id", [self::JOB]);
        foreach ($candidates as $row) {
            $company = (int)$row['company_id'];
            try {
                if (!empty($vault->get($company, 'pazarama')['enabled'])) $queue->enqueue($company, self::JOB);
            } catch (\Throwable $error) {
                error_log('Omnigo komisyon bağlantısı: '.get_class($error));
            }
        }
    }

    public function syncReadOnly(int $companyId, int $deadline): void
    {
        $this->delay = 60;
        $identity = new PazaramaCommissionStore($this->db);
        $sourceVersion = $identity->sourceVersion($companyId);
        $connection = $this->vault->get($companyId, 'pazarama');
        if (!$sourceVersion || empty($connection['enabled'])) throw new SyncFailure(403);
        $store = new FinanceStore($this->db);
        $before = $store->state($companyId, PazaramaCommissionStore::PROVIDER);
        $state = json_decode($before['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        $scan = $state['scan'] ?? [];
        if (($scan['sourceVersion'] ?? null) !== $sourceVersion) {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul'));
            $start = $today->modify('-90 days')->format('Y-m-d');
            $scan = ['sourceVersion'=>$sourceVersion,'from'=>$start,'to'=>$today->modify('-1 day')->format('Y-m-d'),'window'=>$start,'days'=>[],'unreconciled'=>[]];
        }
        $from = $scan['window'];
        $to = min((new \DateTimeImmutable($from))->modify('+29 days')->format('Y-m-d'), $scan['to']);
        $credentials = PazaramaCredentials::fromArray($connection);
        $token = ($this->auth ?? new PazaramaAuthClient())->authenticate($credentials, $deadline)['accessToken'];
        PazaramaSellerIdentity::verify($token, $credentials->sellerId);
        $window = ($this->finance ?? new FinanceClient())->pazaramaCommission($token, $from, $to, $deadline);
        $scan['days'] += $window['days'];
        $scan['unreconciled'] ??= [];
        if (!$window['reconciled']) $scan['unreconciled'][] = ['from'=>$from,'to'=>$to];
        if ($to === $scan['to']) {
            unset($state['scan'], $scan['window']);
            $state['published'] = [...$scan, 'updatedAt'=>gmdate('c')];
            $this->delay = 21600;
        } else {
            $scan['window'] = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $state['scan'] = $scan;
        }
        $this->db->transaction(function () use ($companyId, $sourceVersion, $before, $state, $store, $identity): void {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(), [$companyId]);
            $current = $store->state($companyId, PazaramaCommissionStore::PROVIDER);
            if (($before['version'] ?? 0) !== ($current['version'] ?? 0) || $sourceVersion !== $identity->sourceVersion($companyId)) {
                throw new SyncFailure(409);
            }
            $store->writeState($companyId, PazaramaCommissionStore::PROVIDER, $state, $current);
        });
    }
}
