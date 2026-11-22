<?php
declare(strict_types=1);
namespace Omnigo;

/** Merchandise amounts at order time, in TRY cents. Neither a catalog quote nor a receivable. */
final class ProfitabilityOrderPricing
{
    public const UNKNOWN = ['storePriceCents'=>null, 'buyerPaidCents'=>null, 'sellerDiscountCents'=>null, 'platformDiscountCents'=>null];
    private const MAX_CENTS = 9007199254740991;

    public static function sum(array $lines): array
    {
        if (!$lines) return self::UNKNOWN;
        $total = array_fill_keys(array_keys(self::UNKNOWN), 0);
        foreach ($lines as $line) {
            foreach ($total as $key => $amount) {
                $value = is_array($line) ? $line[$key] ?? null : null;
                $total[$key] = $amount !== null && is_int($value) && $value >= 0 && $value <= self::MAX_CENTS - $amount
                    ? $amount + $value : null;
            }
        }
        return $total;
    }

    public static function fingerprint(array $lines): string
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = json_encode([(string)($line['lineId'] ?? ''), (string)($line['sku'] ?? ''), (string)($line['title'] ?? ''),
                $line['quantity'], $line['netQuantity'], $line['grossCents'], $line['cancelCents'], $line['returnCents']], JSON_THROW_ON_ERROR);
        }
        sort($ids, SORT_STRING);
        return hash('sha256', implode("\n", $ids));
    }

    public static function fromOrder(string $provider, array $lines): array
    {
        $parts = [];
        foreach ($lines as $line) {
            // A partly reversed line needs the actual amount of each surviving unit.
            // Ratios cannot allocate a coupon across units that cost different amounts.
            if ($line['netQuantity'] !== $line['quantity'] || $line['cancelCents'] !== 0 || $line['returnCents'] !== 0) {
                return self::UNKNOWN;
            }
            $parts[] = self::fromLine($provider, $line);
        }
        return self::sum($parts);
    }

    private static function fromLine(string $provider, array $line): array
    {
        $gross = $line['grossCents'];
        if (in_array($provider, ['hepsiburada','koctas'], true)) {
            return self::sum([$line['orderPricing'] ?? null]);
        }
        if (!in_array($provider, ['idefix','pazarama','pttavm','trendyol'], true)) return self::UNKNOWN;
        $p = $line['pricing'] ?? [];
        if (!empty($p['pricingCurrencyMismatch'])) return self::UNKNOWN;
        $basis = $provider === 'pazarama' ? 'seller_invoice' : 'discounted';
        $result = self::UNKNOWN;
        // idefix explicitly stores discountedTotalPrice. Trendyol stores lineItemPrice per unit.
        if (in_array($provider, ['idefix','trendyol'], true)) $result['buyerPaidCents'] = $gross;
        if (($p['reportBasis'] ?? null) !== $basis || ($p['reportCents'] ?? null) !== $gross) return $result;
        $original = $p['originalCents'] ?? null;
        $seller = $p['sellerDiscountCents'] ?? null;
        $platform = $p['platformDiscountCents'] ?? null;
        $buyer = $p['discountedCents'] ?? null;
        if ($provider === 'pttavm' && is_int($original) && is_int($p['discountCents'] ?? null)
            && $p['discountCents'] >= 0 && $original - $p['discountCents'] === $gross) {
            $result['storePriceCents'] = $original;
            if($p['discountCents']>0||!empty($p['discountReported']))$result['buyerPaidCents'] = $gross;
        }
        if (!is_int($seller) || !is_int($platform) || !is_int($original) || !is_int($buyer)
            || min($seller,$platform,$original,$buyer) < 0 || $original - $seller - $platform !== $buyer
            || ($basis === 'seller_invoice' ? $original - $seller : $buyer) !== $gross) return $result;
        if ($provider === 'pazarama' && $platform === 0 && empty($p['platformDiscountReported'])) {
            // Older order snapshots assigned zero when the platform coupon suffix was absent.
            return [...$result,'storePriceCents'=>$original,'sellerDiscountCents'=>$seller];
        }
        if ($provider === 'trendyol') {
            // Historical projections used 0 for an omitted discount. Only an explicitly
            // confirmed gross or both known funding amounts establishes the store price.
            $originalProof = $p['sourceGrossCents'] ?? null;
            $sellerKnown = $seller > 0 || !empty($p['sellerDiscountReported']);
            $platformKnown = $platform > 0 || !empty($p['platformDiscountReported']);
            if ($originalProof !== null && (!is_int($originalProof) || $originalProof < $original
                || ($sellerKnown && $platformKnown && $originalProof !== $original))) return $result;
            return ['storePriceCents'=>$originalProof ?? ($sellerKnown && $platformKnown ? $original : null),
                'buyerPaidCents'=>$buyer, 'sellerDiscountCents'=>$sellerKnown ? $seller : null,
                'platformDiscountCents'=>$platformKnown ? $platform : null];
        }
        return ['storePriceCents'=>$original,'buyerPaidCents'=>$buyer,'sellerDiscountCents'=>$seller,'platformDiscountCents'=>$platform];
    }

    public static function merge(array $projection, array $observed): array
    {
        $result = self::UNKNOWN;
        foreach ($result as $key => $_) {
            $a = $projection[$key] ?? null;
            $b = $observed[$key] ?? null;
            $result[$key] = $a === null || $b === null || $a === $b ? $a ?? $b : null;
        }
        return self::sum([$result]);
    }

    /** Only a unique canonical line can be paired with a single source quote. */
    public static function recordLine(array &$prices, array $line, array $quote): void
    {
        $key = self::fingerprint([$line]);
        $prices[$key] = array_key_exists($key, $prices) ? null : $quote;
    }

    public static function hasLineEvidence(array $quotes): bool
    {
        foreach($quotes as $quote)if(is_array($quote)&&self::sum([$quote])!==self::UNKNOWN)return true;
        return false;
    }
}
