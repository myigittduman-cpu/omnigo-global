<?php
declare(strict_types=1);
namespace Omnigo;

/** Verified merchandise paid by the buyer for the whole Pazarama line, in kuruş. */
final class PazaramaInvoiceAmount
{
    public static function sellerTotal(array $line): ?int
    {
        try {
            return PazaramaSalesMapper::money($line['totalPrice'] ?? null);
        } catch (SyncFailure) {
            return null;
        }
    }

    public static function fromLine(array $line): ?int
    {
        $gross = self::sellerTotal($line);
        return self::fromPricing(self::pricing($line, $gross), $gross);
    }

    public static function pricing(array $line, ?int $gross): ?array
    {
        if ($gross === null) return null;
        $pricing = PazaramaPricing::fromLine($line, $gross);
        if ($pricing !== null && trim($line['discountDescription']) === '' && $pricing['sellerDiscountCents'] === 0) {
            // A present, empty campaign field and a reconciled undiscounted line establish zero.
            $pricing['platformDiscountReported'] = true;
        }
        return $pricing;
    }

    public static function fromPricing(?array $pricing, ?int $grossCents): ?int
    {
        if ($grossCents === null || ($pricing['reportBasis'] ?? null) !== 'seller_invoice'
            || ($pricing['reportCents'] ?? null) !== $grossCents || !empty($pricing['pricingCurrencyMismatch'])) return null;
        $original = $pricing['originalCents'] ?? null;
        $seller = $pricing['sellerDiscountCents'] ?? null;
        $platform = $pricing['platformDiscountCents'] ?? null;
        $buyer = $pricing['discountedCents'] ?? null;
        if (!is_int($original) || !is_int($seller) || !is_int($platform) || !is_int($buyer)
            || min($original, $seller, $platform, $buyer) < 0
            || $original - $seller !== $grossCents || $grossCents - $platform !== $buyer) return null;
        // Older snapshots also recorded zero for an absent platform discount.
        if (($pricing['platformDiscountReported'] ?? ($platform > 0)) !== true) return null;
        return $buyer;
    }

    /** An accepted multi-unit line alone does not establish how many units were refunded. */
    public static function returnedUnits(int $quantity,array $product,?array $financial,?array $approved): ?int
    {
        if($quantity<1||$quantity>100000)return null;
        $explicit=$product['refundedQuantity']??0;
        if(!is_int($explicit)||$explicit<0||$explicit>$quantity)return null;
        if($approved!==null){
            $count=$approved['returnedQuantity']??null;
            if(($approved['quantity']??null)!==$quantity||!is_int($count)||$count<0||$count>$quantity
                ||($explicit>0&&$count!==$explicit))return null;
            $explicit=max($explicit,$count);
        }
        if($explicit>0)return $explicit;
        $code=(string)($product['status']??'');
        if(in_array($code,['7','9'],true))return 0;
        $accepted=ApprovedReturnIndex::approved('Pazarama',['status'=>$code]);
        $refund=$financial['returnCents']??0;
        if($refund===0)return $accepted?($quantity===1?1:null):0;
        $gross=$financial['grossCents']??null;
        if(!is_int($refund)||!is_int($gross)||$refund<0||$refund>$gross||!empty($financial['cancelCents'])
            ||($financial['netCents']??($gross-$refund))!==$gross-$refund)return null;
        if($refund===$gross)return $quantity===1?1:null;
        $net=$financial['netQuantity']??null;
        if(!is_int($net)||$net<1||$net>=$quantity)return null;
        $units=$quantity-$net;
        return self::allocated($gross,$units,$quantity)===$refund?$units:null;
    }

    /** Round the refund share once in kuruş, retaining the remainder for the buyer's invoice. */
    public static function afterReturn(?int $full,int $quantity,?int $returned): ?int
    {
        if($full===null||$full<0||$quantity<1||$quantity>100000||$returned===null||$returned<0||$returned>$quantity)return null;
        return $full-self::allocated($full,$returned,$quantity);
    }

    private static function allocated(int $total,int $units,int $quantity): int
    {
        return intdiv($total,$quantity)*$units+intdiv(($total%$quantity)*$units+intdiv($quantity,2),$quantity);
    }
}
