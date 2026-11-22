<?php
declare(strict_types=1);
namespace Omnigo;

/** One GET per leased run. Recent updates and historical cursor pages alternate fairly. */
final class TrendyolSyncAdapter implements ScheduledReadAdapter,TransientRetryReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries,private ?TrendyolReadClient $reader=null){}
    public function nextDelay(): int{return $this->delay;}
    public function transientRetryDelay(): int{return 60;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'trendyol');if(empty($config['enabled']))throw new SyncFailure(403);
        $store=new TrendyolOrderStore($this->db,$this->vault,$this->deliveries);$before=$store->state($companyId);$state=$before?json_decode($before['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        $now=time()*1000;$revision=$config['revision'];
        if(($state['revision']??'')!==$revision){
            $start=max($now-89*86400000,(int)(strtotime($config['startDate']??'first day of this month')*1000));
            $state=['revision'=>$revision,'coverageStart'=>$start,'historyEnd'=>$now-3600000,'historyNext'=>$start,'turn'=>'recent','ordersPublished'=>false];
        }
        TrendyolReconciliation::initialize($state,$now);
        $historyPending=$state['historyNext']<$state['historyEnd'];$lane=($state['turn']??'recent')==='history'&&$historyPending?'history':'recent';
        $reader=$this->reader??new TrendyolReadClient();
        if(!$historyPending&&isset($state['auditNext'])&&($state['turn']??'recent')==='history'&&($state['auditRetryAt']??0)<=time()){
            $this->replay($reader,$store,$companyId,$config,$before,$state,$deadline);
            return;
        }
        $scan=$state[$lane.'Scan']??null;
        if(!$scan){
            $from=$lane==='history'?$state['historyNext']:max($state['coverageStart'],($state['recentThrough']??($now-3600000))-300000);
            // A long outage is recovered in bounded windows rather than skipping old updates.
            $to=$lane==='history'?min($from+14*86400000,$state['historyEnd']):min($from+14*86400000,$now);
            $scan=['from'=>$from,'to'=>$to,'cursor'=>null];
        }
        try{
            $result=$reader->orders($config,$scan['from'],$scan['to'],$scan['cursor'],$deadline);
            $state['nextRequestAt']=time()+5;$state['checkedAt']=gmdate('c');$state['lastSuccessAt']=gmdate('c');unset($state['code']);
            if($result['complete']){
                unset($state[$lane.'Scan']);
                if($lane==='history'){$state['historyNext']=$scan['to'];$state['turn']='recent';}
                else{
                    $state['recentThrough']=$scan['to'];$state['turn']='history';
                    $state['recentOrders']=[...($state['recentOrders']??[]),'lastSuccessAt'=>gmdate('c'),'lastPageAt'=>gmdate('c'),'intervalSeconds'=>60];
                    OrderReadCoverage::remember($state['recentOrders'],(int)ceil($scan['from']/1000),intdiv($scan['to'],1000));
                }
            }else{$state[$lane.'Scan']=[...$scan,'cursor'=>$result['cursor']];$state['turn']=$lane==='recent'?'history':'recent';}
            $complete=$state['historyNext']>=$state['historyEnd'];$state['ordersPublished']=true;$state['phase']=$complete?'ready':'syncing';
            if($complete&&isset($state['recentThrough'])){$state['startDate']=gmdate(DATE_ATOM,intdiv($state['coverageStart'],1000));$state['watermark']=gmdate(DATE_ATOM,intdiv($state['recentThrough'],1000));$state['salesPublished']=true;}
            IntegrationImportEvidence::completed($state,$config,$complete&&isset($state['recentThrough']));
            TrendyolReconciliation::observe($state,$result['items']);
            TrendyolReconciliation::publish($state);
            $this->delay=$complete&&!isset($state['recentScan'])?60:5;
            $store->commit($companyId,$config,$before,$state,$result['items']);
        }catch(\Throwable $error){
            $latest=$store->state($companyId);$saved=$latest?json_decode($latest['payload'],true,64,JSON_THROW_ON_ERROR):[];
            $saved['phase']='error';$saved['code']=$error instanceof SyncFailure?$error->httpStatus:0;$saved['checkedAt']=gmdate('c');$saved['nextRequestAt']=time()+max(5,$error instanceof SyncFailure?$error->retryAfter:0);
            $store->commit($companyId,$config,$latest,$saved,[]);throw $error;
        }
    }

    /** Existing installations build their initial reference without re-importing historical orders. */
    private function replay(TrendyolReadClient $reader,TrendyolOrderStore $store,int $company,array $config,?array $before,array $state,int $deadline):void
    {
        $from=$state['auditNext'];$to=min($state['auditEnd'],$from+14*86400000-1);
        $state['turn']='recent';
        try{
            $page=$reader->orders($config,$from,$to,$state['auditCursor']??null,$deadline);
            TrendyolReconciliation::observe($state,$page['items']);
            if($page['complete']){
                unset($state['auditCursor']);
                if($to===$state['auditEnd'])unset($state['auditNext'],$state['auditEnd']);
                else $state['auditNext']=$to+1;
            }else $state['auditCursor']=$page['cursor'];
            unset($state['auditRetryAt'],$state['auditAttempts']);
        }catch(SyncFailure $error){
            $state['auditAttempts']=min(8,1+($state['auditAttempts']??0));
            $state['auditRetryAt']=time()+max(min(1800,15*2**$state['auditAttempts']),$error->retryAfter);
            if($error->httpStatus===429){
                $store->commit($company,$config,$before,$state,[]);
                throw $error;
            }
        }
        $this->delay=5;
        $store->commit($company,$config,$before,$state,[]);
    }
}
