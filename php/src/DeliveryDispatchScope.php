<?php
declare(strict_types=1);
namespace Omnigo;

/** A city entry groups the eligible packages of the same marketplace order. */
final class DeliveryDispatchScope
{
    public static function city(array $order): string { return $order['issues']?'Diğer / Kontrol':$order['city']; }

    public static function key(array $order): string
    {
        return hash('sha256',json_encode([$order['provider'],$order['orderNumber']?:$order['externalId'],self::city($order)],JSON_THROW_ON_ERROR));
    }

    public static function item(array $order, array $products,bool $showCancellationMark=false): array
    {
        $status=DeliveryPlan::status($order);
        $item=[
            'id'=>$order['id'],'version'=>$order['version'],'documentId'=>$order['documentId'],'provider'=>$order['provider'],'externalId'=>$order['externalId'],
            'orderNumber'=>$order['orderNumber'],'city'=>$order['city'],'buyerName'=>$order['buyerName'],'createdAt'=>$order['createdAt'],
            'products'=>$products,'marketplaceStatusText'=>self::marketplaceLabel($order,$status),
            'fulfillmentStatus'=>$status,'deliveryStatus'=>DeliveryPlan::label($status),
            'active'=>true,'issues'=>$order['issues'],'selectionKey'=>self::key($order),
            'selectionRecords'=>[['id'=>$order['id'],'version'=>$order['version']]],
        ];
        if($showCancellationMark&&OperationalCancellationMark::remainingOrder($order))$item['cancellationMark']='partial';
        return [...$item,...array_intersect_key($order,array_flip(['address','phone','recipientName','manualCorrections',
            'fulfillmentNeedsReview','fulfillmentReviewProducts']))];
    }

    private static function marketplaceLabel(array $order,string $status): string
    {
        $label=$order['marketplaceStatusText'];
        // This row contains only dispatchable lines, not the failed portion of a mixed order.
        if($label===ShipmentFulfillment::label('undelivered')&&OrderShipping::products($order,'undelivered')){
            return DeliveryPlan::label($status);
        }
        return $label;
    }

    public static function exportOrders(array $orders): array
    {
        $documents=[];
        foreach($orders as $order){
            $details=$order['details']??[];
            $key=hash('sha256',json_encode([$order['provider'],$order['orderNumber'],$order['city'],
                ($details['recipientName']??'')?:$order['recipientName'],$details['deliveryAddress']??$order['address'],
                $details['phone']??$order['phone'],$details['district']??$order['district']??'',
                $details['neighborhood']??'',$details['postalCode']??''],JSON_THROW_ON_ERROR));
            if(isset($documents[$key])){
                $documents[$key]['products']=[...$documents[$key]['products'],...$order['products']];
                if(isset($order['shippingLabels']))$documents[$key]['shippingLabels']=array_merge($documents[$key]['shippingLabels']??[],$order['shippingLabels']);
                if($documents[$key]['deliveryStatus']!==$order['deliveryStatus'])$documents[$key]['deliveryStatus']=DeliveryPlan::label('mixed');
                continue;
            }
            $documents[$key]=[...array_intersect_key($order,array_flip(['id','provider','externalId','orderNumber','city','buyerName','recipientName','address','phone',
                'district','createdAt','products','deliveryStatus','shippingLabels'])),
                'details'=>array_intersect_key($details,array_flip(['recipientName','deliveryAddress','province','neighborhood','district','postalCode','phone','orderedAt']))];
        }
        foreach($documents as &$document){
            if(isset($document['shippingLabels']))$document['shippingLabels']=DeliveryShippingLabels::merge($document['shippingLabels']);
        }
        unset($document);
        return array_values($documents);
    }

    public static function merge(array $item, array $part): array
    {
        if(isset($part['cancellationMark']))$item['cancellationMark']=$part['cancellationMark'];
        if($item['marketplaceStatusText']!==$part['marketplaceStatusText'])$item['marketplaceStatusText']='Kısmi sevkiyat';
        if($item['fulfillmentStatus']!==$part['fulfillmentStatus']){
            $item['fulfillmentStatus']='mixed';
            $item['deliveryStatus']=DeliveryPlan::label('mixed');
        }
        $item['selectionRecords']=[...$item['selectionRecords'],...$part['selectionRecords']];
        $item['products']=[...$item['products'],...$part['products']];
        $item['issues']=array_values(array_unique([...$item['issues'],...$part['issues']]));
        if(($item['address']??null)!==($part['address']??null))$item['multipleAddresses']=true;
        return $item;
    }
}
