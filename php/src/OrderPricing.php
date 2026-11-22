<?php
declare(strict_types=1);
namespace Omnigo;

/** Optional explanatory metadata. Never changes the authoritative sales amounts. */
final class OrderPricing
{
    public static function idefix(array $item,int $reportedCents): ?array
    {
        try {
            foreach(['price','discountedTotalPrice','platformDiscount','vendorDiscount'] as $key)
                if(!array_key_exists($key,$item)||$item[$key]===null)return null;
            $original=KoctasOrderMapper::cents($item['price']);
            $discounted=KoctasOrderMapper::cents($item['discountedTotalPrice']);
            $platform=KoctasOrderMapper::cents($item['platformDiscount']);
            $seller=KoctasOrderMapper::cents($item['vendorDiscount']);
            if($original-$platform-$seller!==$discounted||$discounted!==$reportedCents)return null;
            if(isset($item['totalDiscount'])&&KoctasOrderMapper::cents($item['totalDiscount'])!==$platform+$seller)return null;
            return ['originalCents'=>$original,'platformDiscountCents'=>$platform,'sellerDiscountCents'=>$seller,
                'discountedCents'=>$discounted,'reportCents'=>$reportedCents,'reportBasis'=>'discounted'];
        } catch(SyncFailure) { return null; }
    }
}
