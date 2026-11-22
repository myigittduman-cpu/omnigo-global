<?php
declare(strict_types=1);
namespace Omnigo;

/** Source turnover and sales after cancellations. Invoice/refund accounting stays separate. */
final class TurnoverAmounts
{
    public const ZERO=['turnoverCents'=>0,'cancelledTurnoverCents'=>0,'salesTurnoverCents'=>0];
    public static function activeOrders(array $order): int
    {
        $gross=$order['grossCents']??0;
        if(in_array(strtoupper($order['state']??''),['CANCELED','CANCELLED','REFUSED'],true)
            ||($gross>0&&($order['cancelCents']??0)>=$gross))return 0;
        return $order['orders']??0;
    }
    public static function order(string $provider,array $order):array
    {
        $amount=$order['grossCents']??null;$cancelled=$order['cancelCents']??0;
        if($provider==='pttavm'){
            $amount=empty($order['products'])?null:0;$cancelled=$amount;
            foreach($order['products']??[] as $product){
                $original=$product['pricing']['originalCents']??null;
                $lineCancelled=self::lineCancellation($product,$original);
                if(!is_int($original)||$original<0){$amount=null;$cancelled=null;break;}
                $amount+=$original;$cancelled=$cancelled===null||$lineCancelled===null?null:$cancelled+$lineCancelled;
            }
        }
        $amount=is_int($amount)&&$amount>=0?$amount:null;
        $cancelled=is_int($cancelled)&&$cancelled>=0&&$amount!==null&&$cancelled<=$amount?$cancelled:null;
        return ['turnoverCents'=>$amount,'cancelledTurnoverCents'=>$cancelled,'salesTurnoverCents'=>$amount===null||$cancelled===null?null:$amount-$cancelled];
    }
    private static function lineCancellation(array $product,mixed $original):?int
    {
        if(!is_int($original)||$original<0)return null;
        $quantity=$product['quantity']??null;$cancelled=$product['cancelledQuantity']??null;
        if(is_int($quantity)&&$quantity>0&&is_int($cancelled)&&$cancelled>=0&&$cancelled<=$quantity)return (int)round($original*$cancelled/$quantity);
        $gross=$product['grossCents']??null;$cancel=$product['cancelCents']??0;
        if($cancel===0)return 0;
        return is_int($gross)&&$gross>0&&$cancel===$gross?$original:null;
    }
    public static function add(array &$total,array $value):void
    {
        foreach(array_keys(self::ZERO) as $field)$total[$field]=($total[$field]??null)===null||($value[$field]??null)===null?null:$total[$field]+$value[$field];
    }
    public static function dashboard(array $result,bool $customerView=false):array
    {
        $channels=array_column($result['marketplaceSales']['channels']??[],null,'channel');$total=self::ZERO;$salesOrders=0;
        foreach($result['sales'] as &$sale){
            $source=$channels[$sale['channel']]['periods']['month']??null;
            $amounts=$source??(!empty($sale['integration'])?[]:self::order('', $sale));
            foreach(array_keys(self::ZERO) as $field)$sale[$field]=$amounts[$field]??null;
            $sale['salesOrders']=$source['salesOrders']??$sale['orders'];
            $salesOrders+=$sale['salesOrders'];
            self::add($total,$sale);
        }unset($sale);
        foreach($result['salesToday']['channels'] as &$day){
            $source=$channels[$day['channel']]['periods']['today']??[];
            foreach(array_keys(self::ZERO) as $field)$day[$field]=$source[$field]??null;
            if($customerView&&array_key_exists('salesOrders',$source))$day['salesOrders']=$source['salesOrders'];
        }
        unset($day);
        $result['summary']=array_replace($result['summary'],$total,['salesOrders'=>$salesOrders]);$result['salesBasis']='marketplace_sales_excluding_cancellations';return $result;
    }
}
