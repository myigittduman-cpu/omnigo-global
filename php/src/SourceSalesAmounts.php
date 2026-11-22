<?php
declare(strict_types=1);
namespace Omnigo;

/** Panel comparisons are additive metadata; invoice, cancellation and refund accounting stays intact. */
final class SourceSalesAmounts
{
    public const ZERO=['panelSalesCents'=>0,'platformDiscountCents'=>0,'sellerDiscountCents'=>0];
    public static function order(string $provider,array $order): array
    {
        if(!in_array($provider,['idefix','pazarama'],true))return [...self::ZERO,'panelSalesCents'=>$order['grossCents']-$order['cancelCents']];
        if(empty($order['products']))return array_fill_keys(array_keys(self::ZERO),null);
        $total=self::ZERO;
        foreach($order['products'] as $product){
            if($product['cancelCents']===$product['grossCents'])continue;
            $p=$product['pricing']??null;
            if(!is_array($p)||$product['cancelCents']!==0||($p['reportCents']??null)!==$product['grossCents']
                ||($p['reportBasis']??null)!==($provider==='idefix'?'discounted':'seller_invoice'))return array_fill_keys(array_keys(self::ZERO),null);
            foreach(['originalCents','discountedCents','sellerDiscountCents','platformDiscountCents'] as $key)if(!is_int($p[$key]??null)||$p[$key]<0)return array_fill_keys(array_keys(self::ZERO),null);
            if($p['originalCents']-$p['sellerDiscountCents']-$p['platformDiscountCents']!==$p['discountedCents']
                ||$p['reportCents']!==($provider==='idefix'?$p['discountedCents']:$p['originalCents']-$p['sellerDiscountCents']))return array_fill_keys(array_keys(self::ZERO),null);
            $total['panelSalesCents']+=$provider==='idefix'?$p['originalCents']:$p['discountedCents'];
            $total['platformDiscountCents']+=$p['platformDiscountCents'];$total['sellerDiscountCents']+=$p['sellerDiscountCents'];
        }
        return $total;
    }
    public static function add(array &$total,array $amounts): void
    {
        foreach(self::ZERO as $key=>$zero)$total[$key]=($total[$key]??null)===null||($amounts[$key]??null)===null?null:$total[$key]+$amounts[$key];
    }
}
