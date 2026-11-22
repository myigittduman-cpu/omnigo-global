<?php
declare(strict_types=1);
namespace Omnigo;

/** The list endpoint returns one physical unit per item, unlike list-v2. */
final class IdefixOrderMapper
{
    private const STATES=['created'=>'WAITING_ACCEPTANCE','shipment_ready'=>'SHIPPING','shipment_picking'=>'SHIPPING','shipment_invoiced'=>'SHIPPING','shipment_cancelled'=>'CANCELED','shipment_unsupplied'=>'REFUSED','shipment_split'=>'SPLIT','shipment_in_cargo'=>'SHIPPED','shipment_delivered'=>'RECEIVED','shipment_undeliver'=>'UNDELIVERED','shipment_approved'=>'CLOSED'];
    private static function text(mixed $value,int $limit=8000): string{return DeliveryMapper::text($value,$limit);}
    private static function name(array $address): string{return self::text($address['fullName']??'')?:trim(self::text($address['firstName']??'').' '.self::text($address['lastName']??''));}
    public static function map(array $raw): array
    {
        $package=self::text($raw['id']??'',30);$number=PazaramaSalesMapper::text($raw['orderNumber']??null,140);
        $status=$raw['status']??'';$date=$raw['orderDate']??null;
        if(!ctype_digit($package)||!isset(self::STATES[$status])||!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$date))throw new SyncFailure(422);
        try{$created=new \DateTimeImmutable($date);}catch(\Throwable){throw new SyncFailure(422);}
        if(\DateTimeImmutable::getLastErrors()!==false)throw new SyncFailure(422);
        $items=$raw['items']??null;if(!is_array($items)||!array_is_list($items)||!$items||count($items)>1000)throw new SyncFailure(422);
        $products=[];$sourceProducts=[];$seen=[];$gross=0;$cancel=0;
        foreach($items as $item){
            $line=self::text($item['id']??'',30);if(!ctype_digit($line)||isset($seen[$line])||!in_array($item['currency']??null,['TL','TRY'],true))throw new SyncFailure(422);$seen[$line]=true;
            $amount=KoctasOrderMapper::cents($item['discountedTotalPrice']??null);
            $cancelled=in_array($status,['shipment_cancelled','shipment_unsupplied'],true)||in_array($item['itemStatus']??'',['cancelled','canceled','unsupplied'],true);
            $gross+=$amount;$cancel+=$cancelled?$amount:0;
            $products[]=['lineId'=>$line,'sku'=>self::text($item['merchantSku']??'',180),'title'=>PazaramaSalesMapper::text($item['productName']??null,1000),'quantity'=>1,'grossCents'=>$amount,'cancelCents'=>$cancelled?$amount:0,'returnCents'=>0,'netCents'=>$cancelled?0:$amount,'netQuantity'=>$cancelled?0:1,'status'=>self::text($item['itemStatus']??''),'cancelledQuantity'=>$cancelled?1:0,'shippingState'=>OrderShipping::line('idefix',$item,$raw)];
            $sourceProducts[$line]=ShipmentSourceProduct::read('idefix',$item);
            $pricing=OrderPricing::idefix($item,$amount);
            $products[array_key_last($products)]['earningCents']=IdefixFinanceMapper::amount($item);
            if($pricing!==null)$products[array_key_last($products)]['pricing']=$pricing;
        }
        if($gross!==KoctasOrderMapper::cents($raw['discountedTotalPrice']??null))throw new SyncFailure(422);
        $shipping=$raw['shippingAddress']??[];$invoice=$raw['invoiceAddress']??[];
        if(!is_array($shipping)||!is_array($invoice))throw new SyncFailure(422);
        $commercial=in_array(strtolower((string)($invoice['isCommercial']??'')),['true','1'],true);$office=self::text($invoice['taxOffice']??'');
        $contact=['orderedAt'=>$created->format(DATE_ATOM),'customerName'=>self::text($raw['customerContactName']??''),'recipientName'=>self::name($shipping),'phone'=>self::text($shipping['phone']??'',60),'deliveryAddress'=>self::text($shipping['fullAddress']??$shipping['address1']??''),'province'=>self::text($shipping['city']??'',100),'district'=>self::text($shipping['county']??'',100),'recipientEmail'=>self::text($raw['customerContactMail']??''),'invoiceName'=>$commercial?(self::text($invoice['company']??'')?:self::name($invoice)):self::name($invoice),'invoiceAddress'=>self::text($invoice['fullAddress']??$invoice['address1']??'').($commercial&&$office!==''?"\nV.D.: ".$office:''),'customerType'=>$invoice?($commercial?'Kurumsal':'Bireysel'):'','taxNumber'=>$commercial?self::text($invoice['taxNumber']??''):'','packageStatus'=>self::text($raw['statusDescription']??$status)];
        $shipmentItems=[];
        if($status!=='shipment_split')foreach($products as $product)$shipmentItems[]=['key'=>hash('sha256',json_encode([$number,$product['lineId']],JSON_THROW_ON_ERROR)),'payload'=>[...$contact,'province'=>DeliveryMapper::city($contact['province']),'marketplaceCancellation'=>['quantity'=>1,'cancelledQuantity'=>$product['cancelledQuantity'],'partial'=>false],'marketplaceRowStatus'=>$product['cancelledQuantity']?'cancelled':match($status){'shipment_approved'=>'completed','shipment_invoiced'=>'invoiced',default=>$product['shippingState']},'marketplaceShipping'=>$product['shippingState'],'productName'=>$product['title'],'quantity'=>1,'invoiceCents'=>$product['grossCents'],'marketplaceAmounts'=>ShipmentPaymentAmounts::pricing($product['pricing']??null)]];
        $delivery=DeliveryMapper::finish(OrderCancellation::delivery(['provider'=>'idefix','externalId'=>$number.':'.$package,'orderNumber'=>$number,'buyerName'=>$contact['customerName'],'phone'=>$contact['phone'],'address'=>$contact['deliveryAddress'],'city'=>DeliveryMapper::city($contact['province']),'district'=>$contact['district'],'createdAt'=>$contact['orderedAt'],'status'=>$contact['packageStatus'],'active'=>in_array($status,['created','shipment_ready','shipment_picking','shipment_invoiced','shipment_in_cargo'],true),'products'=>array_map(fn($item)=>array_intersect_key($item,array_flip(['title','sku','quantity'])),array_values(array_filter($products,fn($item)=>$item['netQuantity']>0)))],$raw));
        $delivery['shipmentItems']=$shipmentItems;
        $delivery['shippingState']=OrderShipping::state('idefix',$status);
        $delivery['details']=[...$contact,'packageId'=>$package,'superseded'=>$status==='shipment_split','status'=>$contact['packageStatus'],'cargoCompany'=>self::text($raw['cargoCompany']??''),'trackingNumber'=>self::text($raw['cargoTrackingNumber']??''),'products'=>array_map(fn($item)=>['amountCents'=>$item['grossCents'],...array_intersect_key($item,array_flip(['lineId','title','sku','quantity','status','cancelledQuantity','shippingState'])),...$sourceProducts[$item['lineId']]],$products)];
        // Split source shipments remain available as details but cannot double the order totals.
        $order=['externalId'=>$number,'packageId'=>$package,'createdAt'=>$contact['orderedAt'],'month'=>$created->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m'),'state'=>self::STATES[$status],'orders'=>$status==='shipment_split'?0:1,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>0,'refundsVerified'=>false,'products'=>$products];
        return ['order'=>$order,'delivery'=>$delivery];
    }
    public static function combine(array $packages): array
    {
        $orders=[];$seenPackages=[];$seenLines=[];
        foreach($packages as $package){
            if(isset($seenPackages[$package['packageId']]))throw new SyncFailure(422);$seenPackages[$package['packageId']]=true;
            if(!$package['orders'])continue;
            $id=$package['externalId'];
            foreach($package['products'] as $item){$line=$id.':'.$item['lineId'];if(isset($seenLines[$line]))throw new SyncFailure(422);$seenLines[$line]=true;}
            if(!isset($orders[$id])){$orders[$id]=$package;unset($orders[$id]['packageId']);continue;}
            foreach(['grossCents','cancelCents','returnCents'] as $field)$orders[$id][$field]+=$package[$field];
            array_push($orders[$id]['products'],...$package['products']);
            if($orders[$id]['state']!==$package['state'])$orders[$id]['state']='MIXED';
        }
        return array_values($orders);
    }
}
