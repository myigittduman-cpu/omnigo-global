<?php
declare(strict_types=1);
namespace Omnigo;

/** Commission reads have their own cron and checkpoints; order jobs do not depend on them. */
final class ProfitabilityCommissionSync
{
    private const LOOKBACK_DAYS = 31;
    private const FORMAT_VERSION = 2;

    public function __construct(private Database $db,private IntegrationVault $vault) {}

    public function run(int $deadline): void
    {
        $activation=ProfitabilitySchema::startedAt($this->db);
        if ($activation===null||!PazaramaCommissionStore::available($this->db)) return;
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul'));
        $since=max($activation,$today->modify('-'.(self::LOOKBACK_DAYS-1).' days')->getTimestamp());
        $scopes=$this->db->all('SELECT DISTINCT company_id,provider FROM profitability_orders WHERE ordered_at>=? ORDER BY company_id,provider',[$since]);
        // Rotate the first store/provider; a busy shop cannot starve later stores.
        if($scopes){$offset=intdiv(time(),60)%count($scopes);$scopes=[...array_slice($scopes,$offset),...array_slice($scopes,0,$offset)];}
        foreach ($scopes as $scope) {
            if (time()+5>=$deadline) break;
            $provider=$scope['provider'];$company=(int)$scope['company_id'];
            if (!isset(ProfitabilityCommissionSource::PROVIDERS[$provider])) continue;
            try { $this->scan($company,$provider,$today,$since,min($deadline,time()+12)); }
            catch (\Throwable $error) { error_log('Omnigo sipariş komisyonu '.$provider.': '.get_class($error)); }
        }
    }

    private function scan(int $company,string $provider,\DateTimeImmutable $today,int $since,int $deadline): void
    {
        $connections=new UpcomingPayoutStore($this->db);
        $connection=$connections->connection($company,$provider);
        $settings=$this->vault->get($company,$provider);
        if (!$connection||empty($settings['enabled'])) return;
        $store=new FinanceStore($this->db);
        $keyPrefix=self::key($provider);
        $hold=$store->state($company,$keyPrefix.'retry');
        $retry=json_decode($hold['payload']??'{}',true,16,JSON_THROW_ON_ERROR);
        if (($retry['connection']??null)===$connection&&($retry['until']??0)>time()) return;

        for ($age=0;$age<self::LOOKBACK_DAYS&&time()+3<$deadline;$age++) {
            $day=$today->modify('-'.$age.' days');
            $start=max($day->getTimestamp(),$since);
            $end=$day->modify('+1 day')->getTimestamp();
            if ($start>=$end) break;
            $key=$keyPrefix.$day->format('Y-m-d');
            $before=$store->state($company,$key);
            $state=json_decode($before['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
            if (($state['connection']??null)!==$connection||($state['formatVersion']??null)!==self::FORMAT_VERSION)
                $state=['connection'=>$connection,'rows'=>[],'formatVersion'=>self::FORMAT_VERSION];
            if (($state['nextAt']??0)>time()) continue;
            $limit=match($provider) {'hepsiburada','pttavm','pazarama'=>1,'koctas'=>25,default=>100};
            $args=[$company,$provider,$start,$end];
            $where='company_id=? AND provider=? AND ordered_at>=? AND ordered_at<?';
            if (isset($state['cursor'])) { $where.=' AND external_id>?'; $args[]=$state['cursor']; }
            $orders=$this->db->all('SELECT external_id,order_number,ordered_at FROM profitability_orders WHERE '.$where.
                ' ORDER BY external_id LIMIT '.($limit+1),$args);
            if (!$orders) continue;
            $more=count($orders)>$limit;
            if ($more) array_pop($orders);
            try {
                if(in_array($provider,['pazarama','trendyol'],true)){
                    $local=(new ProfitabilityOrderSales($this->db))->read($company,array_map(fn($row)=>[...$row,'provider'=>$provider],$orders));
                    foreach($orders as &$row)$row['sourceOrder']=$local[$provider][$row['external_id']]??null;
                    unset($row);
                }
                $checkpoint=$state['settlementPage']??null;
                $observed=(new ProviderRequestGate($this->db))->run($company,$provider,$deadline,false,
                    static function(int $usable) use ($provider,$settings,$orders,$day,&$checkpoint): array {
                        return (new ProfitabilityCommissionSource())->read($provider,$settings,$orders,$day,$usable,$checkpoint);
                    });
            }
            catch (ProviderRequestDeferred) { return; }
            catch (SyncFailure $error) {
                $delay=max($error->retryAfter,in_array($error->httpStatus,[401,403],true)?1800:120);
                $this->save($store,$connections,$company,$provider,$keyPrefix.'retry',$connection,$hold,
                    ['connection'=>$connection,'until'=>time()+$delay,'status'=>$error->httpStatus]);
                return;
            }
            if($checkpoint!==null){
                // Fresh order evidence can be shown while its optional accounting slot is queued.
                if($provider==='hepsiburada')foreach($orders as $order){
                    $id=$order['external_id'];
                    $proof=$observed[$order['order_number']]??null;
                    if($proof!==null&&$proof['orderedAt']===(int)$order['ordered_at']
                        && (!isset($state['rows'][$id]['statementItems'])
                            ||($state['rows'][$id]['pricingFingerprint']??null)!==($proof['pricingFingerprint']??null))){
                        $state['rows'][$id]=$proof;
                    }
                }
                $state['settlementPage']=$checkpoint;
                $state['nextAt']=0;
                $this->save($store,$connections,$company,$provider,$key,$connection,$before,$state);
                return;
            }
            unset($state['settlementPage']);
            foreach ($orders as $order) {
                $id=$order['external_id'];
                $evidence=$observed[$order['order_number']]??null;
                if ($evidence!==null&&$evidence['orderedAt']===(int)$order['ordered_at']) $state['rows'][$id]=$evidence;
                else unset($state['rows'][$id]);
            }
            if ($more) $state['cursor']=$orders[array_key_last($orders)]['external_id'];
            else unset($state['cursor']);
            $state['nextAt']=$more?0:time()+($age===0?900:($age<=2?3600:21600));
            $this->save($store,$connections,$company,$provider,$key,$connection,$before,$state);
            return;
        }
    }

    public static function observations(Database $db,int $company,array $orders): array
    {
        if (!PazaramaCommissionStore::available($db)) return [];
        $store=new FinanceStore($db);$connections=new UpcomingPayoutStore($db);
        $cache=[];$versions=[];$result=[];
        foreach ($orders as $order) {
            $provider=$order['provider'];
            if (!isset(ProfitabilityCommissionSource::PROVIDERS[$provider])) continue;
            $key=self::key($provider).ProfitabilityCommission::day((int)$order['ordered_at']);
            if (!array_key_exists($provider,$versions)) $versions[$provider]=$connections->connection($company,$provider);
            if (!array_key_exists($key,$cache)) {
                $snapshot=json_decode($store->state($company,$key)['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
                $cache[$key]=($snapshot['formatVersion']??null)===self::FORMAT_VERSION&&$versions[$provider]
                    &&$versions[$provider]===($snapshot['connection']??null)?($snapshot['rows']??[]):[];
            }
            $id=$order['external_id'];
            if (isset($cache[$key][$id])) $result[$provider][$id]=$cache[$key][$id];
        }
        return $result;
    }

    private static function key(string $provider): string
    {
        return 'pc:'.ProfitabilityCommissionSource::PROVIDERS[$provider].':';
    }

    private function save(FinanceStore $store,UpcomingPayoutStore $connections,int $company,string $provider,string $key,
        string $connection,?array $before,array $state): void
    {
        TransactionRetry::run($this->db,function () use ($store,$connections,$company,$provider,$key,$connection,$before,$state): void {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $fresh=$store->state($company,$key);
            if (($fresh['version']??null)!==($before['version']??null)||$connections->connection($company,$provider)!==$connection) {
                throw new SyncFailure(409);
            }
            $store->writeState($company,$key,$state,$fresh);
        });
    }
}
