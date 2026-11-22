<?php
declare(strict_types=1);
namespace Omnigo;

final class SalesUnitTotals
{
    public static function quantity(array $order,string $field): ?int
    {
        if (!isset($order['products']) || !$order['products']) return null;
        $sum=0;
        foreach ($order['products'] as $product) {
            if (!is_int($product[$field]??null)) return null;
            $sum+=$product[$field];
        }
        return $sum;
    }

    public static function add(?int $total,?int $units): ?int
    {
        return $total===null||$units===null?null:$total+$units;
    }

    public static function activeLine(array $line):?int
    {
        $qty=$line['quantity']??null;
        if(!is_int($qty)||$qty<0)return null;
        $cancelled=$line['cancelledQuantity']??null;
        if(is_int($cancelled))return $cancelled>=0&&$cancelled<=$qty?$qty-$cancelled:null;
        $cancel=$line['cancelCents']??null;
        if($cancel===0)return $qty;
        if(!is_int($cancel)||$cancel<0)return null;
        if($cancel===($line['grossCents']??null))return 0;
        $active=$line['netQuantity']??null;
        return ($line['returnCents']??null)===0&&is_int($active)&&$active>=0&&$active<=$qty?$active:null;
    }

    public static function activeOrder(array $order):?int
    {
        if(empty($order['products']))return null;
        $sum=0;
        foreach($order['products'] as $line)$sum=self::add($sum,self::activeLine($line));
        return $sum;
    }
}
