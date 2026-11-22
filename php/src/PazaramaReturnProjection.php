<?php
declare(strict_types=1);
namespace Omnigo;

/** Order-line return statuses, isolated from the financial and delivery mappings. */
final class PazaramaReturnProjection
{
    public static function append(array &$scan,array $orders): void
    {
        if(!empty($scan['returnProjectionInvalid']))return;
        try{
            foreach($orders as $order)foreach($order['items'] as $line){
                $status=$line['orderItemStatus'];if(!in_array($status,[7,8,9,10],true))continue;
                $orderId=PazaramaSalesMapper::id($order['orderId']);$lineId=PazaramaSalesMapper::id($line['orderItemId']);
                $scan['returnItems'][]=['id'=>$orderId.':'.$lineId,'orderNumber'=>$orderId,'lineId'=>$lineId,'status'=>(string)$status,
                    'createdAt'=>null,'quantity'=>null,'amountCents'=>null,'recordType'=>'order_line',
                    'product'=>ReturnRequestMapper::text($line['product']['name']??'',500),'reason'=>''];
                // The API's order date, quantity and price are not a return request date/amount.
                if(count($scan['returnItems'])>20000)throw new \RuntimeException('capacity');
            }
        }catch(\Throwable){$scan['returnProjectionInvalid']=true;unset($scan['returnItems']);}
    }
    public static function complete(array &$state): void
    {
        $scan=$state['scan']??[];if(($scan['returnVersion']??0)!==1)return;
        if(!empty($scan['returnProjectionInvalid']))$state['returnRequestError']=['at'=>gmdate('c'),'message'=>'İade satırları doğrulanamadı; son doğrulanan liste korunuyor.'];
        else ReturnRequestSnapshot::publish($state,$scan['returnItems']??[],$scan['from'],$scan['through']);
    }
}
