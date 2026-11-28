<?php
declare(strict_types=1);
namespace Omnigo;

/** Opt-in customer defaults. Stored personal grants remain editable by Omnigo administrators. */
final class CustomerWorkspaceStandard
{
    public const PRESET='customer-standard';
    public const MENU=['overview','fileCenter','orders','returns','inventory','deliveries','shipments',
        'financeProfitability','analytics','payments','support'];
    private const CHILDREN=['overviewSales','overviewTop','overviewWork','shipmentInvoices'];
    private const EXCEPTIONS=[
        'orders'=>['documents','upload'],
        'fileCenter'=>['export'],
        'financeProfitability'=>['export'],
        'analytics'=>['export'],
        'inventory'=>['viewPurchasePrice','editPurchasePrice','viewMovements','viewSales','viewMarketplaceStocks','editStock','recordStoreSale'],
        'deliveries'=>['selectDocuments','export'],
        'shipments'=>['selectRows','invoice','documents','upload','export'],
        'shipmentInvoices'=>['documents','upload'],
        'support'=>['create'],
    ];

    public static function enabled(array $profile):bool
    {
        return ($profile['interfacePreset']??null)===self::PRESET;
    }

    public static function pages():array
    {
        $pages=array_fill_keys(array_keys(CustomerPermissionCatalog::pages()),'hidden');
        foreach([...self::MENU,...self::CHILDREN] as $page)$pages[$page]='all_roles';
        $pages['visibility']='admin';
        return $pages;
    }

    public static function grants(string $inventoryMode='horoz'):array
    {
        $grants=[];
        foreach(CustomerPermissionCatalog::pages() as $page=>$definition){
            $grants[$page]=array_fill_keys($definition['actions'],false);
            if(!in_array($page,[...self::MENU,...self::CHILDREN],true))continue;
            foreach(['view',...(self::EXCEPTIONS[$page]??[]),...CustomerTableVisibility::actions($page)] as $action)$grants[$page][$action]=true;
        }
        return InventoryModelPermissions::apply(CustomerPermissionCatalog::validateGrants($grants),$inventoryMode);
    }

    public static function policy(array $inventory=['mode'=>'horoz'],?array $notifications=null):array
    {
        return ['pages'=>self::pages(),'inventory'=>$inventory,'defaults'=>self::grants($inventory['mode']),
            'notifications'=>$notifications??CustomerNotificationPolicy::DEFAULTS,'users'=>(object)[],
            'useStoreDefaultsForNewAccounts'=>true];
    }

    public static function profile(array $profile):array
    {
        return [...$profile,'interfacePreset'=>self::PRESET,'overviewLayout'=>'tabs','newAccountOverviewTabs'=>true];
    }
}
