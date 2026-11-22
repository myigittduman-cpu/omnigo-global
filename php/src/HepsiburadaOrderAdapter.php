<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaOrderAdapter implements FixedCadenceReadAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries,private ?HepsiburadaReadClient $reader=null){}
    public function nextDelay():int{return 60;}
    public function syncReadOnly(int $companyId,int $deadline):void
    {
        $config=$this->vault->get($companyId,'hepsiburada');
        if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        if(empty($config['ordersEnabled'])){(new HepsiburadaProbeAdapter($this->db,$this->vault,$this->reader))->syncReadOnly($companyId,$deadline);return;}
        if(!RecentOrderSchema::available($this->db))throw new SyncFailure(503);
        $before=$this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$companyId]);
        $state=$before?json_decode($before['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        $reader=$this->reader??new HepsiburadaReadClient();$credentials=HepsiburadaCredentials::fromArray($config);$orders=[];$started=time();
        $labels=$this->deliveries->hepsiburadaShippingLabels($companyId,$credentials->merchantId,$state);
        // Persistent per-feed cursors: an interrupted initial import resumes instead of restarting.
        $feeds=HepsiburadaOrderDiscovery::FEEDS;$position=(int)($state['feedPosition']??0);$completed=[];$requests=0;
        while(count($completed)<count($feeds)&&$requests<20){
            if(time()>=$deadline-12)break;
            $feed=$feeds[$position];
            $position=($position+1)%count($feeds);$state['feedPosition']=$position;
            if(isset($completed[$feed]))continue;
            $offset=(int)($state['offsets'][$feed]??0);
            $listStarted=time();
            $page=$reader->page($credentials,$feed,$offset,$deadline);
            $state=HepsiburadaOrderDiscovery::remember($state,$feed,$offset,$page,$credentials->merchantId);
            if(in_array($feed,['packages','shipped'],true))$labels->rememberFeed($page['items'],$feed==='packages');
            HepsiburadaReconciliationSource::discover($state,$feed,$offset,$page,$credentials->merchantId);
            if($offset===0)$state['orderReadFeedStarted'][$feed]=$listStarted;
            if(!$page['hasMore']&&isset($state['orderReadFeedStarted'][$feed])){
                $state['orderReadFeedCoverage'][$feed]=$state['orderReadFeedStarted'][$feed];
            }
            $requests++;if(!$page['hasMore'])$completed[$feed]=true;
        }
        // New discoveries go first; at most five isolated IDs share this worker budget.
        $retries=0;
        foreach((new OrderImportQueue($this->db))->pending($companyId,'hepsiburada') as $retry){
            if($retries>=5)break;
            if($retry['nextAttemptAt']<=time()){$state['pendingOrders'][$retry['externalId']]=true;$retries++;}
        }
        HepsiburadaPackageShipping::schedule($this->db,$companyId,$state);
        $attempts=0;
        foreach(array_keys($state['pendingOrders']??[]) as $number){
            if($attempts>=20||time()>=$deadline-8)break;
            $attempts++;
            $number=(string)$number;
            $mapped=HepsiburadaClaimProjection::apply(HepsiburadaPackageShipping::map($reader->order($credentials,$number,$deadline),$reader,$credentials,$deadline,$state,$labels),$state['fulfillmentClaims']??[]);
            $orders[]=$mapped;HepsiburadaReconciliationSource::capture($state,$mapped);
            unset($state['pendingOrders'][$number]);
        }
        // Existing IDs only seed discovery. Expected amounts always come from a fresh API detail.
        if(empty($state['reconciliationSource']['seeded'])){
            foreach($this->db->all("SELECT external_id FROM recent_orders WHERE company_id=? AND provider='hepsiburada'",[$companyId]) as $row){
                if(!isset($state['reconciliationSource']['orders'][ReconciliationReference::key($row['external_id'])]))$state['reconciliationSource']['pending'][$row['external_id']]=true;
            }
            $state['reconciliationSource']['seeded']=true;
        }
        HepsiburadaReconciliationSource::backfill($state,$reader,$credentials,$deadline,max(0,20-$attempts));
        $labels->save($state);
        unset($state['failure'],$state['preview']);
        $complete=count($state['feedCompletedAt']??[])===count(HepsiburadaOrderDiscovery::FEEDS)&&empty($state['pendingOrders']);
        $state['phase']=$complete?'live':'syncing';$state['ordersPublished']=true;$state['sampleOnly']=false;
        $state['salesPublished']??=false;$state['refundsVerified']??=false;
        $state['checkedAt']=gmdate('c');$state['nextRequestAt']=$started+60;
        if($orders)$state['deliveryLastPageAt']=gmdate('c');
        if($complete){$state['lastSuccessAt']=min($state['feedCompletedAt']);$state['watermark']=$state['lastSuccessAt'];}
        $state['recentOrders']=['lastSuccessAt'=>$state['lastSuccessAt']??null,...($complete?[]:['scan'=>true])];
        if($complete&&count($state['orderReadFeedCoverage']??[])===count($feeds)){
            $through=min($state['orderReadFeedCoverage']);
            $from=max($state['orderReadFeedCoverage'])-3600;
            OrderReadCoverage::remember($state,$from,$through);
        }
        IntegrationImportEvidence::completed($state,$config,$complete);
        (new HepsiburadaOrderStore($this->db,$this->vault,$this->deliveries))->commit($companyId,$config,$before,$state,$orders);
    }
}
