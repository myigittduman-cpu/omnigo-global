<?php
declare(strict_types=1);
namespace Omnigo;

/** One page per leased job; retries retain the last complete visible snapshot. */
final class IdefixSyncAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries,private ?MarketplaceReadClient $reader=null,private ?IdefixReturnClient $returns=null){}
    public function nextDelay(): int{return $this->delay;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'idefix');if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $store=new IdefixOrderStore($this->db,$this->vault,$this->deliveries);$row=$store->state($companyId);$before=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];$state=$before;
        $zone=new \DateTimeZone('Europe/Istanbul');$reset=!isset($state['scan'])||($state['scan']['revision']??'')!==$config['revision']||($state['scan']['refundVersion']??0)!==1;
        if($reset){$from=$state['startDate']??(new \DateTimeImmutable('first day of this month 00:00:00',$zone))->format(DATE_ATOM);$end=(new \DateTimeImmutable('now',$zone))->format(DATE_ATOM);$state['scan']=['revision'=>$config['revision'],'refundVersion'=>1,'from'=>$from,'cursor'=>$from,'end'=>$end,'page'=>1];}
        $scan=$state['scan'];$start=new \DateTimeImmutable($scan['cursor']);$end=min((new \DateTimeImmutable($scan['end']))->getTimestamp(),$start->modify('+14 days')->getTimestamp());
        try{
            if(($scan['stream']??'orders')==='returns'){
                $data=($this->returns??new IdefixReturnClient())->page($config,$scan['from'],$scan['end'],$scan['page'],$deadline);
                if(isset($scan['claimTotal'])&&($scan['claimTotal']!==$data['total']||$scan['claimPages']!==$data['pages']))throw new SyncFailure(422);
                $scan['claimTotal']=$data['total'];$scan['claimPages']=$data['pages'];$scan['claimCount']=($scan['claimCount']??0)+$data['count'];
                if($data['complete']&&$scan['claimCount']!==$data['total'])throw new SyncFailure(422);
                if(!$data['complete']&&$scan['page']>=1000)throw new SyncFailure(422);
                $scan['page']++;$state['scan']=$scan;$state['phase']='syncing';unset($state['code']);
                $store->commit($companyId,$row,$config,$state,[],false,$data['complete'],$data['claims'],$data['requests']??null);$this->delay=$data['complete']?900:60;return;
            }
            $data=($this->reader??new MarketplaceReadClient())->orders('idefix',$config,$start->setTimezone($zone)->format('Y-m-d H:i:s'),(new \DateTimeImmutable('@'.$end))->setTimezone($zone)->format('Y-m-d H:i:s'),$scan['page'],$deadline);
            $mapped=array_map([IdefixOrderMapper::class,'map'],$data['items']);$complete=false;
            if($data['complete']){if($end===strtotime($scan['end']))$complete=true;else{$scan['cursor']=(new \DateTimeImmutable('@'.($end+1)))->setTimezone($zone)->format(DATE_ATOM);$scan['page']=1;}}
            else {if($scan['page']>=1000)throw new SyncFailure(422);$scan['page']++;}
            if($complete){$scan['stream']='returns';$scan['page']=1;}
            $state['scan']=$scan;$state['phase']='syncing';unset($state['code']);$store->commit($companyId,$row,$config,$state,$mapped,$reset,false);
        }catch(\Throwable $error){
            $before['phase']='error';$before['code']=$error instanceof SyncFailure?$error->httpStatus:0;if(($before['code']??0)===422)unset($before['scan']);
            $store->commit($companyId,$row,$config,$before,[],false,false);throw $error;
        }
    }
}
