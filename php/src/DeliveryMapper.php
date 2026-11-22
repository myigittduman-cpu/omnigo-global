<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryMapper
{
    public const CITIES=TurkeyCities::ALL;
    public static function text(mixed $value,int $limit=500): string
    {
        return is_scalar($value)?mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)$value)),0,$limit):'';
    }
    public static function city(mixed $value): string
    {
        $value=self::text($value,100);$normalize=static fn($s)=>strtolower(strtr(trim($s),['İ'=>'i','I'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g','Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c']));
        foreach(self::CITIES as $city)if($normalize($city)===$normalize($value))return $city;
        return 'Diğer / Kontrol';
    }
    public static function koctas(array $raw): array
    {
        $customer=$raw['customer']??[];$address=$customer['shipping_address']??[];
        $active=in_array($raw['order_state']??'',['WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING','SHIPPED','TO_COLLECT'],true);$products=[];
        foreach($raw['order_lines']??[] as $line)if(in_array($line['order_line_state']??'',['WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING','SHIPPED','TO_COLLECT'],true)){
            $quantity=(int)($line['quantity']??0);
            foreach($line['cancelations']??[] as $c)$quantity-=(int)($c['quantity']??0);
            foreach($line['refunds']??[] as $r)if(($r['refund_state']??'')==='REFUNDED')$quantity-=(int)($r['quantity']??0);
            if($quantity>0)$products[]=['title'=>self::text($line['product_title']??'',500),'sku'=>self::text($line['offer_sku']??$line['product_sku']??'',150),'quantity'=>$quantity];
        }
        $state=self::city($address['state']??'');
        $province=$state==='Diğer / Kontrol'?self::city($address['city']??''):$state;
        $deliveryAddress=self::text($address['street_1']??'')!==''?self::join($address,['street_1','street_2','additional_info','zip_code','city','state']):'';
        $district=DeliveryDistrict::resolve($province,[$address['district']??'',$address['city']??'',$address['state']??''],$deliveryAddress,self::text($address['street_1']??'',1000));
        return self::finish(OrderCancellation::delivery(['provider'=>'Koçtaş','externalId'=>self::text($raw['order_id']??'',180),'orderNumber'=>self::text($raw['order_id']??'',180),
            'buyerName'=>trim(self::text($customer['firstname']??'',120).' '.self::text($customer['lastname']??'',120)),
            'phone'=>self::text($address['phone']??'',60)?:self::text($address['phone_secondary']??'',60),
            'address'=>$deliveryAddress,'city'=>$province,'district'=>$district,
            'createdAt'=>self::text($raw['created_date']??'',40),'status'=>($raw['order_state']??'')==='SHIPPED'?'Yolda':'Sevkiyat bekliyor','active'=>$active&&count($products)>0,'products'=>$products],$raw));
    }
    public static function n11(array $raw): array
    {
        $address=$raw['shippingAddress']??[];$products=[];$status=$raw['shipmentPackageStatus']??'';
        foreach($raw['lines']??[] as $line)if(in_array($line['orderItemLineItemStatusName']??'',['Created','Picking','Shipped'],true))$products[]=['title'=>self::text($line['productName']??'',500),'sku'=>self::text($line['stockCode']??'',150),'quantity'=>max(0,(int)($line['quantity']??0))];
        $created='';$sourceOrderTime=null;
        foreach($raw['packageHistories']??[] as $history)if(($history['status']??'')==='Created'&&is_int($history['createdDate']??null)){
            $created=gmdate('c',intdiv($history['createdDate'],1000));
            $sourceOrderTime=$history['createdDate'];
            break;
        }
        $profitabilityPlacedAt=$sourceOrderTime>=1000000000000&&$sourceOrderTime<=9999999999999
            ?sprintf('%s.%03dZ',gmdate('Y-m-d\TH:i:s',intdiv($sourceOrderTime,1000)),$sourceOrderTime%1000):null;
        $number=self::text($raw['orderNumber']??'',100);
        return self::finish(OrderCancellation::delivery(['provider'=>'n11','externalId'=>N11DeliveryIdentity::externalId($raw),'orderNumber'=>$number,
            // Use the order owner field. Never fall back to shippingAddress.fullName or billingAddress.
            'buyerName'=>self::text($raw['customerfullName']??'',240),'phone'=>self::text($address['gsm']??'',60),
            'address'=>self::text($address['address']??'')!==''?self::join($address,['address','neighborhood','district','city','postalCode']):'',
            'city'=>self::city($address['city']??''),'district'=>self::text($address['district']??'',100),'createdAt'=>$created,
            'status'=>$status==='Shipped'?'Yolda':'Sevkiyat bekliyor','active'=>in_array($status,['Created','Picking','Shipped'],true)&&count($products)>0,'products'=>$products,
            ...($profitabilityPlacedAt===null?[]:['profitabilityPlacedAt'=>$profitabilityPlacedAt])],$raw));
    }
    public static function pazarama(array $raw): array
    {
        $address=$raw['shipmentAddress']??[];$products=[];$shipped=false;
        foreach($raw['items']??[] as $item)if(in_array($item['orderItemStatus']??null,[3,12,18,5,16,19],true)){
            $products[]=['title'=>self::text($item['product']['name']??'',500),'sku'=>self::text($item['product']['stockCode']??'',150),'quantity'=>(int)($item['quantity']??0)];
            $shipped=$shipped||in_array($item['orderItemStatus'],[5,16,19],true);
        }
        return self::finish(OrderCancellation::delivery(['provider'=>'Pazarama','externalId'=>self::text($raw['orderId']??'',180),'orderNumber'=>self::text($raw['orderNumber']??'',180),
            'buyerName'=>self::text($raw['customerName']??'',240),'phone'=>self::text($address['phoneNumber']??'',60),
            'address'=>self::text($address['displayAddressText']??'',1500)?:self::join($address,['addressDetail','neighborhoodName','districtName','cityName','postalCode']),
            'city'=>self::city($address['cityName']??''),'district'=>self::text($address['districtName']??'',100),
            'createdAt'=>self::text($raw['orderDate']??'',40),'status'=>$shipped?'Yolda':'Sevkiyat bekliyor','active'=>count($products)>0,'products'=>$products],$raw));
    }
    private static function join(array $value,array $keys): string
    {
        return implode(', ',array_unique(array_filter(array_map(fn($key)=>self::text($value[$key]??'',1000),$keys))));
    }
    public static function finish(array $value): array
    {
        $value['issues']=[];
        foreach(['buyerName'=>'Siparişi veren kişinin adı','phone'=>'Teslimat telefonu','address'=>'Teslimat adresi','orderNumber'=>'Sipariş numarası'] as $key=>$label)if($value[$key]==='')$value['issues'][]=$label.' eksik';
        if(!in_array($value['city'],self::CITIES,true))$value['issues'][]='Teslimat ili kontrol edilmeli';
        if((!$value['products']&&empty($value['cancelledProducts']))||array_filter($value['products'],fn($p)=>$p['title']===''||$p['quantity']<1))$value['issues'][]='Ürün bilgisi eksik';
        return $value;
    }
}
