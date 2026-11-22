<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit request facts only. Never changes cancellation amounts or shipment state. */
final class CancellationRequestEvents
{
    public static function lines(array $order): array
    {
        $ids=[];
        if(($order['provider']??'')==='Pazarama')foreach($order['details']['products']??[] as $line){
            if((string)($line['status']??'')==='18'&&!empty($line['lineId']))$ids[]=(string)$line['lineId'];
        }
        sort($ids);return array_values(array_unique($ids));
    }
    public static function advance(array $order,array $old): array
    {
        $current=self::lines($order);$seen=$old['cancellationRequestHistory']??self::lines($old);
        $new=array_values(array_diff($current,$seen));
        $seen=array_values(array_unique([...$seen,...$current]));sort($seen);
        return ['seen'=>$seen,'new'=>$new];
    }
}
