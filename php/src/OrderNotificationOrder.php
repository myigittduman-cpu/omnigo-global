<?php
declare(strict_types=1);
namespace Omnigo;

/** Resolves notification references inside the company/provider scope; only cancellation presentations include a scoped buyer name. */
final class OrderNotificationOrder
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null){}
    public static function reference(array $order):array
    {
        $id=(string)($order['externalId']??$order['orderNumber']??'');
        if(in_array($order['provider']??'',['n11','idefix','Hepsiburada'],true))$id=explode(':',$id,2)[0];
        return $id!==''?['orderRef'=>hash('sha256',$id)]:[];
    }
    private function snapshot(array $event):?array
    {
        $provider=$event['provider']??'';
        if(!isset(OrderList::PROVIDERS[$provider]))return null;
        $payload=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        $key=$payload['orderRef']??$event['order_key']??'';
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))return null;
        $company=(int)$event['company_id'];
        // Older Pazarama events hash the visible number, while order snapshots use its UUID.
        // New events already carry the hashed UUID; decrypt only this legacy lookup on demand.
        if($provider==='pazarama'&&!isset($payload['orderRef']))$key=$this->legacyPazaramaReference($company,$key)??$key;
        return $this->snapshots($company,$provider,[$key])[$key]??null;
    }
    public function snapshots(int $company,string $provider,array $refs): array
    {
        $missing=array_fill_keys($refs,true);$matched=[];
        $sources=[];
        if(RecentOrderSchema::available($this->db))$sources[]=['recent_orders','company_id=? AND provider=?',[$company,$provider],'external_id',true];
        $sources[]=['records','company_id=? AND kind=?',[$company,'order_'.$provider],$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))":"json_extract(payload,'$.externalId')",false];
        foreach($sources as [$table,$where,$args,$external,$recent]){
            if(!$missing)break;
            if($this->db->mysql()){
                $params=implode(',',array_fill(0,count($missing),'?'));
                $where.=" AND SHA2($external,256) IN ($params)";$args=[...$args,...array_keys($missing)];
            }
            $query=$this->db->run("SELECT id,payload FROM $table WHERE $where",$args);
            while($row=$query->fetch()){
                $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                $key=hash('sha256',(string)($value['externalId']??''));
                if(!isset($missing[$key]))continue;
                $matched[$key]=['id'=>($recent?-1:1)*(int)$row['id'],'amountCents'=>$value['grossCents']??null];
                unset($missing[$key]);
            }
        }
        return $matched;
    }
    private function legacyPazaramaReference(int $company,string $key):?string
    {
        $exists=$this->db->mysql()?$this->db->one("SHOW TABLES LIKE 'delivery_orders'"):$this->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='delivery_orders'");
        if(!$exists)return null;
        $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');$after=0;
        do{
            $rows=$this->db->all("SELECT id,external_id,payload FROM delivery_orders WHERE company_id=? AND provider='Pazarama' AND id>? ORDER BY id LIMIT 100",[$company,$after]);
            foreach($rows as $row){
                $after=(int)$row['id'];$value=$cipher->open($company,'order:Pazarama:'.$row['external_id'],$row['payload']);
                if(hash('sha256',(string)($value['orderNumber']??''))===$key)return hash('sha256',$row['external_id']);
            }
        }while(count($rows)===100);
        return null;
    }
    public function enrich(array $event):array
    {
        if(($event['event_type']??'order')==='operation'){
            if($this->cipher===null)return $event;
            $detail=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
            if(in_array('cancelled',$detail['events']??[],true)&&isset($detail['auditId'])){
                $company=(int)$event['company_id'];
                $audit=$this->db->one("SELECT record_id,after_value FROM audit WHERE id=? AND company_id=? AND action='operations.mail'",[(int)$detail['auditId'],$company]);
                if($audit){
                    $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
                    $private=$cipher->open($company,'operation-mail:'.$detail['auditId'],$audit['after_value']);
                    $private=DeliveryNotificationName::enrich($this->db,$cipher,$company,(int)$audit['record_id'],$private);
                    $detail['buyerName']=$private['buyerName']??'';
                    $event['payload']=json_encode($detail,JSON_THROW_ON_ERROR);
                }
            }
            return $event;
        }
        if(($event['event_type']??'order')!=='order')return $event;
        $order=$this->snapshot($event);
        return [...$event,'amountCents'=>$order['amountCents']??null];
    }
    public function detail(array $user,int $company,int $id):array
    {
        (new CustomerPermissions($this->db,$company))->require($user,'orders');
        $event=$this->db->one("SELECT * FROM order_notifications WHERE id=? AND company_id=? AND event_type='order'",[$id,$company]);
        if(!$event)Http::fail(404,'Sipariş bildirimi bulunamadı.');
        $order=$this->snapshot($event);
        if(!$order)Http::fail(404,'Sipariş ayrıntıları henüz aktarılmadı. Biraz sonra tekrar deneyin.');
        return (new OrderDetails($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->detail($user,$company,$order['id']);
    }
}
