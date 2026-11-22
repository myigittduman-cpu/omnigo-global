<?php
declare(strict_types=1);

/** Explicit, reviewed legacy corrections. No discovery, remote calls or financial writes. */
final class LegacyShipmentRepair
{
    public function __construct(private \Omnigo\Database $db,private \Omnigo\DeliveryCipher $cipher){}
    public function apply(int $company,string $operation,array $plan): array
    {
        return $this->db->transaction(function()use($company,$operation,$plan){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            foreach($this->db->all("SELECT after_value FROM audit WHERE company_id=? AND action='operation.legacy_repair'",[$company])as $r){
                $saved=json_decode($r['after_value'],true);if(($saved['operation']??null)===$operation)return ['alreadyApplied'=>true,...$saved['result']];
            }
            $before=$this->db->all('SELECT * FROM shipment_entries ORDER BY id');$financial=$this->protectedFingerprint();
            $deliveryBefore=$this->db->all('SELECT * FROM delivery_orders ORDER BY id');$result=['removed'=>[],'filled'=>[],'added'=>[],'deliveryIds'=>[]];
            // Every precondition is checked before any write; any failure rolls back all actions.
            foreach([...$plan['duplicates'],...$plan['products']]as $item)$this->unchanged($company,$item['before']);
            foreach($plan['duplicates']as $item){
                $survivor=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?',[$company,$item['survivorId']]);
                self::require($survivor!==null&&$survivor['removed_at']===null&&$survivor['provider']===$item['before']['provider']&&$survivor['source_key']===$item['sourceKey'],'Korunacak kaynak satır değişti.');
                $old=$this->cipher->open($company,'shipment:'.$item['before']['id'],$item['before']['payload']);
                self::require(empty($old['documents'])&&empty($old['source']['locked']),'Manuel evrak veya alan koruması ayrıca incelenmeli.');
                self::require((int)$item['before']['version']===1&&\Omnigo\ShipmentStatus::resolve($item['before'])==='','Düzenlenmiş manuel kayıt otomatik birleştirilemez.');
            }
            foreach($plan['products']as $item){$p=$this->cipher->open($company,'shipment:'.$item['before']['id'],$item['before']['payload']);self::require($p['productName']===''&&$item['title']!==''&&$item['before']['source_key']===null,'Ürün adı veya kaynak değişti.');self::require(!$this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$item['sourceKey']]),'Ürün kaynağı zaten bağlı.');}
            foreach($plan['missing']as $item)self::require(!$this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$item['sourceKey']]),'Eksik aday artık mevcut.');
            foreach($plan['deliveries']as $d)self::require(!$this->db->one('SELECT id FROM delivery_orders WHERE company_id=? AND provider=? AND external_id=?',[$company,$d['provider'],$d['externalId']]),'Teslimat kaydı artık mevcut.');
            $now=gmdate('c');
            foreach($plan['duplicates']as $item){$id=(int)$item['before']['id'];$this->db->run('UPDATE shipment_entries SET removed_at=?,version=version+1,updated=? WHERE company_id=? AND id=?',[$now,$now,$company,$id]);$this->audit($company,$id,'shipment.legacy_duplicate',['operation'=>$operation,'survivorId'=>$item['survivorId'],'orderNumber'=>$item['orderNumber'],'recoverable'=>true]);$result['removed'][]=$id;}
            foreach($plan['products']as $item){$id=(int)$item['before']['id'];$p=$this->cipher->open($company,'shipment:'.$id,$item['before']['payload']);$p['productName']=$item['title'];
                $p['source']=['kind'=>'marketplace','provider'=>$item['before']['provider'],'locked'=>array_values(array_diff([...\Omnigo\ShipmentRepository::FIELDS,...\Omnigo\ShipmentRepository::OPTIONAL_FIELDS],['productName','packageStatus']))];
                $p['marketplaceCancellation']=$item['cancellation'];$p['marketplaceShipping']=$item['shipping'];$p['packageStatus']='İptal edildi';$p['legacyReconciliation']=['operation'=>$operation,'orderNumber'=>$item['orderNumber'],'lineId'=>$item['lineId'],'originalAmountPreserved'=>true];
                self::require(\Omnigo\ShipmentSourceStatus::value($p)==='cancelled','Kesin iptal doğrulanamadı.');
                $this->db->run("UPDATE shipment_entries SET payload=?,source_key=?,row_status='cancelled',cancelled=1,shipped=0,version=version+1,updated=? WHERE company_id=? AND id=?",[$this->cipher->seal($company,'shipment:'.$id,$p),$item['sourceKey'],$now,$company,$id]);
                $this->audit($company,$id,'shipment.legacy_product',['operation'=>$operation,'fields'=>['productName','source','marketplaceCancellation','marketplaceShipping','packageStatus','rowStatus'],'orderNumber'=>$item['orderNumber'],'lineId'=>$item['lineId']]);$result['filled'][]=$id;
            }
            foreach($plan['missing']as $item){$p=$item['payload'];$date=new \DateTimeImmutable($p['orderedAt'],new \DateTimeZone('Europe/Istanbul'));$p['orderedAt']=$date->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format(DATE_ATOM);
                self::require(\Omnigo\ShipmentSourceStatus::value($p)==='delivered'&&in_array($p['province'],\Omnigo\ShipmentRepository::CITIES,true),'Tarihi teslimat doğrulanamadı.');
                $p['packageStatus']='Teslim edildi';$p['source']=['kind'=>'marketplace','provider'=>$item['provider']];$p['legacyReconciliation']=['operation'=>$operation,'orderNumber'=>$item['orderNumber'],'lineId'=>$item['lineId']];
                $this->db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,ordered_at,created,updated,source_key,row_status) VALUES(?,?,?,?,?,?,?,?,?,'delivered')",[$company,$p['province'],$item['provider'],substr($p['orderedAt'],0,7),'',\Omnigo\ShipmentOrdering::timestamp($p),$now,$now,$item['sourceKey']]);$id=$this->db->insertId();$this->db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$this->cipher->seal($company,'shipment:'.$id,$p),$id]);$this->audit($company,$id,'shipment.legacy_backfill',['operation'=>$operation,'orderNumber'=>$item['orderNumber'],'lineId'=>$item['lineId']]);$result['added'][]=$id;
            }
            foreach($plan['deliveries']as $d){unset($d['shipmentItems']);self::require($d['active']===false,'Geçmiş aktarım aktif sipariş oluşturamaz.');if($d['provider']==='n11')$d['status']='Teslim edildi';
                $lifecycle=!empty($d['cancellation']['cancelledQuantity'])?-1:0;$hash=hash('sha256',json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                $this->db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$d['provider'],$d['externalId'],$d['city'],$lifecycle,$this->cipher->seal($company,'order:'.$d['provider'].':'.$d['externalId'],$d),$hash,$now]);$result['deliveryIds'][]=$this->db->insertId();
            }
            $allowed=array_flip([...$result['removed'],...$result['filled']]);$after=[];foreach($this->db->all('SELECT * FROM shipment_entries ORDER BY id')as $r)$after[$r['id']]=$r;
            foreach($before as $r)if(!isset($allowed[$r['id']]))self::require($r===$after[$r['id']],'Kapsam dışı sevkiyat değişti.');
            $deliveryAfter=[];foreach($this->db->all('SELECT * FROM delivery_orders ORDER BY id')as $r)$deliveryAfter[$r['id']]=$r;foreach($deliveryBefore as $r)self::require($r===$deliveryAfter[$r['id']],'Mevcut teslimat değişti.');
            self::require($financial===$this->protectedFingerprint(),'Finans, senkronizasyon veya bildirim durumu değişti.');
            $result['protectedStateUnchanged']=true;$this->audit($company,0,'operation.legacy_repair',['operation'=>$operation,'result'=>$result]);return ['alreadyApplied'=>false,...$result];
        });
    }
    private function unchanged(int $company,array $before):void
    {
        $current=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$before['id']]);unset($before['decoded']);
        self::require($current!==null&&(int)$before['company_id']===$company&&$current===$before&&$current['removed_at']===null,'İncelenen satır değişti; yeniden inceleme gerekli.');
    }
    private function protectedFingerprint():string
    {
        $data=[];foreach(['records','recent_orders','delivery_documents','order_notifications','push_deliveries']as $table)$data[$table]=$this->db->all('SELECT * FROM '.$table.' ORDER BY id');return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
    private function audit(int $company,int $id,string $action,array $value):void{$this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,NULL,?,?)',[$company,$action,$id,json_encode($value,JSON_THROW_ON_ERROR),gmdate('c')]);}
    private static function require(bool $valid,string $message):void{if(!$valid)throw new \RuntimeException($message);}
}
