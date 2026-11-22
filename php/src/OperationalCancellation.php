<?php
declare(strict_types=1);
namespace Omnigo;

/** List display only: never feed invoice totals, sales calculations or source snapshots. */
final class OperationalCancellation
{
    public static function summary(array $packages): ?array
    {
        $lines=[];$undelivered=false;
        foreach($packages as $package){
            foreach($package['cancellation']['lines']??[] as $id=>$line)if(!isset($lines[$id])||$line['cancelledQuantity']>$lines[$id]['cancelledQuantity'])$lines[$id]=$line;
            foreach($package['details']['products']??$package['products']??[] as $index=>$product){
                $id=(string)($product['lineId']??($package['externalId'].':'.$index));
                $quantity=max(0,(int)($product['quantity']??0));$cancelled=(int)($product['cancelledQuantity']??$lines[$id]['cancelledQuantity']??0);
                $state=$product['shippingState']??OrderShipping::state($package['provider']??'',$product['status']??'');
                if($state==='undelivered'){$cancelled=$quantity;$undelivered=true;}
                $line=['quantity'=>$quantity,'cancelledQuantity'=>min($quantity,$cancelled),'partial'=>$cancelled>0&&$cancelled<$quantity];
                if(!isset($lines[$id])||$line['cancelledQuantity']>=$lines[$id]['cancelledQuantity'])$lines[$id]=$line;
            }
        }
        return $undelivered?[...OrderCancellation::combine($lines),'operationalOnly'=>true]:null;
    }
}
