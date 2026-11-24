<?php
declare(strict_types=1);
namespace Omnigo;

/** Keep historical product references while reading the current SKU card. */
final class InventoryStockCard
{
    public static function productKeySql(Database $db,string $alias,string $column='product_key'):string
    {
        $key=$alias.'.'.$column;
        $path=$db->mysql()?"CONCAT('$.inventoryStockCodeIdentity.members.\"', $key, '\"')"
            :"('$.inventoryStockCodeIdentity.members.\"' || $key || '\"')";
        $value="JSON_EXTRACT(cp.payload,$path)";
        if($db->mysql())$value="JSON_UNQUOTE($value)";
        return "COALESCE((SELECT $value FROM company_profiles cp WHERE cp.company_id=$alias.company_id),$key)";
    }

    public static function primaryProductSql(Database $db):string
    {
        return 'p.product_key='.self::productKeySql($db,'p');
    }

    public static function primaryKey(Database $db,int $company,string $key):string
    {
        $row=$db->one('SELECT '.self::productKeySql($db,'p').' AS card_key FROM inventory_products p WHERE p.company_id=? AND p.product_key=?',[$company,$key]);
        return $row['card_key']??$key;
    }

    public static function providers(array $snapshots): array
    {
        $providers=array_unique(array_column($snapshots,'provider'));
        return array_values(array_intersect(CustomerMarketplaces::PROVIDERS,$providers));
    }
}
