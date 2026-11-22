<?php
declare(strict_types=1);
namespace Omnigo;

/** Response redaction complements command/history endpoint checks. No stock arithmetic changes. */
final class InventoryPermissionPolicy
{
    public static function capabilities(CustomerPermissions $policy,array $user):array
    {
        $names=CustomerPermissionCatalog::pages()['inventory']['actions'];
        if(($user['role']??'')==='admin')return array_fill_keys($names,true);
        $grants=$policy->access($user)['grants']['inventory']??[];$result=[];
        foreach($names as $name)$result[$name]=!empty($grants['view'])&&!empty($grants[$name]);
        if(!$result['viewPurchasePrice'])$result['editPurchasePrice']=false;
        return $result;
    }
    public static function commandAction(array $input):string
    {
        return in_array($input['mode']??'',['store_sale','store_return'],true)?'recordStoreSale':'editStock';
    }
    public static function listing(array $data,array $capabilities):array
    {
        $data['capabilities']=$capabilities;
        foreach($data['items'] as &$item){
            if(empty($capabilities['viewPurchasePrice']))foreach(['purchasePriceCents','purchasePriceVersion','purchasePriceUpdated','purchaseInputCents','purchaseVatBasis','purchaseVatRateBasisPoints','purchaseGrossCents','purchaseNetCents'] as $field)unset($item[$field]);
            if(empty($capabilities['viewSales']))foreach(['markets','storeSales','marketplaceAvailability'] as $field)unset($item[$field]);
            if(empty($capabilities['viewMarketplaceStocks']))foreach(['marketplaceStocks','marketplaceReference'] as $field)unset($item[$field]);
        }
        unset($item);return $data;
    }
}
