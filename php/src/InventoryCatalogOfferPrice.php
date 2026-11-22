<?php
declare(strict_types=1);
namespace Omnigo;

/** Read an explicit TRY catalogue price; omit unknown amounts and unverified discounts. */
final class InventoryCatalogOfferPrice
{
    public static function extract(string $provider, array $row): ?int
    {
        $currency = $row['currencyType'] ?? $row['currency_iso_code'] ?? 'TRY';
        if (!is_string($currency) || !in_array(strtoupper(trim($currency)), ['TRY','TL','₺'], true)) return null;
        $value = match ($provider) {
            'n11', 'pazarama' => $row['salePrice'] ?? null,
            'idefix', 'hepsiburada', 'koctas' => $row['price'] ?? null,
            'pttavm' => empty($row['iskonto']) || (is_numeric($row['iskonto']) && (float)$row['iskonto'] === 0.0)
                ? ($row['kdVli'] ?? null) : null,
            default => null,
        };
        if (!is_numeric($value)) return null;
        $cents = round((float)$value * 100);
        return $cents > 0 && $cents <= 100000000000 ? (int)$cents : null;
    }
}
