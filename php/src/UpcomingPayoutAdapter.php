<?php
declare(strict_types=1);
namespace Omnigo;

/** Advances one bounded page per lease and publishes only complete accounting reads. */
final class UpcomingPayoutAdapter implements ScheduledReadAdapter
{
    public const JOB = 'Yaklaşan: n11';
    public const PROVIDER = 'upcoming_n11';
    public const JOBS = [
        'n11' => self::JOB,
        'hepsiburada' => 'Yaklaşan: Hepsiburada',
        'pazarama' => 'Yaklaşan: Pazarama',
    ];
    public const REFRESH_INTERVAL = 1800;
    private int $delay = 60;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private ?N11FinanceClient $reader = null,
        private string $provider = 'n11',
        private ?FinanceClient $finance = null,
        private ?PazaramaAuthClient $pazaramaAuth = null,
    ) {
        if (!isset(self::JOBS[$provider])) throw new \InvalidArgumentException('Hakediş kaynağı geçersiz.');
    }

    public function nextDelay(): int { return $this->delay; }

    public static function run(Database $db, IntegrationVault $vault, int $deadline): void
    {
        if (!PazaramaCommissionStore::available($db)) return;
        $adapters = [];
        foreach (self::JOBS as $provider => $job) {
            $adapters[$job] = new self($db,$vault,provider:$provider);
        }
        $queue = new SyncQueue($db,$adapters);
        self::schedule($db,$vault,$queue);
        $jobs = array_keys($adapters);
        while (time()+3 < $deadline && $queue->runOne(null,$jobs,$deadline)) {}
    }

    public static function schedule(Database $db, IntegrationVault $vault, SyncQueue $queue): void
    {
        foreach (self::JOBS as $provider => $job) {
            $candidates = $db->all('SELECT DISTINCT r.company_id FROM records r
                LEFT JOIN jobs j ON j.company_id=r.company_id AND j.provider=?
                WHERE r.kind=? AND j.id IS NULL',[$job,'integration_'.$provider]);
            foreach ($candidates as $row) {
                $company = (int)$row['company_id'];
                try {
                    if (!empty($vault->get($company,$provider)['enabled'])) $queue->enqueue($company,$job);
                } catch (\Throwable $error) {
                    error_log('Omnigo hakediş bağlantısı '.$provider.': '.get_class($error));
                }
            }
        }
    }

    public function syncReadOnly(int $companyId, int $deadline): void
    {
        $this->delay = 60;
        $store = new UpcomingPayoutStore($this->db);
        $pilot = $companyId === $store->pilot();
        $groupByTuesday = $pilot && $this->provider === 'hepsiburada';
        $connection = $store->connection($companyId,$this->provider);
        $settings = $this->vault->get($companyId,$this->provider);
        if (!$connection || empty($settings['enabled'])) throw new SyncFailure(403);
        $today = new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul'));
        $start = $today->format('Y-m-d');
        [$first,$last] = $this->range($today,$groupByTuesday);
        $limit = $last->format('Y-m-d');
        $finance = new FinanceStore($this->db);
        $before = $finance->state($companyId,self::stateKey($this->provider));
        $payload = json_decode($before['payload'] ?? '{}',true,64,JSON_THROW_ON_ERROR);
        $scan = $payload['scan'] ?? [];
        if (($scan['connection'] ?? null) !== $connection || ($scan['today'] ?? '') !== $start
            || time()-(int)($scan['startedAt'] ?? 0) >= self::REFRESH_INTERVAL) {
            $scan = ['connection'=>$connection, 'today'=>$start, 'window'=>$first->format('Y-m-d'),
                'cursor'=>0, 'count'=>null, 'seen'=>[], 'days'=>[],'startedAt'=>time()];
        }
        $from = $scan['window'];
        $windowDays = $this->provider === 'pazarama' ? 14 : 30;
        $through = min($limit,(new \DateTimeImmutable($from))->modify('+'.($windowDays-1).' days')->format('Y-m-d'));
        try {
            $page = $this->page($settings,$from,$through,$scan['cursor'],$deadline);
            $this->collect($scan,$page,$start,$limit,$groupByTuesday);
        } catch (SyncFailure $error) {
            // Changed pagination must restart from the first page, never merge two snapshots.
            if ($error->httpStatus === 409) {
                unset($payload['scan']);
                $this->save($store,$finance,$companyId,$before,$connection,$payload);
            }
            throw $error;
        }
        $next = $page['cursor'];
        $complete = $next === null && $through === $limit;
        if ($complete) {
            unset($payload['scan']);
            $known=[...($payload['knownDates']??[]),...array_keys($payload['published']['days']??[]),...array_keys($scan['days'])];
            $payload['knownDates']=array_values(array_unique(array_filter($known,static fn(string $day):bool=>$day>=$start)));
            $payload['published'] = ['connection'=>$connection, 'checkedAt'=>time(),
                'days'=>array_filter($scan['days'],static fn(int $amount): bool => $amount > 0)];
            $this->delay = self::REFRESH_INTERVAL;
        } else {
            if ($next === null) {
                $scan['window'] = (new \DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d');
                $scan['count'] = null;
            }
            $scan['cursor'] = $next ?? 0;
            $payload['scan'] = $scan;
        }
        $this->save($store,$finance,$companyId,$before,$connection,$payload);
        if ($complete && !$pilot && UpcomingPayoutReviewStore::available($this->db)) {
            (new UpcomingPayoutReviewService($this->db))->observe($companyId);
        }
    }

    public static function stateKey(string $provider): string
    {
        return 'upcoming_'.$provider;
    }

    /** Separate caller-owned progress for a new-dates-only check. */
    public function readNewDatePage(int $company,array $cursor,int $deadline): array
    {
        $store=new UpcomingPayoutStore($this->db);
        $connection=$store->connection($company,$this->provider);
        $settings=$this->vault->get($company,$this->provider);
        if(!$connection||empty($settings['enabled']))throw new SyncFailure(403);
        if(($cursor['connection']??$connection)!==$connection)throw new SyncFailure(409);
        $groupByTuesday=$company===$store->pilot()&&$this->provider==='hepsiburada';
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul'));
        $date=$today->format('Y-m-d');
        [$first,$last]=$this->range($today,$groupByTuesday);
        $limit=$last->format('Y-m-d');
        if(($cursor['today']??null)!==$date){
            $cursor=['connection'=>$connection,'today'=>$date,'window'=>$first->format('Y-m-d'),
                'page'=>0,'count'=>null,'seen'=>[],'days'=>[]];
        }
        $from=$cursor['window'];
        $windowDays=$this->provider==='pazarama'?14:30;
        $through=min($limit,(new \DateTimeImmutable($from))->modify('+'.($windowDays-1).' days')->format('Y-m-d'));
        $page=$this->page($settings,$from,$through,$cursor['page'],$deadline);
        $this->collect($cursor,$page,$date,$limit,$groupByTuesday);
        $next=$page['cursor'];
        if($next===null&&$through===$limit)return ['cursor'=>$cursor,'complete'=>true];
        if($next===null){
            $cursor['window']=(new \DateTimeImmutable($through))->modify('+1 day')->format('Y-m-d');
            $cursor['count']=null;
        }
        $cursor['page']=$next??0;
        return ['cursor'=>$cursor,'complete'=>false];
    }

    private function range(\DateTimeImmutable $today,bool $groupByTuesday): array
    {
        if ($this->provider === 'hepsiburada') {
            if (!$groupByTuesday) return [$today,$today->modify('+41 days')];
            $firstPayment = self::hepsiburadaPaymentDay($today);
            return [$firstPayment->modify('-6 days'),$firstPayment->modify('+5 weeks')];
        }
        if ($this->provider === 'pazarama') return [$today,$today->modify('+55 days')];
        return [$today->modify('first day of last month'),$today->modify('last day of +2 months')];
    }

    private function page(array $settings,string $from,string $through,int $cursor,int $deadline): array
    {
        if ($this->provider === 'hepsiburada') {
            return ($this->finance ?? new FinanceClient())->hepsiburada(
                HepsiburadaCredentials::fromArray($settings),$from,$through,$cursor,$deadline);
        }
        if ($this->provider === 'pazarama') {
            $credentials = PazaramaCredentials::fromArray($settings);
            $auth = ($this->pazaramaAuth ?? new PazaramaAuthClient())->authenticate($credentials,$deadline);
            PazaramaSellerIdentity::verify($auth['accessToken'],$credentials->sellerId);
            return ($this->finance ?? new FinanceClient())->pazaramaUpcoming($auth['accessToken'],$from,$through,$deadline);
        }
        return ($this->reader ?? new N11FinanceClient())->settlements(
            N11Credentials::fromArray($settings),$from,$through,$cursor,$deadline);
    }

    private static function hepsiburadaPaymentDay(\DateTimeImmutable $due): \DateTimeImmutable
    {
        // VİZYONA's forecast groups the signed Wednesday-to-Tuesday movements under Tuesday.
        $days = (9-(int)$due->format('N'))%7;
        return $due->modify('+'.$days.' days');
    }

    private function collect(array &$scan, array $page, string $today, string $limit, bool $groupByTuesday): void
    {
        $count = $page['count'] ?? null;
        if ($scan['count'] !== null && $scan['count'] !== $count) throw new SyncFailure(409);
        $scan['count'] = $count;
        foreach ($page['entries'] as $entry) {
            $key = $entry['key'];
            if (isset($scan['seen'][$key])) throw new SyncFailure(409);
            $scan['seen'][$key] = true;
            if ($entry['state'] !== 'pending') continue;
            $day = $entry['expectedDate'];
            if ($day !== '' && $groupByTuesday) {
                $day = self::hepsiburadaPaymentDay(new \DateTimeImmutable($day))->format('Y-m-d');
            }
            if ($day === '' || $day < $today || $day > $limit) continue;
            $sum = ($scan['days'][$day] ?? 0) + $entry['netCents'];
            if (!is_int($sum)) throw new SyncFailure(422);
            $scan['days'][$day] = $sum;
        }
        if (count($scan['seen']) > 10000) throw new SyncFailure(422);
    }

    private function save(UpcomingPayoutStore $store, FinanceStore $finance, int $company,
        ?array $before, string $connection, array $payload): void
    {
        $this->db->transaction(function () use ($store,$finance,$company,$before,$connection,$payload) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $key = self::stateKey($this->provider);
            $current = $finance->state($company,$key);
            if (($before['version'] ?? 0) !== ($current['version'] ?? 0)
                || $store->connection($company,$this->provider) !== $connection) throw new SyncFailure(409);
            $finance->writeState($company,$key,$payload,$current);
        });
    }
}
