<?php
declare(strict_types=1);
namespace Omnigo;

/** Optional line totals from the marketplace feed. The invoice amount is calculated separately. */
final class ShipmentPaymentAmounts
{
    private const FIELDS = ['productCents', 'sellerDiscountCents', 'platformDiscountCents', 'discountCents',
        'couponCents', 'shippingCents', 'installmentCents', 'merchantCents', 'buyerCents', 'netProductCents', 'totalCents'];

    public static function clean(array $amounts): array
    {
        return array_filter(array_intersect_key($amounts, array_flip(self::FIELDS)),
            static fn(mixed $amount): bool => is_int($amount) && $amount >= 0 && $amount < 1000000000000);
    }

    public static function pricing(?array $pricing): array
    {
        return self::clean([
            'productCents' => $pricing['originalCents'] ?? null,
            'sellerDiscountCents' => $pricing['sellerDiscountCents'] ?? null,
            'platformDiscountCents' => $pricing['platformDiscountCents'] ?? null,
            'discountCents' => $pricing['discountCents'] ?? null,
            'couponCents' => $pricing['couponCents'] ?? null,
            'netProductCents' => $pricing['discountedCents'] ?? (($pricing['reportBasis'] ?? null) === 'discounted' ? ($pricing['reportCents'] ?? null) : null),
        ]);
    }

    public static function n11(array $line): array
    {
        $unit = self::decimal($line['price'] ?? null);
        $quantity = $line['quantity'] ?? 0;
        return self::clean([
            'productCents' => $unit !== null && is_int($quantity) && $quantity > 0 ? $unit * $quantity : null,
            'sellerDiscountCents' => self::decimal($line['totalSellerDiscountPrice'] ?? null),
            'platformDiscountCents' => self::decimal($line['totalMallDiscountPrice'] ?? null),
            'buyerCents' => self::decimal($line['dueAmount'] ?? null),
            'installmentCents' => self::decimal($line['installmentChargeWithVAT'] ?? null),
        ]);
    }

    public static function hepsiburada(array $line): array
    {
        return self::clean([
            'productCents' => self::hepsiburadaMoney($line['totalPrice'] ?? null),
            'sellerDiscountCents' => self::hepsiburadaMoney($line['totalMerchantDiscount'] ?? $line['totalmerchantDiscount'] ?? null),
            'platformDiscountCents' => self::hepsiburadaMoney($line['totalHBDiscount'] ?? null),
            'merchantCents' => self::hepsiburadaMoney($line['merchantTotalPrice'] ?? null),
        ]);
    }

    public static function koctas(array $line): array
    {
        return self::clean([
            'productCents' => self::decimal($line['price'] ?? null),
            'shippingCents' => self::decimal($line['shipping_price'] ?? null),
            'totalCents' => self::decimal($line['total_price'] ?? null),
        ]);
    }

    public static function pazarama(array $line, ?int $sellerTotal): array
    {
        try {
            $shipping = isset($line['shipmentAmount']) ? PazaramaSalesMapper::money($line['shipmentAmount']) : null;
        } catch (SyncFailure) {
            $shipping = null;
        }
        $pricing = PazaramaInvoiceAmount::pricing($line, $sellerTotal);
        $buyer = PazaramaInvoiceAmount::fromPricing($pricing, $sellerTotal);
        return self::clean([
            ...self::pricing($pricing),
            'platformDiscountCents' => $buyer === null ? null : $pricing['platformDiscountCents'],
            'netProductCents' => $buyer,
            'shippingCents' => $shipping,
        ]);
    }

    private static function decimal(mixed $value): ?int
    {
        if ($value === null) return null;
        try { return KoctasOrderMapper::cents($value); }
        catch (SyncFailure) { return null; }
    }

    private static function hepsiburadaMoney(mixed $value): ?int
    {
        if (!is_array($value) || ($value['currency'] ?? 'TRY') !== 'TRY') return null;
        try { return HepsiburadaMoney::cents($value['amount'] ?? null); }
        catch (SyncFailure) { return null; }
    }
}
