<?php
declare(strict_types=1);
namespace Omnigo;

/** Source order statuses only; no invented claim date or bank-refund confirmation. */
final class PttavmReturnProjection
{
    public static function orders(array $orders): array
    {
        $items=[];
        foreach($orders as $order)foreach($order['products']??[] as $line){
            if(!in_array($line['status']??'',['iade','gondericisine_teslim_edildi'],true))continue;
            $items[]=['id'=>'line:'.$order['externalId'].':'.$line['lineId'],'orderNumber'=>$order['externalId'],'lineId'=>$line['lineId'],
                'status'=>$line['status'],'createdAt'=>null,'orderedAt'=>$order['createdAt'],'recordType'=>'order_line',
                'quantity'=>$line['quantity'],'amountCents'=>$line['returnCents']??null,'product'=>$line['title'],'reason'=>''];
        }
        return $items;
    }
}
