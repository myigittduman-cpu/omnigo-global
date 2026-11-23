<?php
declare(strict_types=1);
namespace Omnigo;

/** A card owns its direct offers and explicitly verified marketplace links. */
final class InventoryStockCard
{
    public static function listingKeySql(): string
    {
        return 'COALESCE(k.product_key,l.product_key)';
    }

    public static function listingLinkSql(): string
    {
        return " LEFT JOIN inventory_links k ON k.company_id=l.company_id AND k.provider=l.provider AND k.source_product=l.product_key AND k.state='active'";
    }

    public static function primaryProductSql(): string
    {
        return "NOT EXISTS (SELECT 1 FROM inventory_links k WHERE k.company_id=p.company_id AND k.source_product=p.product_key AND k.product_key<>p.product_key AND k.state='active')";
    }

    public static function providers(array $snapshots): array
    {
        $providers=array_unique(array_column($snapshots,'provider'));
        return array_values(array_intersect(CustomerMarketplaces::PROVIDERS,$providers));
    }
}
