<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable bounded pages; publish only after the entire sales and return scan succeeds. */
final class N11SyncAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private ?N11ReadClient $reader=null,private ?N11ReturnClient $returns=null,private ?DeliveryRepository $deliveries=null) {}
    public function nextDelay(): int {return $this->delay;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'n11');
        if (!$config||!($config['enabled']??false)) throw new SyncFailure(403);
        $store=new N11SnapshotStore($this->db,$this->vault,$this->deliveries);$row=$store->state($companyId);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        if (($state['nextRequestAt']??0)>time()) throw new SyncFailure(429,$state['nextRequestAt']-time());
        $reset=!isset($state['scan'])||($state['scan']['revision']??null)!==$config['revision'];
        if ($reset) {
            $zone=new \DateTimeZone('Europe/Istanbul');
            $from=$state['startDate']??(new \DateTimeImmutable('first day of this month 00:00:00',$zone))->format(DATE_ATOM);
            $through=(new \DateTimeImmutable('now',$zone))->format(DATE_ATOM);
            $state['scan']=['from'=>$from,'through'=>$through,'revision'=>$config['revision'],'type'=>'orders','cursor'=>strtotime($from)*1000,'page'=>0];
        }
        $scan=$state['scan'];$credentials=N11Credentials::fromArray($config);$page=[];$complete=false;$deliveryItems=[];
        try {
            if ($scan['type']==='orders') {
                $end=min(strtotime($scan['through'])*1000,$scan['cursor']+14*86400000-1);
                if ($end<=$scan['cursor']) throw new SyncFailure(503,60);
                $data=($this->reader??new N11ReadClient())->packages($credentials,$scan['cursor'],$end,$scan['page'],$deadline);
                if (!$data['content']&&$scan['page']+1<$data['totalPages']) throw new SyncFailure(422);
                $mapped=array_map([N11SalesMapper::class,'package'],$data['content']);
                if($this->deliveries)$deliveryItems=array_map(static function($raw){$delivery=DeliveryMapper::n11($raw);return [...$delivery,'details'=>OrderDetailMapper::n11($raw,$delivery),'shipmentItems'=>ShipmentOrderMapper::n11($raw,$delivery)];},$data['content']);
                foreach ($mapped as $package) {
                    $expected=$state['sellerId']??$state['preview']['sellerId']??null;
                    if ($expected!==null&&$expected!==$package['sellerId']) throw new SyncFailure(422);
                    $state['sellerId']=$package['sellerId'];
                }
                $page=['type'=>'orders','items'=>$mapped];$scan['page']++;
                if ($scan['page']>=max(1,$data['totalPages'])) {
                    $scan['page']=0;$scan['cursor']=$end+1;
                    if ($end===strtotime($scan['through'])*1000) {$scan['type']='returns';$scan['cursor']=substr($scan['from'],0,10);}
                }
            } else {
                $zone=new \DateTimeZone('Europe/Istanbul');$from=new \DateTimeImmutable($scan['cursor'],$zone);
                $to=min($from->modify('+30 days')->format('Y-m-d'),substr($scan['through'],0,10));
                $data=($this->returns??new N11ReturnClient())->claims($credentials,$scan['cursor'],$to,$scan['page'],$deadline);
                $page=['type'=>'returns','items'=>$data['claims']];$scan['page']++;
                if ($scan['page']>=max(1,$data['pageCount'])) {
                    $scan['page']=0;$scan['cursor']=(new \DateTimeImmutable($to,$zone))->modify('+1 day')->format('Y-m-d');
                    $complete=$to===substr($scan['through'],0,10);
                }
            }
            $state['scan']=$scan;$state['phase']='syncing';unset($state['failure']);
            $store->commit($companyId,$row,$config,$state,$page,$reset,$complete,$deliveryItems);
            $this->delay=$complete?900:60;
        } catch (\Throwable $error) {
            // Store a safe diagnostic without advancing the cursor or replacing valid sales.
            $old=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            $old['failure']=N11Diagnostics::failure($error);
            $store->commit($companyId,$row,$config,$old,[],false,false);
            throw $error;
        }
    }
}
