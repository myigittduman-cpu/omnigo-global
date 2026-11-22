<?php
declare(strict_types=1);
namespace Omnigo;

/** Search can omit coupons; detail can exclude them from discounts. Only enrich matching amounts. */
final class PttavmCouponDetail
{
    public static function signature(array $order): string
    {
        $lines=[];
        foreach($order['products']??[] as $line){
            $p=$line['pricing']??[];
            $lines[(string)($line['lineId']??'')]=[$line['quantity']??null,$line['grossCents']??null,
                $p['originalCents']??null,$p['discountCents']??null,$p['platformDiscountCents']??null,$p['sellerDiscountCents']??null];
        }
        ksort($lines);
        return hash('sha256',json_encode([$order['externalId']??null,$order['grossCents']??null,$lines],JSON_THROW_ON_ERROR));
    }
    public static function preserve(array $fresh,array $previous): array
    {
        if(self::signature($fresh)!==self::signature($previous))return $fresh;
        foreach(['pricingDetailVerified','pricingDetailRetryAt','pricingDetailAttempts','pricingDetailError'] as $key)
            if(isset($previous[$key]))$fresh[$key]=$previous[$key];
        if(!empty($previous['pricingDetailVerified'])){
            $lines=array_column($previous['products'],'pricing','lineId');
            foreach($fresh['products'] as &$line)$line['pricing']=$lines[$line['lineId']];
        }
        return $fresh;
    }
    public static function apply(array $order,array $raw): array
    {
        if(($raw['siparisNo']??null)!==($order['externalId']??null))throw new SyncFailure(422);
        $source=[];
        foreach($raw['siparisUrunler']??[] as $line){
            $id=(string)($line['lineItemId']??'');
            if($id===''||isset($source[$id]))throw new SyncFailure(422);
            $source[$id]=$line;
        }
        if(count($source)!==count($order['products']??[]))throw new SyncFailure(422);
        foreach($order['products'] as &$line){
            $detail=$source[$line['lineId']]??null;$p=$line['pricing']??[];
            if(!$detail||($detail['toplamIslemAdedi']??null)!==$line['quantity'])throw new SyncFailure(422);
            $values=[];
            foreach(['kdvDahilToplamTutar','indirimToplam','indirimPttavm','indirimTedarikci','couponAmount'] as $key)
                $values[$key]=KoctasOrderMapper::cents($detail[$key]??null);
            ['kdvDahilToplamTutar'=>$original,'indirimToplam'=>$discount,'indirimPttavm'=>$platform,'indirimTedarikci'=>$seller,'couponAmount'=>$coupon]=$values;
            if($original!==($p['originalCents']??null)||$platform+$seller!==$discount||$seller!==($p['sellerDiscountCents']??null))throw new SyncFailure(422);
            $included=$discount===($p['discountCents']??null)&&$platform===($p['platformDiscountCents']??null);
            $excluded=$discount+$coupon===($p['discountCents']??null)&&$platform+$coupon===($p['platformDiscountCents']??null);
            if((!$included&&!$excluded)||$coupon>($p['platformDiscountCents']??0))throw new SyncFailure(422);
            $line['pricing']=[...$p,'couponCents'=>$coupon,'invoiceBasis'=>'ptt_coupon_included'];
        }
        $order['pricingDetailVerified']=true;
        unset($order['pricingDetailRetryAt'],$order['pricingDetailAttempts'],$order['pricingDetailError']);
        return $order;
    }
}
