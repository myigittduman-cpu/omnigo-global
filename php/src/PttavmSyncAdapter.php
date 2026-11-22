<?php
declare(strict_types=1);
namespace Omnigo;

/** Leased queue: recent orders first, bounded historical pages within the worker budget. GET only. */
final class PttavmSyncAdapter implements ScheduledReadAdapter,TransientRetryReadAdapter
{
    private const MAX_HISTORY_PAGES=8;
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries,private ?MarketplaceReadClient $reader=null,private ?\Closure $pause=null){}
    public function nextDelay(): int{return 60;}
    public function transientRetryDelay(): int{return 60;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,'pttavm');if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $store=new PttavmOrderStore($this->db,$this->vault,$this->deliveries);
        $row=$store->state($companyId);$state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        $zone=new \DateTimeZone('Europe/Istanbul');$now=new \DateTimeImmutable('now',$zone);
        $recentFrom=$now->modify('-7 days');$reader=$this->reader??new MarketplaceReadClient();
        $inventory=new InventoryOrderIdentity($this->db);
        $catalogCode=fn(string $id)=>$inventory->catalogCode($companyId,$id);
        try{
            $data=$reader->orders('pttavm',$config,$recentFrom->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s'),1,$deadline);
            $mapped=PttavmOrderMapper::batch($data['items'],$config['storeName']??null,$catalogCode);
            $state['recentOrders']=[...($state['recentOrders']??[]),'lastSuccessAt'=>gmdate('c'),'lastPageAt'=>gmdate('c'),'intervalSeconds'=>60];
            OrderReadCoverage::remember($state['recentOrders'],$recentFrom->getTimestamp(),$now->getTimestamp());
            $state['nextRequestAt']=time()+60;$state['checkedAt']=gmdate('c');$state['lastSuccessAt']=gmdate('c');
            $state['ordersPublished']=true;$state['sampleOnly']=false;$state['salesPublished']=false;
            $state['phase']=empty($state['startDate'])?'syncing':'ready';unset($state['code']);
            $store->commit($companyId,$config,$row,$state,$mapped);
            // Detail failures must not delay live orders/cancellations or fail their successful sync.
            if($deadline-time()>18){
                try{(new PttavmPricingWorker($this->db,$this->vault,$reader))->run($companyId,$config,min(time()+12,$deadline-15));}
                catch(\Throwable $error){error_log('PTT tutar dökümü: '.get_class($error));}
            }
            for($page=0;$page<self::MAX_HISTORY_PAGES;$page++){
                if($deadline-time()<15)return;
                if($page>0){if($this->pause)($this->pause)(1);else sleep(1);}
                if($deadline-time()<15)return;
                if($this->historyPage($companyId,$config,$now,$reader,$store,$deadline,$catalogCode))return;
            }
        }catch(\Throwable $error){
            // Preserve the last successful orders and cursor. The lease queue handles Retry-After.
            $row=$store->state($companyId);$state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
            $state['phase']='error';$state['code']=$error instanceof SyncFailure?$error->httpStatus:0;$state['checkedAt']=gmdate('c');
            $store->commit($companyId,$config,$row,$state,[]);throw $error;
        }
    }
    private function historyPage(int $companyId,array $config,\DateTimeImmutable $now,MarketplaceReadClient $reader,PttavmOrderStore $store,int $deadline,callable $catalogCode): bool
    {
            $zone=$now->getTimezone();
            $row=$store->state($companyId);$state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            $scan=$state['scan']??null;
            if(!$scan||($scan['revision']??'')!==$config['revision'])$scan=['revision'=>$config['revision'],'from'=>$config['startDate']??$now->modify('first day of last month')->setTime(0,0)->format(DATE_ATOM),'end'=>$now->format(DATE_ATOM)];
            if(isset($scan['cursor'])&&!isset($state['reconciliationDraft']))unset($scan['cursor']);
            $cursor=new \DateTimeImmutable($scan['cursor']??$scan['from']);$end=min($cursor->modify('+30 days'),new \DateTimeImmutable($scan['end']));
            if($cursor>$end)throw new SyncFailure(422);
            $data=$reader->orders('pttavm',$config,$cursor->setTimezone($zone)->format('Y-m-d H:i:s'),$end->setTimezone($zone)->format('Y-m-d H:i:s'),1,$deadline);
            $mapped=PttavmOrderMapper::batch($data['items'],$config['storeName']??null,$catalogCode);
            ReconciliationReference::incremental($state,array_column($mapped,'order'),array_column($mapped,'delivery'),
                true,!isset($scan['cursor']),$end->getTimestamp()===strtotime($scan['end']),$scan['from'],$scan['end']);
            if($end->getTimestamp()===strtotime($scan['end'])){
                $state['startDate']=$scan['from'];$state['watermark']=$scan['end'];$state['phase']='ready';$state['historyLastSuccessAt']=gmdate('c');unset($state['scan']);
                IntegrationImportEvidence::completed($state,$config,true);
            }else{$scan['cursor']=$end->format(DATE_ATOM);$state['scan']=$scan;}
            $state['checkedAt']=gmdate('c');$store->commit($companyId,$config,$row,$state,$mapped);
            return !isset($state['scan']);
    }
}
