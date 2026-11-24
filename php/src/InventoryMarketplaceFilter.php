<?php
declare(strict_types=1);
namespace Omnigo;

/** Restricts catalogue results before counting/pagination; never changes source stocks. */
final class InventoryMarketplaceFilter
{
    public static function label(string $provider): string
    {
        $provider=trim($provider);
        if ($provider==='') return '';
        foreach (InventoryCatalogPage::LABELS as $key=>$label) {
            if ($provider===$key || $provider===$label) return $label;
        }
        throw new \InvalidArgumentException('Pazaryeri seçimini kontrol edin.');
    }

    public static function where(string $provider): array
    {
        $label=self::label($provider);
        if ($label==='') return ['1=1',[]];
        return ["EXISTS (SELECT 1 FROM inventory_listings ml
            WHERE ml.company_id=p.company_id AND ml.provider=? AND ml.remote_id NOT LIKE 'order-sku:%'
            AND ml.product_key=p.product_key)",[$label]];
    }
}
