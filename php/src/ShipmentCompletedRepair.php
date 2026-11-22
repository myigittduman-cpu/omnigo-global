<?php
declare(strict_types=1);
namespace Omnigo;

/** Reconcile only stored, explicitly delivered source lines. No remote writes or stock/payment changes. */
final class ShipmentCompletedRepair
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function inspect(int $company,int $from,int $through):array
    {
        if($from<0||$through<$from||$through>time()+300)throw new \InvalidArgumentException('Sevkiyat kontrol tarihleri geçersiz.');
        $plan=['company'=>$company,'from'=>$from,'through'=>$through,'items'=>[],'alreadyCompleted'=>0,'removed'=>0,'manual'=>0,'unverified'=>0,'outside'=>0];
        $cities=CustomerCities::read($this->db,$company);$repository=new DeliveryRepository($this->db,$this->cipher);$manual=[];
        foreach($this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND source_key IS NULL AND removed_at IS NULL',[$company]) as $row)$manual[$row['provider']][]=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
        foreach($this->orders($company) as $row){
            $order=$repository->decode($row);if(!empty($order['operationalExcluded']))continue;$contact=$order['details']??[];
            $stamp=trim((string)($contact['orderedAt']??''))?:trim((string)($order['createdAt']??''));if($stamp==='')continue;
            try{$date=new \DateTimeImmutable($stamp,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){continue;}
            if($date->getTimestamp()<$from||$date->getTimestamp()>$through)continue;
            if(!in_array($row['city'],$cities,true)){$plan['outside']++;continue;}
            foreach($contact['products']??[] as $product){
                $line=(string)($product['lineId']??'');if($line===''){$plan['unverified']++;continue;}
                $identity=$row['provider']==='Pazarama'?$order['externalId']:$order['orderNumber'];
                $key=hash('sha256',json_encode([$identity,$line],JSON_THROW_ON_ERROR));
                $state=$product['shippingState']??$order['sourceShipmentStates'][$key]??null;
                if(!in_array($state,['delivered','completed'],true)){if(!(int)$row['active']&&$state===null)$plan['unverified']++;continue;}
                $quantity=$product['quantity']??0;$cancelled=(int)($product['cancelledQuantity']??$product['cancelQuantity']??0);
                if(!is_int($quantity)||$quantity<1||$cancelled>=$quantity||trim((string)($product['title']??''))===''){$plan['unverified']++;continue;}
                $payload=array_intersect_key($contact,array_flip(['recipientName','recipientEmail','invoiceName','invoiceAddress','customerType','taxNumber','taxOffice']));
                $payload=[...$payload,'orderedAt'=>$date->format(DATE_ATOM),'customerName'=>$contact['customerName']??$order['buyerName']??'','phone'=>$contact['phone']??$order['phone']??'','deliveryAddress'=>$contact['deliveryAddress']??$order['address']??'','province'=>$row['city'],'district'=>$contact['district']??$order['district']??'','orderNumber'=>$order['orderNumber'],'sourceDeliveryId'=>$order['externalId'],'productName'=>$product['title'],'quantity'=>$quantity,'invoiceCents'=>$product['amountCents']??null,'packageStatus'=>'Teslim edildi','marketplaceShipping'=>'delivered','marketplaceCancellation'=>['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>$cancelled>0]];
                if(!ShipmentColorPolicy::completedIntake($payload)){$plan['unverified']++;continue;}
                $source=hash('sha256',$row['provider'].':'.$key);$existing=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]);
                if($existing){
                    if($existing['removed_at']!==null){$plan['removed']++;continue;}
                    $stored=$this->cipher->open($company,'shipment:'.$existing['id'],$existing['payload']);
                    if(ShipmentSourceStatus::isDelivered($stored)&&ShipmentStatus::resolve($existing)==='completed'){$plan['alreadyCompleted']++;continue;}
                }elseif($this->possibleManual($manual[$row['provider']]??[],$payload)){$plan['manual']++;continue;}
                $plan['items'][]=['provider'=>$row['provider'],'key'=>$key,'payload'=>$payload,'existingId'=>$existing?(int)$existing['id']:null];
            }
        }
        return $plan;
    }
    private function possibleManual(array $rows,array $payload):bool
    {
        foreach($rows as $saved){
            if(trim((string)($saved['orderNumber']??''))!==''&&$saved['orderNumber']===$payload['orderNumber'])return true;
            $phone=static fn($value)=>substr(preg_replace('/\D/','',(string)$value),-10);
            if($phone($saved['phone']??'')===''||$phone($saved['phone'])!==$phone($payload['phone']))continue;
            try{if(abs((new \DateTimeImmutable($saved['orderedAt']))->getTimestamp()-(new \DateTimeImmutable($payload['orderedAt']))->getTimestamp())<=900)return true;}catch(\Throwable){}
        }
        return false;
    }
    private function orders(int $company):\Generator
    {
        $after=0;do{$rows=$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND id>? ORDER BY id LIMIT 250',[$company,$after]);foreach($rows as $row){$after=(int)$row['id'];yield $row;}}while(count($rows)===250);
    }
    public function apply(array $actor,int $company,int $from,int $through,callable $backup):array
    {
        if(($actor['role']??'')!=='admin')Http::fail(403,'Bu işlem yalnız Omnigo yetkililerine açıktır.');
        return $this->db->transaction(function()use($actor,$company,$from,$through,$backup){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \OutOfBoundsException('Mağaza bulunamadı.');
            $plan=$this->inspect($company,$from,$through);$result=['added'=>0,'updated'=>0,'rows'=>[],'manual'=>$plan['manual'],'alreadyCompleted'=>$plan['alreadyCompleted'],'unverified'=>$plan['unverified'],'removed'=>$plan['removed'],'outside'=>$plan['outside']];
            foreach($plan['items'] as $item){
                $source=hash('sha256',$item['provider'].':'.$item['key']);$before=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND source_key=?'.$this->db->lock(),[$company,$source]);$backup($company,$before,$item);
                (new ShipmentOrderIngestor($this->db,$this->cipher))->ingest($company,$item['provider'],[['key'=>$item['key'],'payload'=>$item['payload'],'restoreMissing'=>true]]);
                $after=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]);
                if(!$after||ShipmentFulfillment::presentation($after,$this->cipher->open($company,'shipment:'.$after['id'],$after['payload']))['displayRowStatus']!=='completed')throw new \RuntimeException('Tamamlanan siparişin yeşil statüsü doğrulanamadı.');
                $result[$before?'updated':'added']++;$result['rows'][]=['id'=>(int)$after['id'],'version'=>(int)$after['version'],'created'=>$before===null];
            }
            (new CustomerManagement($this->db))->audit($actor,$company,'Tamamlanan sevkiyatlar eşitlendi',$company);return $result;
        });
    }
}
