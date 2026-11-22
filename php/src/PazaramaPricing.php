<?php
declare(strict_types=1);
namespace Omnigo;

/** The API invoice amount excludes the seller coupon, but retains the platform subsidy. */
final class PazaramaPricing
{
    public static function fromLine(array $line,int $invoiceCents): ?array
    {
        try {
            $quantity=$line['quantity']??null;$description=$line['discountDescription']??null;
            if(!is_int($quantity)||$quantity<1||!is_string($description))return null;
            $original=PazaramaSalesMapper::money($line['salePrice']??null)*$quantity;
            $seller=PazaramaSalesMapper::money($line['discountAmount']??null);
            if($original-$seller!==$invoiceCents)return null;
            $platform=0;
            // The live API provides the platform-funded amount only in this labelled suffix.
            // Never read a coupon threshold or an arbitrary number from the description.
            if(str_contains($description,'Pazarama')){
                if(substr_count($description,'Pazarama')!==1||!preg_match('/\(Pazarama İndirimi - ([0-9]{1,10}(?:\.[0-9]{1,2})?) TL\)\s*$/uD',$description,$match))return null;
                $platform=KoctasOrderMapper::cents($match[1]);
            }
            if($platform>$invoiceCents)return null;
            return ['originalCents'=>$original,'platformDiscountCents'=>$platform,'platformDiscountReported'=>isset($match[1]),'sellerDiscountCents'=>$seller,
                'discountedCents'=>$invoiceCents-$platform,'reportCents'=>$invoiceCents,'reportBasis'=>'seller_invoice'];
        }catch(SyncFailure){return null;}
    }
}
