<?php
declare(strict_types=1);
namespace Omnigo;

/** Delivery documents describe the units still belonging to this shipment line. */
final class ShipmentExportLine
{
    public static function project(array $item,array $sourceLines): ?array
    {
        $cancel=$item['marketplaceCancellation']??[];
        $return=$item['marketplaceReturn']??[];
        if(OperationalCancellationMark::shipmentMark($item,$cancel)==='cancelled'
            ||in_array($item['fulfillmentStatus']??'',['returned','cancelled'],true))return null;
        $cancelled=max(0,(int)($cancel['cancelledQuantity']??0));
        $returned=max(0,(int)($return['returnedQuantity']??0));
        if(ShipmentFulfillment::fullyReturned($return))return null;
        $original=(int)($cancel['quantity']??$return['quantity']??$item['quantity']??0);
        foreach($sourceLines as $line){
            if(($line['shippingState']??'')==='cancelled')return null;
            $cancelled=max($cancelled,(int)($line['cancelledQuantity']??0));
            $returned=max($returned,(int)($line['refundedQuantity']??0));
            $original=max($original,(int)($line['quantity']??0));
        }
        if(!$cancelled&&!$returned)return $item;
        $remaining=min((int)($item['quantity']??$original),$original-$cancelled-$returned);
        if(isset($return['remainingQuantity']))$remaining=min($remaining,(int)$return['remainingQuantity']);
        if($remaining<1)return null;
        $item['quantity']=$remaining;
        // The stored invoice may refer to the complete line, which can include different discounts.
        $item['invoiceCents']=null;
        unset($item['marketplaceCancellation'],$item['marketplaceReturn']);
        return $item;
    }
}
