<?php
declare(strict_types=1);
namespace Omnigo;

/** Sort complete filtered results before pagination; live source names cannot move the default order. */
final class InventorySort
{
    public static function name(string $value):string
    {
        if(!in_array($value,['stable','sku','name_asc','name_desc','category'],true))throw new \InvalidArgumentException('Ürün sıralamasını kontrol edin.');
        return $value;
    }
    public static function order(string $value,bool $positions=false):string
    {
        $stable="CASE WHEN TRIM(p.sku)='' THEN 1 ELSE 0 END,p.sku,p.product_key";
        return match(self::name($value)){
            'stable'=>$positions?'CASE WHEN o.position_no IS NULL THEN 1 ELSE 0 END,o.position_no,'.$stable:$stable,
            'name_asc'=>'p.title ASC,'.$stable,
            'name_desc'=>'p.title DESC,'.$stable,
            'category'=>'('.InventoryProductCategory::expression().'),'.$stable,
            default=>$stable
        };
    }
}
