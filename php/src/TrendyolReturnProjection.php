<?php
declare(strict_types=1);
namespace Omnigo;
/** Order return status only; does not claim a separate claim or bank refund. */
final class TrendyolReturnProjection
{
    public static function orders(array $orders): array
    {
        $items=[];
        foreach($orders as $order)foreach($order['products']??[] as $product){
            if(($product['shippingState']??'')!=='returned')continue;
            $items[]=['id'=>'order:'.$order['externalId'].':'.$product['lineId'],'orderNumber'=>$order['externalId'],'lineId'=>$product['lineId'],
                'status'=>'Returned','createdAt'=>null,'orderedAt'=>$order['createdAt'],'quantity'=>$product['quantity'],'amountCents'=>$product['returnCents'],'product'=>$product['title'],'reason'=>''];
        }
        return $items;
    }
}
