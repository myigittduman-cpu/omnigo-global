<?php
declare(strict_types=1);
namespace Omnigo;

final class ProfitabilityMarketplacePricing
{
    /** Use explicit merchant, shopper and discount amounts only; never infer the funding party from a difference. */
    public static function hepsiburada(array $line): array
    {
        $quantity = $line['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 100000) return ProfitabilityOrderPricing::UNKNOWN;
        $buyer = self::hbLineTotal($line,'totalPrice','unitPrice',$quantity);
        if ($buyer === null) return ProfitabilityOrderPricing::UNKNOWN;
        $store = self::hbLineTotal($line, 'merchantTotalPrice', 'merchantUnitPrice', $quantity);
        $seller = self::hbDiscount($line,'merchantDiscount','totalMerchantDiscount','unitMerchantDiscount',$quantity);
        $platform = self::hbDiscount($line,'hbDiscount','totalHBDiscount','unitHBDiscount',$quantity);
        // In the order endpoint totalPrice is the discounted merchandise price. If no
        // merchant price is provided, disclose the reconstruction from both named
        // discount components. Never substitute deptorDifferenceAmount for either one.
        if(!isset($line['merchantTotalPrice'])&&!isset($line['merchantUnitPrice'])
            &&isset($line['merchantDiscount'],$line['hbDiscount'],$line['totalPrice'],$line['unitPrice'])
            &&$seller!==null&&$platform!==null&&$seller<=9007199254740991-$buyer-$platform){
            return ['storePriceCents'=>$buyer+$seller+$platform,'buyerPaidCents'=>$buyer,
                'sellerDiscountCents'=>$seller,'platformDiscountCents'=>$platform,'storePriceBasis'=>'buyer_plus_discounts'];
        }
        return self::hbMerchandise($store, $buyer, $seller, $platform);
    }

    private static function hbDiscount(array $line,string $nested,string $total,string $unit,int $quantity): ?int
    {
        $flat=self::hbLineTotal($line,$total,$unit,$quantity);
        if(!array_key_exists($nested,$line))return $flat;
        if(!is_array($line[$nested]))return null;
        $value=self::hbLineTotal($line[$nested],'totalPrice','unitPrice',$quantity);
        $hasFlat=isset($line[$total])||isset($line[$unit])||isset($line[str_replace('MerchantDiscount','merchantDiscount',$total)])
            ||isset($line[str_replace('MerchantDiscount','merchantDiscount',$unit)]);
        return !$hasFlat||$flat===$value?$value:null;
    }

    public static function hbMerchandise(?int $store, int $lineTotal, ?int $seller, ?int $platform): array
    {
        // The public order contract identifies totalPrice as an order-line total. Only label it as
        // the shopper's merchandise amount if the explicitly reported discounts reconcile to it.
        $buyer = $store !== null && $seller !== null && $platform !== null && $store - $seller - $platform === $lineTotal
            ? $lineTotal : null;
        return ['storePriceCents'=>$store,'buyerPaidCents'=>$buyer,'sellerDiscountCents'=>$seller,'platformDiscountCents'=>$platform];
    }

    private static function hbLineTotal(array $source, string $totalKey, string $unitKey, int $quantity): ?int
    {
        // Different HB endpoints spell MerchantDiscount in two ways.
        $totalValue = $source[$totalKey] ?? $source[str_replace('MerchantDiscount','merchantDiscount',$totalKey)] ?? null;
        $unitValue = $source[$unitKey] ?? $source[str_replace('MerchantDiscount','merchantDiscount',$unitKey)] ?? null;
        $total = self::hbCents($totalValue,1);
        $extended = self::hbCents($unitValue,$quantity);
        if (($totalValue !== null && $total === null) || ($unitValue !== null && $extended === null)) return null;
        if ($total !== null && $extended !== null && $extended !== $total) return null;
        return $total ?? $extended;
    }

    private static function hbCents(mixed $value,int $quantity): ?int
    {
        if (!is_array($value) || ($value['currency'] ?? null) !== 'TRY') return null;
        $raw=$value['amount']??null;
        if((!is_int($raw)&&!is_float($raw)&&!is_string($raw))||!preg_match('/^(\d{1,10})(?:\.(\d{1,8}))?$/D',(string)$raw,$parts))return null;
        // HB may send unit discounts with more than two decimals. Extend first, round once.
        $minor=(int)$parts[1]*100000000+(int)str_pad($parts[2]??'',8,'0');
        if($minor>intdiv(PHP_INT_MAX-500000,$quantity))return null;
        return intdiv($minor*$quantity+500000,1000000);
    }

    public static function n11(array $line): array
    {
        $quantity = $line['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 100000
            || !in_array($line['currency'] ?? $line['currencyCode'] ?? 'TRY', ['TL','TRY'], true)) return ProfitabilityOrderPricing::UNKNOWN;
        $unit = self::nonnegative($line['price'] ?? null);
        $store = $unit !== null && $unit <= intdiv(9007199254740991,$quantity) ? $unit * $quantity : null;
        $seller = self::nonnegative($line['totalSellerDiscountPrice'] ?? null);
        $platform = self::nonnegative($line['totalMallDiscountPrice'] ?? null);
        $due = self::nonnegative($line['dueAmount'] ?? null);
        // The amount to collect may include a separately billed instalment charge.
        $buyer = $due !== null && $store !== null && $seller !== null && $platform !== null
            && $store - $seller - $platform === $due ? $due : null;
        return ['storePriceCents'=>$store,'buyerPaidCents'=>$buyer,'sellerDiscountCents'=>$seller,'platformDiscountCents'=>$platform];
    }

    public static function n11CommissionDiscount(array $line): ?int
    {
        if(!in_array($line['currency']??$line['currencyCode']??'TRY',['TRY','TL'],true))return null;
        $discount=self::nonnegative($line['sellerCampaignCommissionDiscount']??null);
        $quantity=$line['quantity']??null;
        if(!is_int($quantity)||$quantity<1||$quantity>100000)return null;
        return $quantity===1||$discount===0?$discount:null;
    }

    /** Returns false for conflicting representations across package pages. */
    public static function collectN11Quote(array &$quotes,string $lineId,array $quote): bool
    {
        if(isset($quotes[$lineId]))return $quotes[$lineId]===$quote;
        $quotes[$lineId]=$quote;
        return true;
    }

    public static function koctas(array $raw): array
    {
        $quantity=$raw['quantity']??null;$price=self::nonnegative($raw['price']??null);
        if(!is_int($quantity)||$quantity<1||$quantity>100000||$price===null)return ProfitabilityOrderPricing::UNKNOWN;
        // OR11 supplies original and current offer prices per unit. Promotions are already
        // allocated to this line. The product price excludes shipping, fees and commissions.
        $unit=self::nonnegative($raw['origin_unit_price']??null);
        if(!isset($raw['origin_unit_price'])){
            $quoted=self::nonnegative($raw['price_unit']??null);
            $promotions=self::koctasPromotionTotal($raw['promotions']??null);
            if($quoted!==null && $quoted<=intdiv(9007199254740991,$quantity) && $promotions!==null
                && $quoted*$quantity-$price===$promotions)$unit=$quoted;
        }
        $store=$unit!==null&&$unit<=intdiv(9007199254740991,$quantity)?$unit*$quantity:null;
        $result=[...ProfitabilityOrderPricing::UNKNOWN,'storePriceCents'=>$store];
        $parts=$raw['price_amount_breakdown']['parts']??null;
        if(!is_array($parts)||!$parts||!array_is_list($parts))return $result;
        $total=0;$buyer=0;
        foreach($parts as $part){
            $amount=self::nonnegative($part['amount']??null);$debitable=$part['debitable_from_customer']??null;
            if($amount===null||!is_bool($debitable)||$amount>$price-$total)return $result;
            $total+=$amount;if($debitable)$buyer+=$amount;
        }
        if($total===$price)$result['buyerPaidCents']=$buyer;
        return $result;
    }

    private static function koctasPromotionTotal(mixed $promotions): ?int
    {
        if (!is_array($promotions)||!array_is_list($promotions)) return null;
        $total=0;
        $seen=[];
        foreach ($promotions as $promotion) {
            $id=$promotion['id']??null;
            $amount=self::nonnegative($promotion['deduced_amount']??null);
            if (!is_string($id)||$id===''||isset($seen[$id])||$amount===null||$amount>9007199254740991-$total) return null;
            $seen[$id]=true;
            $total+=$amount;
        }
        return $total;
    }

    public static function idefixCommission(array $raw,array $product): ?int
    {
        $commission=self::nonnegative($raw['commissionAmount']??null);
        $earnings=self::nonnegative($raw['earningAmount']??null);
        if($commission===null||$earnings===null)return null;
        $sum=$commission+$earnings;
        if($sum===$product['grossCents'])return $commission;
        $p=ProfitabilityOrderPricing::fromOrder('idefix',[$product]);
        if($p['storePriceCents']!==null&&$p['sellerDiscountCents']!==null
            &&$sum===$p['storePriceCents']-$p['sellerDiscountCents'])return $commission;
        return null;
    }

    private static function nonnegative(mixed $value): ?int
    {
        $amount = ProfitabilityFinancialEvidence::cents($value);
        return $amount !== null && $amount >= 0 ? $amount : null;
    }
}
