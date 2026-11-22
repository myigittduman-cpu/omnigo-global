<?php
declare(strict_types=1);
namespace Omnigo;

/** Unsupported operations cannot be granted by a stored legacy or per-user policy. */
final class InventoryModelPermissions
{
    public static function apply(array $grants,string $mode):array
    {
        if($mode==='marketplace')foreach(['editStock','recordStoreSale'] as $action)$grants['inventory'][$action]=false;
        if($mode==='store')$grants['inventory']['viewMarketplaceStocks']=false;
        if(empty($grants['inventory']['viewPurchasePrice']))$grants['inventory']['editPurchasePrice']=false;
        return $grants;
    }
}
