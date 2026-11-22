<?php
declare(strict_types=1);
namespace Omnigo;

/** PTT includes the coupon in indirimToplam / indirimPttavm. Never deduct it twice. */
final class PttavmPricing
{
    public static function line(array $line,int $original,int $discount): array
    {
        $gross=$original-$discount;
        $base=['originalCents'=>$original,'discountCents'=>$discount,'discountReported'=>isset($line['indirimToplam']),
            'reportCents'=>$gross,'reportBasis'=>'discounted'];
        // Older sources can lack the split. Preserve totals without inventing a breakdown.
        foreach(['indirimPttavm','indirimTedarikci','couponAmount'] as $key)if(!isset($line[$key]))return $base;
        try{
            $platform=KoctasOrderMapper::cents($line['indirimPttavm']);
            $seller=KoctasOrderMapper::cents($line['indirimTedarikci']);
            $coupon=KoctasOrderMapper::cents($line['couponAmount']);
        }catch(SyncFailure){return $base;}
        if($platform+$seller!==$discount||$coupon>$platform)return $base;
        return [...$base,'platformDiscountCents'=>$platform,'sellerDiscountCents'=>$seller,
            'couponCents'=>$coupon,'discountedCents'=>$gross,'invoiceBasis'=>'ptt_coupon_pending'];
    }
    /** Order-line return value is known even when buyer bank-refund confirmation is unavailable. */
    public static function returnAmountVerified(array $order): bool
    {
        if(($order['provider']??'')!=='PTT AVM'||empty($order['products']))return false;
        $sum=0;
        foreach($order['products'] as $line){
            $status=$line['status']??null;
            if(!is_string($status)||$status==='')return false;
            try{PttavmOrderMapper::shipping($status);}catch(SyncFailure){return false;}
            $gross=$line['grossCents']??null;$returned=$line['returnCents']??null;
            if(!is_int($gross)||$gross<0||!is_int($returned))return false;
            $expected=in_array($status,['iade','gondericisine_teslim_edildi'],true)?$gross:0;
            if($returned!==$expected)return false;
            $sum+=$returned;
        }
        return $sum===($order['returnCents']??null);
    }
}
