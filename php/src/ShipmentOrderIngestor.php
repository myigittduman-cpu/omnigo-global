<?php
declare(strict_types=1);
namespace Omnigo;

/** Runs in the existing sync job transaction/lock; retrying the page cannot duplicate rows. */
final class ShipmentOrderIngestor
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public static function activate(Database $db,int $company,?int $timestamp=null): int
    {
        $row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='shipment_auto_since'",[$company]);
        if($row)return (int)json_decode($row['payload'],true,8,JSON_THROW_ON_ERROR)['since'];
        if($db->mysql()){
            $type=$db->one("SHOW COLUMNS FROM records LIKE 'kind'")['Type']??'';
            if(!str_contains($type,"'shipment_auto_since'"))throw new SyncFailure(503);
        }
        $timestamp??=time();$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'shipment_auto_since',json_encode(['since'=>$timestamp]),gmdate('c')]);return $timestamp;
    }
    public function ingest(int $company,string $provider,array $items,bool $allowCreate=true,?ShipmentImportChanges $changes=null): int
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Sipariş aktarımı işlem kilidi gerektirir.');
        $activeCities=CustomerCities::read($this->db,$company,true);
        // Menu visibility never disables source ingestion; caller safety flags still apply.
        $since=self::activate($this->db,$company);$added=0;
        foreach($items as $item){
            $payload=ShipmentAddress::payload($item['payload']);unset($payload['fulfillment']);$city=$payload['province'];
            if(!in_array($city,ShipmentRepository::CITIES,true))continue;
            try{$date=new \DateTimeImmutable($payload['orderedAt']?:'invalid',new \DateTimeZone('Europe/Istanbul'));}catch(\Exception){throw new SyncFailure(422);}
            if($date->getTimestamp()>time()+300)continue;
            $date=$date->setTimezone(new \DateTimeZone('Europe/Istanbul'));$month=$date->format('Y-m');$payload['orderedAt']=$date->format('c');
            $source=hash('sha256',$provider.':'.$item['key']);
            $existing=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]);
            if($existing){
                if($existing['removed_at']!==null)continue;
                $stored=$this->cipher->open($company,'shipment:'.$existing['id'],$existing['payload']);$fields=[];
                if(array_key_exists('marketplaceShipping',$payload)&&!array_key_exists('marketplaceRowStatus',$payload)&&array_key_exists('marketplaceRowStatus',$stored)){
                    unset($stored['marketplaceRowStatus']);$fields[]='marketplaceRowStatus';
                }
                $status=ShipmentSourceStatus::next($existing,$stored,$payload,false);
                if($provider==='Pazarama'&&(PazaramaShipmentInvoice::protectedRow($existing,$stored)||($payload['invoiceBasis']??null)!=='buyer_product')){
                    unset($payload['invoiceCents'],$payload['invoiceBasis']);
                }
                $links=array_values(array_unique(array_filter([...($stored['sourceDeliveryIds']??[]),$stored['sourceDeliveryId']??null,$payload['sourceDeliveryId']??null])));sort($links);
                if($links!==($stored['sourceDeliveryIds']??[])){$stored['sourceDeliveryIds']=$links;$fields[]='sourceDeliveryIds';}
                foreach($payload as $field=>$value){
                    if($field==='sourceDeliveryId'&&isset($stored[$field]))continue;
                    // Empty critical identity fields must not remain locked by an old Excel import.
                    $fillMissingIdentity=in_array($field,['recipientName','orderNumber'],true)&&trim((string)($stored[$field]??''))===''&&is_string($value)&&trim($value)!=='';
                    $pendingInvoice=$provider==='Pazarama'&&$field==='invoiceCents'&&($payload['invoiceBasis']??null)==='buyer_product';
                    if((in_array($field,$stored['source']['locked']??[],true)&&!in_array($field,$item['correctionFields']??[],true)&&!$fillMissingIdentity)||$value===''||($value===null&&!$pendingInvoice)||($stored[$field]??null)===$value)continue;
                    $stored[$field]=$value;$fields[]=$field;
                }
                if($fields||$status!==null){
                    if($status!==null){
                        $this->db->run('UPDATE shipment_entries SET row_status=?,shipped=?,cancelled=?,invoiced=? WHERE id=?',[$status,(int)($status==='shipped'),(int)($status==='cancelled'),(int)($status==='invoiced'||!empty($existing['invoiced'])),$existing['id']]);
                        if(in_array($stored['province'],$activeCities,true))$changes?->statusChanged($status,$payload);
                        $fields[]='rowStatus';
                    }
                    $this->db->run('UPDATE shipment_entries SET city=?,month=?,payload=?,ordered_at=?,version=version+1,updated=? WHERE id=?',[$stored['province'],substr($stored['orderedAt'],0,7),$this->cipher->seal($company,'shipment:'.$existing['id'],$stored),ShipmentOrdering::timestamp($stored),gmdate('c'),$existing['id']]);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,NULL,?,?)',[$company,'shipment.auto_update',$existing['id'],json_encode(['fields'=>$fields]),gmdate('c')]);
                }continue;
            }
            if(!in_array($city,$activeCities,true)||(!$allowCreate&&!ShipmentColorPolicy::completedIntake($payload))||($date->getTimestamp()<$since&&empty($item['restoreMissing'])))continue;
            $now=gmdate('c');
            $this->db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,ordered_at,created,updated,source_key) VALUES(?,?,?,?,?,?,?,?,?)',[$company,$city,$provider,$month,'',ShipmentOrdering::timestamp($payload),$now,$now,$source]);
            $id=$this->db->insertId();$payload['source']=['kind'=>'marketplace','provider'=>$provider];$payload['sourceDeliveryIds']=isset($payload['sourceDeliveryId'])?[$payload['sourceDeliveryId']]:[];
            $status=ShipmentSourceStatus::rowStatus($payload)??'';
            if($status!=='')$this->db->run('UPDATE shipment_entries SET row_status=?,shipped=?,cancelled=?,invoiced=? WHERE id=?',[$status,(int)($status==='shipped'),(int)($status==='cancelled'),(int)($status==='invoiced'),$id]);
            $this->db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$this->cipher->seal($company,'shipment:'.$id,$payload),$id]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,NULL,?,?)',[$company,'shipment.auto',$id,json_encode(['provider'=>$provider,'version'=>1]),$now]);
            $changes?->added($status);
            $added++;
        }
        return $added;
    }
}
