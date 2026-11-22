<?php
declare(strict_types=1);
namespace Omnigo;

/** Resolve invoices written before the buyer's product amount was explicitly stored. */
final class PazaramaShipmentInvoice
{
    public static function protectedRow(array $row,array $payload): bool
    {
        return self::keepRecordedAmount($row,$payload)||!empty($payload['marketplaceReturn']['returnedQuantity']);
    }

    /** Return synchronization protects writes, but does not verify an old seller-side amount. */
    public static function keepRecordedAmount(array $row,array $payload): bool
    {
        return empty($row['source_key']) || !empty($row['cancelled']) || ($row['row_status']??'')==='cancelled'
            || !empty($payload['marketplaceCancellation']['cancelledQuantity'])
            || in_array(ShipmentSourceStatus::value($payload), ['cancelled','undelivered'], true)
            || in_array('invoiceCents',$payload['source']['locked']??[],true);
    }

    public static function read(array $row,array $payload,?int $verified=null): ?int
    {
        if(self::keepRecordedAmount($row,$payload) || ($payload['invoiceBasis']??null)==='buyer_product'){
            return $payload['invoiceCents']??null;
        }
        if($verified!==null)return $verified;
        // Older shipment breakdowns kept the subsidy amount but discarded its presence flag.
        // A positive amount still proves the subsidy; a recorded zero needs the source snapshot.
        $amounts=$payload['marketplaceAmounts']??[];
        $original=$amounts['productCents']??null;$seller=$amounts['sellerDiscountCents']??null;
        $subsidy=$amounts['platformDiscountCents']??null;$paid=$amounts['netProductCents']??null;
        if(!is_int($original)||!is_int($seller)||!is_int($subsidy)||$subsidy<=0)return null;
        return PazaramaInvoiceAmount::fromPricing(['originalCents'=>$original,'sellerDiscountCents'=>$seller,
            'platformDiscountCents'=>$subsidy,'platformDiscountReported'=>true,'discountedCents'=>$paid,
            'reportCents'=>$original-$seller,'reportBasis'=>'seller_invoice'],$original-$seller);
    }
}
