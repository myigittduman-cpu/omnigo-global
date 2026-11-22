<?php
declare(strict_types=1);
namespace Omnigo;

/** Rebuilds only known source lines, retaining their original deduplication identity. */
final class DeliveryCorrectionShipments
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function items(int $company,array $order): array
    {
        if($order['issues']||!$order['active']||!empty($order['details']['superseded']))return [];
        try{$date=new \DateTimeImmutable($order['createdAt']?:'invalid',new \DateTimeZone('Europe/Istanbul'));}catch(\Exception){Http::fail(422,'Kaynak sipariş tarihi doğrulanamadı; sevkiyata eksik aktarım yapılmadı.');}
        if(\DateTimeImmutable::getLastErrors()!==false||$date->getTimestamp()>time()+300)Http::fail(422,'Kaynak sipariş tarihi geçersiz veya gelecekte. Tarih doğrulanmadan sevkiyat oluşturulmadı.');
        $items=[];$contact=$order['details']??[];
        foreach($contact['products']??[] as $product){
            $id=(string)($product['lineId']??'');$quantity=(int)($product['quantity']??0);
            if($id===''||$quantity<1)Http::fail(422,'Ürün kimliği veya adedi eksik. Kaynak ürün doğrulanmadan sevkiyat oluşturulamaz.');
            $identity=$order['provider']==='Pazarama'?$order['externalId']:$order['orderNumber'];
            $key=hash('sha256',json_encode([$identity,$id],JSON_THROW_ON_ERROR));$source=hash('sha256',$order['provider'].':'.$key);
            $existing=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$source]);
            if(!$existing&&empty($product['title'])&&($product['cancelledQuantity']??0)>=$quantity)continue;
            if($existing&&$existing['removed_at']!==null)Http::fail(409,'Bu sevkiyat satırı daha önce kaldırılmış. Otomatik olarak yeniden oluşturulmadı.');
            $base=$existing?$this->cipher->open($company,'shipment:'.$existing['id'],$existing['payload']):[];
            if(!$existing){
                foreach($this->db->all('SELECT id,payload FROM shipment_entries WHERE company_id=? AND provider=? AND source_key IS NULL AND removed_at IS NULL',[$company,$order['provider']]) as $manual){
                    $saved=$this->cipher->open($company,'shipment:'.$manual['id'],$manual['payload']);
                    $phone=static fn($s)=>substr(preg_replace('/\D/','',(string)$s),-10);
                    if($phone($order['phone'])!==''&&$phone($saved['phone']??'')===$phone($order['phone'])&&abs((strtotime($saved['orderedAt']??'')?:0)-(strtotime($order['createdAt'])?:0))<=900)Http::fail(409,'Olası bir manuel sevkiyat kaydı bulundu. Mükerrer oluşturmamak için kayıt eşleştirmesi gerekiyor.');
                }
            }
            $shipping=$product['shippingState']??OrderShipping::state($order['provider'],$product['status']??'');
            if($shipping==='unknown')$shipping=$order['shippingState']??'unknown';
            $cancelled=(int)($product['cancelledQuantity']??0);if($cancelled>=$quantity)$shipping='cancelled';
            $payload=[...$base,...array_intersect_key($contact,array_flip(['recipientName','recipientEmail','invoiceName','invoiceAddress','customerType','taxNumber'])),
                'orderedAt'=>$order['createdAt'],'customerName'=>$order['buyerName'],'phone'=>$order['phone'],'deliveryAddress'=>$order['address'],'province'=>$order['city'],'district'=>$order['district'],
                'productName'=>$product['title'],'quantity'=>$quantity,'invoiceCents'=>$product['amountCents']??null,'packageStatus'=>$order['status'],
                'marketplaceCancellation'=>['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>$cancelled>0&&$cancelled<$quantity],'marketplaceShipping'=>$shipping];
            if($order['provider']==='Pazarama'){
                unset($payload['invoiceBasis']);
                $payload['invoiceCents']=$product['invoiceCents']??null;
                if(array_key_exists('invoiceCents',$product))$payload['invoiceBasis']='buyer_product';
            }
            if(!isset($payload['marketplaceRowStatus']))$payload['marketplaceRowStatus']=$order['sourceShipmentStates'][$key]??match(true){
                $shipping==='cancelled'=>'cancelled',
                $order['provider']==='Koçtaş'&&($product['status']??'')==='CLOSED'=>'completed',
                $order['provider']==='idefix'&&($contact['status']??'')==='shipment_invoiced'=>'invoiced',
                $order['provider']==='idefix'&&($contact['status']??'')==='shipment_approved'=>'completed',
                default=>$shipping,
            };
            $items[]=['key'=>$key,'payload'=>$payload];
        }
        if(!$items)Http::fail(422,'Kaynak ürün bilgisi yok. Sipariş sevkiyata eksik olarak aktarılmadı.');
        return $items;
    }
    public function assertTransferred(int $company,string $provider,array $items): void
    {
        foreach($items as $item){
            $source=hash('sha256',$provider.':'.$item['key']);
            if(!$this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=? AND removed_at IS NULL',[$company,$source]))Http::fail(409,'Sevkiyat satırı doğrulanamadı. Değişiklikler kaydedilmedi; tekrar kontrol edin.');
        }
    }
    public function hasRows(int $company,array $order): bool
    {
        $identity=$order['provider']==='Pazarama'?$order['externalId']:$order['orderNumber'];
        foreach($order['details']['products']??[] as $product){
            $key=hash('sha256',json_encode([$identity,(string)($product['lineId']??'')],JSON_THROW_ON_ERROR));
            if($this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=? AND removed_at IS NULL',[$company,hash('sha256',$order['provider'].':'.$key)]))return true;
        }
        return false;
    }
}
