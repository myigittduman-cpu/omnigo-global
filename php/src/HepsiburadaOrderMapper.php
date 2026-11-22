<?php
declare(strict_types=1);
namespace Omnigo;

/** Uses the authoritative order detail; package feeds never add line totals twice. */
final class HepsiburadaOrderMapper
{
    private static function text(mixed $value,int $length=1500):string{return DeliveryMapper::text($value,$length);}
    private static function date(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))throw new SyncFailure(422);
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        if(\DateTimeImmutable::getLastErrors()!==false)throw new SyncFailure(422);
        return $date->format('c');
    }
    private static function address(array $address):string
    {
        return implode(', ',array_unique(array_filter(array_map(fn($key)=>self::text($address[$key]??''),['address','district','town','city','postalCode']))));
    }
    public static function map(array $raw,string $merchant,string $expectedNumber,array $packageStates=[],?HepsiburadaShippingLabelCache $labels=null):array
    {
        if((string)($raw['orderNumber']??'')!==$expectedNumber||!preg_match('/^[0-9]{1,30}$/D',$expectedNumber))throw new SyncFailure(422);
        $created=self::date($raw['orderDate']??null);$lines=$raw['items']??null;
        if(!is_array($lines)||!array_is_list($lines)||!$lines||count($lines)>1000)throw new SyncFailure(422);
        $products=[];$deliveries=[];$seen=[];$gross=0;$cancel=0;$shipping=[];
        foreach($lines as $line){
            if(!is_array($line)||strtolower((string)($line['merchantId']??''))!==$merchant||(string)($line['orderNumber']??'')!==$expectedNumber)throw new SyncFailure(422);
            $id=self::text($line['id']??'',80);if($id===''||isset($seen[$id]))throw new SyncFailure(422);$seen[$id]=true;
            $quantity=$line['quantity']??null;if(!is_int($quantity)||$quantity<1||$quantity>100000)throw new SyncFailure(422);
            if(($line['totalPrice']['currency']??'')!=='TRY')throw new SyncFailure(422);
            $amount=HepsiburadaMoney::cents($line['totalPrice']['amount']??null);
            $state=match($line['status']??''){
                'Open','Unpacked','Packaged'=>'pending','InTransit'=>'shipped','Delivered'=>'delivered',
                'CancelledByMerchant','CancelledByCustomer','CancelledBySap'=>'cancelled','ClaimCreated'=>'review','Undelivered'=>'undelivered',
                default=>throw new SyncFailure(422)
            };
            $package=(string)($line['packageNumber']??'');$packageState=$packageStates[$package]??null;
            if(in_array($line['status']??'',['ClaimCreated','InTransit','Packaged'],true)&&in_array($packageState,['delivered','shipped','undelivered'],true))$state=$packageState;
            $updated=self::date($line['lastStatusUpdateDate']??$raw['orderDate']);$cancelled=$state==='cancelled'?$quantity:0;
            $label=match($state){'pending'=>'Sevkiyat bekliyor','shipped'=>'Kargolandı','delivered'=>'Teslim edildi','cancelled'=>'İptal edildi',default=>'Kontrol gerekiyor'};
            $product=['lineId'=>$id,'title'=>self::text($line['name']??'',500),'sku'=>self::text($line['merchantSKU']??$line['merchantSku']??$line['sku']??'',200),
                'quantity'=>$quantity,'amountCents'=>$amount,'grossCents'=>$amount,'cancelCents'=>$cancelled?$amount:0,'returnCents'=>0,
                'netQuantity'=>$quantity-$cancelled,'cancelledQuantity'=>$cancelled,'status'=>$label,'shippingState'=>$state,'sourceUpdatedAt'=>$updated];
            $product['sourceStatus']=(string)$line['status'];
            if($package!==''&&$packageState!==null){$product['packageNumber']=$package;$product['packageShippingState']=$packageState;}
            if($product['title']==='')throw new SyncFailure(422);
            $products[]=$product;$gross+=$amount;$cancel+=$cancelled?$amount:0;$shipping[]=$state;
            $address=$line['shippingAddress']??$raw['deliveryAddress']??[];$invoice=$line['invoice']??$raw['invoice']??[];$billing=$invoice['address']??[];
            if(!is_array($address)||!is_array($invoice)||!is_array($billing))throw new SyncFailure(422);
            $cancellation=['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>false];
            $active=in_array($state,['pending','shipped'],true)&&empty($line['isMicroExport'])&&($address['countryCode']??'TR')==='TR';
            $delivery=['provider'=>'Hepsiburada','externalId'=>$expectedNumber.':'.$id,'orderNumber'=>$expectedNumber,'createdAt'=>$created,
                'buyerName'=>self::text($line['customerName']??$raw['customer']['name']??'',240),'phone'=>self::text($address['phoneNumber']??'',60)?:self::text($address['alternatePhoneNumber']??'',60),
                'address'=>self::address($address),'city'=>DeliveryMapper::city($address['city']??''),'district'=>self::text($address['town']??$address['district']??'',100),
                'status'=>$label,'deliveryStatus'=>$label,'active'=>$active,'products'=>$active?[array_intersect_key($product,array_flip(['title','sku','quantity']))]:[],
                'operationalExcluded'=>!empty($line['isMicroExport'])||($address['countryCode']??'TR')!=='TR',
                'cancellation'=>[...$cancellation,'lines'=>[$id=>$cancellation]],'cancelledProducts'=>$cancelled?[['lineId'=>$id,'title'=>$product['title'],'quantity'=>$cancelled]]:[]];
            $tax=self::text($invoice['taxNumber']??'',30);$type=$tax!==''?'Kurumsal':'Bireysel';
            $contact=['recipientName'=>self::text($address['name']??'',240),'recipientEmail'=>self::text($address['email']??'',200),'invoiceName'=>self::text($billing['name']??'',240),
                'invoiceAddress'=>self::address($billing),'customerType'=>$type,'taxNumber'=>$type==='Kurumsal'?$tax:'','taxOffice'=>self::text($invoice['taxOffice']??'',100)];
            $upperCode=ShipmentProductCode::normalize($line['merchantSKU']??null);
            $lowerCode=ShipmentProductCode::normalize($line['merchantSku']??null);
            $sellerStockCode=$upperCode===''?$lowerCode:$upperCode;
            if($upperCode!==''&&$lowerCode!==''&&$upperCode!==$lowerCode)$sellerStockCode='';
            $labelPackage=in_array($line['status'],['Packaged','InTransit','ClaimCreated'],true)&&preg_match('/^[0-9]{1,30}$/D',$package)?$package:'';
            $delivery['details']=[...$contact,'province'=>self::text($address['city']??'',100),
                'labelPackageId'=>$labelPackage,'shippingBarcode'=>$labelPackage!==''?$labels?->read($expectedNumber,$labelPackage):null,
                'products'=>[[...$product,'sellerStockCode'=>$sellerStockCode,...ShipmentSourceProduct::read('Hepsiburada',$line)]]];
            $delivery['shipmentItems']=empty($line['isMicroExport'])&&($address['countryCode']??'TR')==='TR'?[['key'=>hash('sha256',json_encode([$expectedNumber,$id],JSON_THROW_ON_ERROR)),
                'payload'=>[...$contact,'orderedAt'=>$created,'customerName'=>$delivery['buyerName'],'phone'=>$delivery['phone'],'deliveryAddress'=>$delivery['address'],
                    'province'=>$delivery['city'],'district'=>$delivery['district'],'packageStatus'=>$label,'productName'=>$product['title'],'sellerStockCode'=>$sellerStockCode,'quantity'=>$quantity,'invoiceCents'=>$amount,
                    'marketplaceCancellation'=>$cancellation,'marketplaceShipping'=>$state,'marketplaceAmounts'=>ShipmentPaymentAmounts::hepsiburada($line)]]]:[];
            $deliveries[]=DeliveryMapper::finish($delivery);
        }
        $states=array_values(array_unique($shipping));
        $state=count($states)>1?'MIXED':match($states[0]){'pending'=>'SHIPPING','shipped'=>'SHIPPED','delivered'=>'RECEIVED','cancelled'=>'CANCELED',default=>'UNDELIVERED'};
        return ['order'=>['externalId'=>$expectedNumber,'createdAt'=>$created,'state'=>$state,'orders'=>1,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>0,'refundsVerified'=>false,'products'=>$products], 'deliveries'=>$deliveries];
    }
}
