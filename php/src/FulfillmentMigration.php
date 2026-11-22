<?php
declare(strict_types=1);
namespace Omnigo;

/** Resumable transition: preserve explicit operator decisions; never invent delivery evidence. */
final class FulfillmentMigration
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $company,bool $dryRun=false): array
    {
        $links=[];$cursor=0;
        do{
            $orders=$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND id>? ORDER BY id LIMIT 250',[$company,$cursor]);
            foreach($orders as $row){
                $cursor=(int)$row['id'];$order=(new DeliveryRepository($this->db,$this->cipher))->decode($row);
                if(!empty($order['details']['superseded']))continue;
                foreach($order['details']['products']??[] as $product){$key=DeliveryFulfillment::key($order,$product);if($key!==null)$links[$key][]=$row['external_id'];}
            }
        }while(count($orders)===250);
        $result=['changed'=>0,'review'=>0,'preservedManual'=>0,'linked'=>0];$cursor=0;
        do{
            $batch=$this->db->all('SELECT id FROM shipment_entries WHERE company_id=? AND id>? ORDER BY id LIMIT 100',[$company,$cursor]);
            if(!$batch)break;
            $delta=TransactionRetry::run($this->db,function()use($company,$batch,$links,$dryRun){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $counts=['changed'=>0,'review'=>0,'preservedManual'=>0,'linked'=>0];
                foreach($batch as $item){
                    $id=(int)$item['id'];$row=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$id]);
                    $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
                    if(isset($payload['fulfillment']['version']))continue;
                    $old=ShipmentStatus::resolve($row);$status='';$manual=null;
                    $audit=$this->db->one("SELECT after_value,created,user_id FROM audit WHERE company_id=? AND record_id=? AND user_id IS NOT NULL AND action IN ('shipment.rowStatus','shipment.bulkStatus','shipment.shipped','shipment.cancelled') ORDER BY id DESC LIMIT 1",[$company,$id]);
                    if($audit){$after=json_decode($audit['after_value']??'null',true);$manual=$after['rowStatus']??null;}
                    if(is_string($manual)&&in_array($manual,ShipmentStatus::VALUES,true)){$status=$manual;$counts['preservedManual']++;}
                    elseif(!$row['source_key']){$status=$old;$manual=$old;$counts['preservedManual']++;}
                    elseif(in_array($old,['shipped','delivered','completed'],true)){$status='review';$counts['review']++;}
                    $payload['fulfillment']=['version'=>1,'migratedAt'=>gmdate('c'),'previousStatus'=>$old,'basis'=>$manual!==null?'operator':'unconfirmed'];
                    if(isset($links[$row['source_key']??''])){$payload['sourceDeliveryIds']=array_values(array_unique($links[$row['source_key']]));sort($payload['sourceDeliveryIds']);$payload['sourceDeliveryId']=$payload['sourceDeliveryIds'][0];$counts['linked']++;}
                    $now=gmdate('c');
                    if($dryRun){$counts['changed']++;continue;}
                    $this->db->run('UPDATE shipment_entries SET row_status=?,cancelled=?,shipped=?,payload=?,version=version+1,updated=? WHERE id=? AND company_id=?',[$status,(int)($status==='cancelled'),(int)($status==='shipped'),$this->cipher->seal($company,'shipment:'.$id,$payload),$now,$id,$company]);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',[$company,'shipment.fulfillment_migrated',$id,json_encode(['rowStatus'=>$old]),json_encode(['rowStatus'=>$status,'basis'=>$payload['fulfillment']['basis']]),$now]);
                    $counts['changed']++;
                }
                return $counts;
            });
            foreach($delta as $key=>$value)$result[$key]+=$value;$cursor=(int)end($batch)['id'];
        }while(count($batch)===100);
        return $result;
    }
}
