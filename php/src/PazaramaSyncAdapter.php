<?php
declare(strict_types=1);
namespace Omnigo;

/** One bounded page per job. Readers keep the last complete snapshot during a scan. */
final class PazaramaSyncAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private ?PazaramaAuthClient $auth=null,private ?PazaramaReadClient $reader=null,private ?DeliveryRepository $deliveries=null) {}
    public function nextDelay(): int {return $this->delay;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'pazarama');
        if(!$config||!($config['enabled']??false))throw new SyncFailure(403);
        $store=new PazaramaSnapshotStore($this->db,$this->vault,$this->deliveries);$row=$store->state($companyId);
        $old=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];$state=$old;
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        $reset=!isset($state['scan'])||($state['scan']['revision']??null)!==$config['revision']||($state['scan']['windowVersion']??0)!==2||($state['scan']['returnVersion']??0)!==1||PazaramaDateWindow::exhausted($state['scan']);
        $zone=new \DateTimeZone('Europe/Istanbul');
        if($reset){
            $from=$state['startDate']??(new \DateTimeImmutable('first day of this month 00:00:00',$zone))->format(DATE_ATOM);
            $now=new \DateTimeImmutable('now',$zone);$end=PazaramaDateWindow::scanEnd($now);
            $state['scan']=['from'=>$from,'end'=>$end,'through'=>$now->format(DATE_ATOM),'revision'=>$config['revision'],'windowVersion'=>2,'returnVersion'=>1,'returnItems'=>[],'cursor'=>(new \DateTimeImmutable($from))->setTimezone($zone)->format('Y-m-d\TH:i'),'page'=>1];
        }
        $scan=$state['scan'];$stage='authentication';
        try{
            $token=($this->auth??new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($config),$deadline);
            PazaramaSellerIdentity::verify($token['accessToken'],$config['sellerId']);$state['sellerVerified']=true;
            $stage='orders';$end=PazaramaDateWindow::end($scan['cursor'],$scan['end']);
            if($end<=$scan['cursor'])throw new SyncFailure(503,60);
            $raw=($this->reader??new PazaramaReadClient())->orders($token['accessToken'],$scan['cursor'],$end,$scan['page'],$deadline);
            $stage='preview';$mapped=array_map([PazaramaSalesMapper::class,'order'],$raw);$page=[];$eligible=[];
            // The API ignores the time portion: freeze the published snapshot at scan.through.
            foreach($mapped as $index=>$order)if(strtotime($order['createdAt'])>=strtotime($scan['from'])&&strtotime($order['createdAt'])<=strtotime($scan['through'])){$page[]=$order;$eligible[]=$raw[$index];}
            // Reject changing/repeated pages rather than publishing an incomplete moving dataset.
            $complete=false;$scan['page']++;
            if(count($raw)<20){$scan['page']=1;$scan['cursor']=PazaramaDateWindow::next($end);$complete=$end===$scan['end'];}
            PazaramaReturnProjection::append($scan,$eligible);
            $state['scan']=$scan;$state['phase']='syncing';unset($state['failure']);
            $deliveryItems=$this->deliveries?array_map(static function($raw){$delivery=DeliveryMapper::pazarama($raw);return [...$delivery,'details'=>OrderDetailMapper::pazarama($raw,$delivery),'shipmentItems'=>ShipmentOrderMapper::pazarama($raw,$delivery)];},$eligible):[];
            $store->commit($companyId,$row,$config,$state,$page,$reset,$complete,$deliveryItems);
            $this->delay=$complete?900:60;
        }catch(\Throwable $error){
            if($error instanceof SyncFailure&&$error->httpStatus===422)unset($old['scan']);
            $old['failure']=PazaramaDiagnostics::failure($stage,$error);
            $store->commit($companyId,$row,$config,$old,[],false,false);
            throw $error;
        }finally{unset($token);}
    }
}
