<?php
declare(strict_types=1);
namespace Omnigo;

/** Per-unit pricing preview. Currency is TRY, amounts are integer kuruş. */
final class InventoryChannelPricePreview
{
    private const SCALE = 10000;
    private const AMOUNT_LIMIT = 10000000000;

    public static function quote(int $costNetCents, array $rule): array
    {
        $cost = self::integer($costNetCents, self::AMOUNT_LIMIT);
        $shipping = self::integer($rule['shippingNetCents'] ?? null, self::AMOUNT_LIMIT);
        $fees = self::integer($rule['otherNetCents'] ?? null, self::AMOUNT_LIMIT);
        $vat = self::integer($rule['salesVatBasisPoints'] ?? null, self::SCALE);
        $commission = self::integer($rule['effectiveCommissionBasisPoints'] ?? null, self::SCALE);
        $margin = self::integer($rule['targetNetMarginBasisPoints'] ?? null, self::SCALE);
        $step = self::integer($rule['roundUpCents'] ?? 1, 100000);
        $base = self::SCALE;
        $vatFactor = $base + $vat;
        $headroom = ($base - $margin) * $base - $commission * $vatFactor;
        if ($headroom <= 0 || $step === 0) {
            throw new \DomainException('Kesintiler ve hedef kârla geçerli bir satış fiyatı hesaplanamadı.');
        }

        $fixed = $cost + $shipping + $fees;
        $minimum = max(1, self::ceilDivide($fixed * $vatFactor * $base, $headroom));
        $gross = self::ceilDivide($minimum, $step) * $step;
        $quote = self::project($gross, $fixed, $vatFactor, $commission);
        if ($quote['projectedNetProfitCents'] * $base < $margin * $quote['netRevenueCents']) {
            // Cover the rounding of both revenue and commission at the requested margin.
            $minimum = self::ceilDivide(($fixed + 1) * $vatFactor * $base, $headroom);
            $quote = self::project(self::ceilDivide($minimum, $step) * $step, $fixed, $vatFactor, $commission);
        }
        return $quote;
    }

    private static function project(int $gross, int $fixed, int $vatFactor, int $commission): array
    {
        if ($gross > self::AMOUNT_LIMIT) {
            throw new \DomainException('Önerilen satış fiyatı desteklenen sınırı aşıyor.');
        }
        $net = intdiv($gross * self::SCALE + intdiv($vatFactor, 2), $vatFactor);
        $commissionCents = ProfitabilityCommission::amount($gross, $commission);
        $profit = $net - $fixed - $commissionCents;
        return ['priceGrossCents'=>$gross, 'netRevenueCents'=>$net, 'commissionCostCents'=>$commissionCents,
            'fixedNetCostsCents'=>$fixed, 'projectedNetProfitCents'=>$profit,
            'projectedNetMarginBasisPoints'=>ProfitabilityCommission::share($profit, $net)];
    }

    private static function integer(mixed $value, int $limit): int
    {
        if (!is_int($value) || $value < 0 || $value > $limit) {
            throw new \InvalidArgumentException('Kanal fiyatı için geçerli maliyet, KDV, komisyon ve hedef kâr bilgileri gerekli.');
        }
        return $value;
    }

    private static function ceilDivide(int $value, int $by): int
    {
        return intdiv($value, $by) + (int)($value % $by !== 0);
    }
}
