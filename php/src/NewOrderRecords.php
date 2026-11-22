<?php
declare(strict_types=1);
namespace Omnigo;

/** Manual catch-up has its own bounded window and never advances the regular checkpoint. */
final class NewOrderRecords
{
    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private DeliveryRepository $deliveries,
        private ?NewOrderReadPage $reader = null,
    ) {}

    public static function baseline(Database $db,int $company,string $provider,int $through): ?array
    {
        if(!RecentOrderSchema::available($db))return null;
        $row=$db->one('SELECT version,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
        $connection=$db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        $state=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        $covered=self::coveredAt($provider,$state);
        if(!$row||!$connection||!$covered)return null;
        $floor=max(1,min($through,$covered)-self::overlap($provider));
        $known=NewOrderRecordStore::knownIds($db,$company,$provider,$floor,$through);
        if($known===null)return null;
        return ['connection'=>$connection['id'].':'.$connection['version'],'version'=>(int)$row['version'],
            'covered'=>$covered,'known'=>$known];
    }

    public function observed(int $company,string $provider,array $cursor,?callable $checkpoint=null): ?array
    {
        // Only an unstarted supplemental scan can be satisfied by a regular read without double counting.
        if(isset($cursor['revision'])||!isset($cursor['ordBaseline']))return null;
        return TransactionRetry::run($this->db,function()use($company,$provider,$cursor,$checkpoint):?array{
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $result=$this->readObservation($company,$provider,$cursor);
            if($result!==null&&$checkpoint!==null)$checkpoint($result);
            return $result;
        });
    }

    private function readObservation(int $company,string $provider,array $cursor): ?array
    {
        $baseline=$cursor['ordBaseline'];
        $connection=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        if(!$connection||$baseline['connection']!==$connection['id'].':'.$connection['version'])throw new SyncFailure(409);
        $config=$this->vault->get($company,$provider);
        if(empty($config['enabled']))throw new SyncFailure(403);
        $row=$this->db->one('SELECT version,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
        if(!$row||(int)$row['version']<=$baseline['version'])return null;
        $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        if(!empty($state['pendingOrders'])||(new OrderImportQueue($this->db))->count($company,$provider)>0)return null;
        $requested=$cursor['through'];
        $window=$this->window($company,$provider,$config,$state,$cursor);
        // Some providers store a verified latest-order cursor under an independent state key.
        $coverage=in_array($provider,['hepsiburada','koctas'],true)?$state:($state['recentOrders']??[]);
        if(!OrderReadCoverage::covers($coverage,$window['from'],$requested,$requested))return null;
        $ids=NewOrderRecordStore::knownIds($this->db,$company,$provider,$window['from'],$requested);
        if($ids===null)return null;
        return ['cursor'=>[],'done'=>true,'added'=>count(array_diff($ids,$baseline['known']))];
    }

    public function step(int $company, string $provider, array $cursor, int $deadline,?callable $checkpoint=null): array
    {
        $label = CustomerMarketplaces::PROVIDERS[$provider] ?? null;
        $config = $this->vault->get($company, $provider);
        if (!$label || empty($config['enabled']) || ($provider==='hepsiburada' && empty($config['ordersEnabled']))) {
            throw new SyncFailure(403);
        }
        if (!RecentOrderSchema::available($this->db)) throw new SyncFailure(503);

        $job = $this->db->one('SELECT state,next_run,lease_until,attempts FROM jobs WHERE company_id=? AND provider=?', [$company,$label]);
        $row = $this->db->one('SELECT version,payload FROM records WHERE company_id=? AND kind=?', [$company,'sync_'.$provider]);
        $state = json_decode($row['payload'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        $this->checkAvailability($job, $state);

        $revision = (string)($config['revision'] ?? '');
        if ($revision === '' || (isset($cursor['revision']) && $cursor['revision'] !== $revision)) throw new SyncFailure(409);
        if (isset($cursor['ordBaseline'])) {
            $current=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
            if(!$current||$cursor['ordBaseline']['connection']!==$current['id'].':'.$current['version'])throw new SyncFailure(409);
        }
        $cursor = $this->window($company, $provider, $config, $state, $cursor);
        if ($cursor['from'] >= $cursor['through']) return ['cursor'=>$cursor,'done'=>true,'added'=>0];

        $reader = $this->reader ?? new NewOrderReadPage($this->db);
        $store = new NewOrderRecordStore($this->db, $this->deliveries);
        $page = $reader->read($company, $provider, $config, $state, $cursor, $deadline, $store);
        $outcome=static fn(int $added):array=>['cursor'=>$page['cursor'],'done'=>$page['done'],'added'=>$added,'delay'=>60];
        $added = $store->insert($company, $provider, $page['items'], $cursor, function () use ($company,$provider,$revision,$row): void {
            $fresh = $this->vault->get($company, $provider);
            $current = $this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?', [$company,'sync_'.$provider]);
            if (empty($fresh['enabled']) || ($fresh['revision'] ?? null) !== $revision || ($current['version'] ?? null) !== ($row['version'] ?? null)) {
                throw new SyncFailure(409);
            }
        },$checkpoint===null?null:static fn(int $count)=>$checkpoint($outcome($count)));
        return $outcome($added);
    }

    private function window(int $company, string $provider, array $config, array $state, array $cursor): array
    {
        if (isset($cursor['from'])) return $cursor;

        $through = (int)($cursor['through'] ?? time());
        if(($cursor['target']['mode']??null)==='full'){
            $from=TableSyncBoundary::first($this->db,$company,$provider,$config,$state,$through,$cursor['target']['table']);
            return $cursor+['from'=>$from,'windowFrom'=>$from,'revision'=>$config['revision']];
        }
        $covered = $cursor['ordBaseline']['covered'] ?? self::coveredAt($provider,$state);
        if (!$covered) throw new SyncFailure(503,60);

        $created = $this->db->one('SELECT created FROM audit WHERE company_id=? AND action=? ORDER BY id LIMIT 1',
            [$company,CustomerOnboarding::CREATED_AUDIT])['created'] ?? null;
        $first = 0;
        if (IntegrationImportSchema::available($this->db)) {
            $first = self::timestamp($this->db->one('SELECT MIN(started_at) AS started FROM integration_import_runs WHERE company_id=? AND provider=?',
                [$company,$provider])['started'] ?? null);
        }
        $connection = self::timestamp($config['connectedAt'] ?? $config['notifyFrom'] ?? null);
        $from = max($first, $connection, self::timestamp($created), min($covered,$through)-self::overlap($provider),
            self::timestamp($config['startDate'] ?? $state['startDate'] ?? null));
        if ($provider==='trendyol') $from = max($from,$through-89*86400);
        if(isset($cursor['target']))$from=max($from,$cursor['target']['after']??0);
        return ['through'=>$through,'from'=>$from,'windowFrom'=>$from,'revision'=>$config['revision']]+array_intersect_key($cursor,['target'=>true]);
    }

    private static function overlap(string $provider): int
    {
        return match($provider){'koctas','trendyol'=>300,'hepsiburada','pttavm'=>3600,default=>600};
    }

    private static function coveredAt(string $provider,array $state): int
    {
        if($provider==='trendyol'&&isset($state['recentThrough']))return intdiv((int)$state['recentThrough'],1000);
        $recent=(array)($state['recentOrders']??[]);
        return self::timestamp($recent['watermark']??$recent['lastSuccessAt']??$state['watermark']??$state['lastSuccessAt']??null);
    }

    private function checkAvailability(?array $job, array $state): void
    {
        if (!$job || in_array($job['state'], ['blocked','failed'], true)) throw new SyncFailure(503,60);
        $now = time();
        if ($job['state']==='running' && (int)$job['lease_until']>$now) throw new ProviderRequestDeferred((int)$job['lease_until']);
        $nextRequest = max((int)($state['nextRequestAt'] ?? 0), (int)($state['recentOrders']['nextRequestAt'] ?? 0));
        if ((int)$job['attempts']>0) $nextRequest = max($nextRequest,(int)$job['next_run']);
        if ($nextRequest>$now) throw new ProviderRequestDeferred($nextRequest);
    }

    private static function timestamp(mixed $value): int
    {
        if (is_int($value)) return $value;
        return is_string($value) ? (strtotime($value) ?: 0) : 0;
    }
}
