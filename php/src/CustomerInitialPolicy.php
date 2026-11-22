<?php
declare(strict_types=1);
namespace Omnigo;

/** Stable sales-oriented defaults, never copied from another tenant's users or credentials. */
final class CustomerInitialPolicy
{
    public static function template(?Database $db=null): array
    {
        $pages=['overview'=>'shown','channels'=>'shown','orders'=>'shown','inventory'=>'shown','analytics'=>'shown','payments'=>'shown','tasks'=>'hidden','reports'=>'hidden','support'=>'shown','returns'=>'admin','shipments'=>'shown','deliveries'=>'shown','cityProducts'=>'shown','visibility'=>'shown'];
        foreach(CustomerPermissionCatalog::PAGE_PARENTS as $page=>$parent)$pages[$page]=$pages[$parent];
        $defaults=CustomerStandardPermissions::grants();
        $notifications=CustomerNotificationPolicy::DEFAULTS;
        return ['pages'=>$pages,'inventory'=>['mode'=>'horoz'],'defaults'=>$defaults,'notifications'=>$notifications,'users'=>(object)[]];
    }
    public static function validate(mixed $value): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value))Http::fail(400,'Başlangıç yetkilerini kontrol edin.');
        $pages=(array)($value['pages']??[]);
        unset($pages['shipmentDeliveryDocuments']);
        $pages['cityProducts']??='shown';
        if(!array_key_exists('visibility',$pages))$pages['visibility']='shown';
        foreach(CustomerPermissionCatalog::PAGE_PARENTS as $page=>$parent)$pages[$page]??=$pages[$parent]??'hidden';
        CustomerSectionAudience::validatePages($pages);
        try{$inventory=InventorySettings::validate($value['inventory']??[]);}
        catch(\InvalidArgumentException $error){Http::fail(400,$error->getMessage());}
        if(!empty($inventory['marketplaceManagement'])&&$inventory['mode']!==MarketplaceStockPolicy::onboardingOption()['baseMode']){
            Http::fail(400,'Pazaryeri stok yönetimi için ortak stok modelini seçin.');
        }
        return ['pages'=>$pages,'inventory'=>$inventory,'defaults'=>InventoryModelPermissions::apply(CustomerPermissionCatalog::validateGrants($value['defaults']??null),$inventory['mode']),'notifications'=>CustomerNotificationPolicy::validate($value['notifications']??null),'users'=>(object)[]];
    }
}
