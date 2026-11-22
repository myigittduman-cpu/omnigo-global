<?php
declare(strict_types=1);
namespace Omnigo;

/** Administrative reconciliation of already imported order details; no marketplace writes. */
final class ShipmentTransferRepair
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function restoreActivation(int $company): int
    {
        $earliest=null;
        foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind=''",[$company]) as $row){
            $value=json_decode($row['payload'],true);if(!is_array($value)||array_keys($value)!==['since']||!is_int($value['since']))continue;
            $earliest=$earliest===null?$value['since']:min($earliest,$value['since']);
        }
        $since=ShipmentOrderIngestor::activate($this->db,$company,$earliest);
        if($earliest!==null&&$earliest<$since){$since=$earliest;$this->db->run("UPDATE records SET payload=?,version=version+1,updated=? WHERE company_id=? AND kind='shipment_auto_since'",[json_encode(['since'=>$since]),gmdate('c'),$company]);}
        return $since;
    }
    public function inspect(int $company,string $month): array
    {
        $repo=new DeliveryRepository($this->db,$this->cipher);$plan=['items'=>[],'existing'=>0,'manual'=>0,'ambiguous'=>0,'outside'=>0];
        $manual=[];foreach($this->db->all('SELECT id,provider,payload FROM shipment_entries WHERE company_id=? AND month=? AND source_key IS NULL',[$company,$month]) as $row)$manual[$row['provider']][]=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
        foreach($this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND active=1',[$company]) as $row){
            $order=$repo->decode($row);$contact=$order['details']??[];
            if(!in_array($order['city'],CustomerCities::read($this->db,$company,true),true)){$plan['outside']++;continue;}
            if(!$contact||empty($contact['orderedAt']))continue;
            $date=new \DateTimeImmutable($contact['orderedAt'],new \DateTimeZone('Europe/Istanbul'));
            if($date->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m')!==$month)continue;
            foreach($contact['products']??[] as $product){
                if(empty($product['lineId'])||($product['quantity']??0)<1||in_array(strtolower((string)($product['status']??'')),['cancelled','canceled','unsupplied','refused'],true))continue;
                $identity=$order['provider']==='Pazarama'?$order['externalId']:$order['orderNumber'];
                $key=hash('sha256',json_encode([$identity,$product['lineId']],JSON_THROW_ON_ERROR));$source=hash('sha256',$order['provider'].':'.$key);
                if($this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source])){$plan['existing']++;continue;}
                $fields=array_flip(['orderedAt','customerName','recipientName','phone','deliveryAddress','province','district','recipientEmail','invoiceName','invoiceAddress','customerType','taxNumber','packageStatus']);
                $payload=[...array_intersect_key($contact,$fields),'province'=>$order['city'],'productName'=>$product['title'],'quantity'=>$product['quantity'],'invoiceCents'=>$product['amountCents']??null];
                if($order['provider']==='Pazarama'){
                    $payload['invoiceCents']=$product['invoiceCents']??null;
                    if(array_key_exists('invoiceCents',$product))$payload['invoiceBasis']='buyer_product';
                }
                $matches=array_filter($manual[$order['provider']]??[],fn($saved)=>self::possibleManualMatch($saved,$payload));
                if($matches){$plan[count($matches)===1?'manual':'ambiguous']++;continue;}
                $plan['items'][]=['provider'=>$order['provider'],'deliveryId'=>$order['id'],'key'=>$key,'payload'=>$payload];
            }
        }
        return $plan;
    }
    private static function possibleManualMatch(array $a,array $b): bool
    {
        $phone=static fn($v)=>substr(preg_replace('/\D/','',(string)$v),-10);
        if($phone($a['phone']??'')===''||$phone($a['phone']??'')!==$phone($b['phone']??''))return false;
        $zone=new \DateTimeZone('Europe/Istanbul');
        if(abs((new \DateTimeImmutable($a['orderedAt'],$zone))->getTimestamp()-(new \DateTimeImmutable($b['orderedAt'],$zone))->getTimestamp())>900)return false;
        // Conservative: an existing same-customer order within 15 minutes is reviewed, never duplicated automatically.
        return true;
    }
    public function apply(int $company,array $plan): int
    {
        return $this->db->transaction(function()use($company,$plan){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$since=$this->restoreActivation($company);$count=0;
            $current=[];
            foreach($plan['items'] as $item){$month=(new \DateTimeImmutable($item['payload']['orderedAt'],new \DateTimeZone('Europe/Istanbul')))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m');if(!isset($current[$month]))$current[$month]=$this->inspect($company,$month)['items'];}
            $allowed=[];foreach($current as $items)foreach($items as $item)$allowed[$item['provider'].':'.$item['key']]=$item;
            try{
                foreach($plan['items'] as $item){
                    $fresh=$allowed[$item['provider'].':'.$item['key']]??null;if(!$fresh)continue;$item=$fresh;
                    $orderedAt=(new \DateTimeImmutable($item['payload']['orderedAt'],new \DateTimeZone('Europe/Istanbul')))->getTimestamp();
                    $this->db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='shipment_auto_since'",[json_encode(['since'=>min($since,$orderedAt)]),$company]);
                    $source=hash('sha256',$item['provider'].':'.$item['key']);
                    $before=$this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]);
                    (new ShipmentOrderIngestor($this->db,$this->cipher))->ingest($company,$item['provider'],[['key'=>$item['key'],'payload'=>$item['payload']]]);
                    if(!$before&&$this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]))$count++;
                }
            }finally{$this->db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='shipment_auto_since'",[json_encode(['since'=>$since]),$company]);}
            return $count;
        });
    }
}
