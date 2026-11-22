<?php
declare(strict_types=1);
namespace Omnigo;

/** Item identities survive package splits. Source item metadata stays in encrypted delivery payloads. */
final class TrendyolOrderMapper
{
    public static function status(string $raw): array
    {
        return match($raw){
            'Created','Picking','Invoiced'=>['pending','Sevk bekliyor'],
            'Shipped','AtCollectionPoint'=>['shipped','Sevk edildi'],
            'Delivered'=>['delivered','Teslim edildi'],
            'Cancelled','UnSupplied'=>['cancelled','İptal edildi'],
            'UnDelivered'=>['undelivered','Teslim edilemedi'],
            'Returned'=>['returned','İade edildi'],
            'Awaiting','Verified'=>['unknown','Onay bekleniyor'],
            'UnPacked'=>['unknown','Paket yeniden düzenleniyor'],
            default=>throw new SyncFailure(422),
        };
    }
    public static function identity(mixed $value): string
    {
        $value=(string)$value;if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$value))throw new SyncFailure(422);return $value;
    }
    public static function timestamp(mixed $value): int
    {
        if(!is_int($value)||$value<946684800000||$value>4102444800000)throw new SyncFailure(422);return $value;
    }
    public static function merge(array $raw,array $old=[]): array
    {
        $number=self::identity($raw['orderNumber']??'');$package=self::identity($raw['shipmentPackageId']??'');
        $modified=self::timestamp($raw['lastModifiedDate']??null);$ordered=self::timestamp($raw['orderDate']??null);
        if(($raw['currencyCode']??'')!=='TRY'||!is_array($raw['lines']??null)||!$raw['lines'])throw new SyncFailure(422);
        if($old&&($old['orderNumber']??'')!==$number)throw new SyncFailure(422);
        $origins=array_map([self::class,'identity'],$raw['originPackageIds']??[]);$base=self::address($raw,$ordered);
        $units=$old['trendyolSourceItems']??[];$total=0;$seen=[];
        foreach($raw['lines'] as $line){
            $details=$line['discountDetails']??null;$quantity=$line['quantity']??null;
            if(!is_int($quantity)||$quantity<1||$quantity>10000||!is_array($details)||count($details)!==$quantity)throw new SyncFailure(422);
            [$shipping,$label]=self::status((string)($line['orderLineItemStatusName']??$raw['shipmentPackageStatus']??''));
            foreach($details as $detail){
                $id=self::identity($detail['lineItemId']??'');if(isset($seen[$id]))throw new SyncFailure(422);$seen[$id]=true;
                $net=KoctasOrderMapper::cents($detail['lineItemPrice']??null);$seller=KoctasOrderMapper::cents($detail['lineItemSellerDiscount']??0);$platform=KoctasOrderMapper::cents($detail['lineItemTyDiscount']??0);$total+=$net;
                $proof=[];
                if(($line['currencyCode']??'TRY')==='TRY'){
                    $proof=['sellerDiscountReported'=>isset($detail['lineItemSellerDiscount']),
                        'platformDiscountReported'=>isset($detail['lineItemTyDiscount'])];
                    if(isset($line['lineGrossAmount'])){
                        // Optional evidence never blocks the operational order import.
                        try{$proof['sourceGrossCents']=KoctasOrderMapper::cents($line['lineGrossAmount']);}catch(SyncFailure){}
                    }
                }else{$proof=['pricingCurrencyMismatch'=>true];}
                $prior=$units[$id]??null;
                if(!self::acceptUnit($prior,$package,$origins,$modified))continue;
                $units[$id]=['package'=>$package,'origins'=>array_values(array_unique([...$origins,...($prior['origins']??[])])),'modified'=>$modified,'base'=>$base,
                    'sellerStockCode'=>ShipmentProductCode::normalize($line['stockCode']??null)?:($prior['sellerStockCode']??''),
                    ...ShipmentSourceProduct::read('Trendyol',$line),
                    'product'=>['lineId'=>$id,'title'=>DeliveryMapper::text($line['productName']??'',1000),'sku'=>DeliveryMapper::text($line['stockCode']??$line['barcode']??'',180),
                        'quantity'=>1,'grossCents'=>$net,'shippingState'=>$shipping,'status'=>$label,
                        'cancelledQuantity'=>in_array($shipping,['cancelled','undelivered'],true)?1:0,'refundedQuantity'=>$shipping==='returned'?1:0,
                        'pricing'=>['reportBasis'=>'discounted','reportCents'=>$net,'discountedCents'=>$net,'originalCents'=>$net+$seller+$platform,'sellerDiscountCents'=>$seller,'platformDiscountCents'=>$platform,...$proof]],
                    'trackingNumber'=>DeliveryMapper::text($raw['cargoTrackingNumber']??'',180)];
            }
        }
        if($total!==KoctasOrderMapper::cents($raw['packageTotalPrice']??null))throw new SyncFailure(422);
        if(count($units)>10000)throw new SyncFailure(422);ksort($units,SORT_STRING);
        return self::project($number,$units);
    }
    public static function acceptUnit(?array $prior,string $package,array $origins,int $modified):bool
    {
        if(!$prior)return true;
        if(in_array($package,$prior['origins'],true))return false;
        if(in_array($prior['package'],$origins,true))return true;
        if($modified<$prior['modified'])return false;
        if($package!==$prior['package']&&$modified===$prior['modified'])throw new SyncFailure(422);
        return true;
    }
    private static function address(array $raw,int $ordered): array
    {
        $text=fn($value,$limit=8000)=>DeliveryMapper::text($value,$limit);$shipping=$raw['shipmentAddress']??[];$invoice=$raw['invoiceAddress']??[];
        $name=fn($a)=>$text($a['fullName']??trim(($a['firstName']??'').' '.($a['lastName']??'')),300);
        $address=fn($a)=>$text($a['fullAddress']??trim(($a['address1']??'').' '.($a['address2']??'')));
        return ['orderedAt'=>gmdate(DATE_ATOM,intdiv($ordered,1000)),'customerName'=>trim($text($raw['customerFirstName']??'',150).' '.$text($raw['customerLastName']??'',150)),
            'recipientName'=>$name($shipping),'phone'=>$text($shipping['phone']??'',60),'recipientEmail'=>$text($raw['customerEmail']??'',250),
            'deliveryAddress'=>$address($shipping),'province'=>DeliveryMapper::city($shipping['city']??''),'district'=>$text($shipping['district']??'',100),
            'invoiceName'=>!empty($raw['commercial'])?($text($invoice['company']??'',300)?:$name($invoice)):$name($invoice),
            'invoiceAddress'=>$address($invoice),'customerType'=>!empty($raw['commercial'])?'Kurumsal':'Bireysel','taxNumber'=>!empty($raw['commercial'])?$text($invoice['taxNumber']??$raw['taxNumber']??'',40):''];
    }
    private static function project(string $number,array $units): array
    {
        $products=[];$active=[];$shipments=[];$cancelLines=[];$cancelProducts=[];$totals=SalesCalendar::ZERO;$states=[];$base=reset($units)['base'];$latest=null;
        foreach($units as $id=>$unit){
            $p=$unit['product'];$shipping=$p['shippingState'];$states[]=$shipping;
            $p['cancelCents']=$p['cancelledQuantity']?$p['grossCents']:0;$p['returnCents']=$p['refundedQuantity']?$p['grossCents']:0;
            $p['netQuantity']=$p['cancelledQuantity']||$p['refundedQuantity']?0:1;$p['netCents']=$p['grossCents']-$p['cancelCents']-$p['returnCents'];$products[]=$p;
            foreach(['grossCents','cancelCents','returnCents'] as $key)$totals[$key]+=$p[$key];
            if($latest===null||$unit['modified']>$latest['modified'])$latest=$unit;
            $cancelLines[$id]=['quantity'=>1,'cancelledQuantity'=>$p['cancelledQuantity'],'partial'=>false];
            if($p['cancelledQuantity'])$cancelProducts[]=['lineId'=>(string)$id,'title'=>$p['title'],'quantity'=>1];
            if(in_array($shipping,['pending','shipped'],true))$active[]=['lineId'=>(string)$id,'title'=>$p['title'],'sku'=>$p['sku'],'quantity'=>1];
            if($shipping!=='unknown')$shipments[]=['key'=>hash('sha256',json_encode([$number,(string)$id],JSON_THROW_ON_ERROR)),'payload'=>[...$unit['base'],'orderNumber'=>$number,'productName'=>$p['title'],'sellerStockCode'=>$unit['sellerStockCode']??'','quantity'=>1,'invoiceCents'=>$p['grossCents'],'marketplaceAmounts'=>ShipmentPaymentAmounts::pricing($p['pricing']??null),
                'packageStatus'=>$p['status'],'marketplaceShipping'=>$shipping==='returned'?'undelivered':$shipping,'marketplaceRowStatus'=>$shipping==='returned'?'undelivered':$shipping,'marketplaceCancellation'=>$cancelLines[$id]]];
        }
        $state=count(array_unique($states))===1?$states[0]:'mixed';$label=$state==='mixed'?'Birden fazla işlem durumu':reset($products)['status'];
        $delivery=DeliveryMapper::finish(['provider'=>'Trendyol','externalId'=>$number,'orderNumber'=>$number,'buyerName'=>$base['customerName'],'phone'=>$base['phone'],
            'address'=>$base['deliveryAddress'],'city'=>$base['province'],'district'=>$base['district'],'createdAt'=>$base['orderedAt'],'status'=>$label,'deliveryStatus'=>$label,'shippingState'=>$state,
            'active'=>(bool)$active,'products'=>$active,'cancellation'=>[...OrderCancellation::combine($cancelLines),'lines'=>$cancelLines],'cancelledProducts'=>$cancelProducts]);
        $delivery['shipmentItems']=$shipments;$delivery['trendyolSourceItems']=$units;
        $addresses=[];foreach($units as $unit)if(in_array($unit['product']['shippingState'],['pending','shipped'],true))$addresses[]=json_encode(array_intersect_key($unit['base'],array_flip(['recipientName','deliveryAddress','province','district','phone'])));
        if(count(array_unique($addresses))>1)$delivery['issues'][]='Aynı siparişin paketleri farklı teslimat adresleri içeriyor; adresler kontrol edilmeli';
        $delivery['details']=[...$base,'status'=>$label,'trackingNumber'=>$latest['trackingNumber'],'products'=>array_map(fn($p)=>['amountCents'=>$p['grossCents'],...$p,'sellerStockCode'=>$units[$p['lineId']]['sellerStockCode']??'',
            ...array_intersect_key($units[$p['lineId']],['sourceProduct'=>true])],$products)];
        $created=new \DateTimeImmutable($base['orderedAt']);
        return ['delivery'=>$delivery,'order'=>['externalId'=>$number,'createdAt'=>$base['orderedAt'],'month'=>$created->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m'),
            ...$totals,'orders'=>1,'state'=>$label,'products'=>$products,'refundsVerified'=>true]];
    }
}
