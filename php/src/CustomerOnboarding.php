<?php
declare(strict_types=1);
namespace Omnigo;

/** Initial shipment scope is explicit and applies only to a newly created customer. */
final class CustomerOnboarding
{
    public const CREATED_AUDIT='Yeni müşteri oluşturuldu';

    public static function shipmentStart(mixed $date): int
    {
        $zone=new \DateTimeZone('Europe/Istanbul');$today=new \DateTimeImmutable('today',$zone);
        $value=Validation::date($date??$today->format('Y-m-01'));
        $start=new \DateTimeImmutable($value.' 00:00:00',$zone);
        if($start>$today||$start<$today->modify('first day of this month'))Http::fail(400,'İlk sevkiyat aktarımı için bu ay içinde, bugünden ileri olmayan bir tarih seçin.');
        return $start->getTimestamp();
    }
    public static function create(Database $db,array $user,string $name,mixed $date,mixed $options=null,mixed $marketplaces=null,mixed $workspacePages=null,mixed $initialPolicy=null,mixed $shippingScope=null,mixed $setupOptions=null): int
    {
        $modules=CustomerModules::validate($options??['shipments'=>true,'deliveries'=>true]);$since=self::shipmentStart($date);
        $selected=$marketplaces===null?null:CustomerMarketplaces::selection($marketplaces);
        $policy=CustomerInitialPolicy::template($db);
        if($workspacePages!==null){
            if(is_array($workspacePages)&&array_is_list($workspacePages))$workspacePages=array_values(array_filter($workspacePages,static fn($page)=>$page!=='shipmentDeliveryDocuments'));
            if(!is_array($workspacePages)||!array_is_list($workspacePages)||array_filter($workspacePages,fn($p)=>!is_string($p)||!isset(CustomerPermissionCatalog::pages()[$p])))Http::fail(400,'Başlangıç sayfalarını kontrol edin.');
            $pages=array_fill_keys(array_keys(CustomerPermissionCatalog::pages()),'hidden');
            foreach($workspacePages as $page)$pages[$page]='shown';
            $pages['visibility']='shown';
            foreach($modules as $page=>$enabled)$pages[$page]=$enabled?'shown':'removed';
            foreach(CustomerPermissionCatalog::PAGE_PARENTS as $page=>$parent)if(!in_array($page,$workspacePages,true))$pages[$page]=$pages[$parent];
            $policy['pages']=$pages;
        }
        if($initialPolicy!==null){
            $policy=CustomerInitialPolicy::validate($initialPolicy);
            if($policy['defaults']!=InventoryModelPermissions::apply(CustomerStandardPermissions::grants(),$policy['inventory']['mode']))$policy['useStoreDefaultsForNewAccounts']=true;
        }
        else foreach($modules as $page=>$enabled){
            $policy['pages'][$page]=$enabled?($policy['pages'][$page]??'shown'):'removed';
            if(!$enabled){
                $policy['defaults'][$page]['view']=false;
                foreach(CustomerPermissionCatalog::PAGE_PARENTS as $child=>$parent)if($parent===$page)$policy['pages'][$child]='removed';
            }
        }
        $selection=$setupOptions===null?null:CustomerSetupSelection::validate($setupOptions);
        if($selection!==null)$modules=CustomerSetupSelection::operationalModules($selection);
        else foreach($modules as $page=>&$enabled)$enabled=CustomerSectionAudience::enabled($policy['pages'][$page]);
        unset($enabled);
        $scope=$shippingScope===null?null:CustomerShippingScope::validate($shippingScope);
        return TransactionRetry::run($db,function()use($db,$user,$name,$since,$modules,$selected,$policy,$scope,$selection){
            $id=(new Repository($db))->createCompany($name,$selected);ShipmentOrderIngestor::activate($db,$id,$since);
            $profile=['modules'=>$modules,'workspacePolicy'=>$policy];
            if($selection!==null){$profile['setupOptions']=$selection;$profile['cityProductsConfigured']=true;}
            if($scope)$profile=CustomerShippingScope::apply($profile+['deliveryCities'=>[],'activeDeliveryCities'=>[]],$scope);
            $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$id,json_encode($profile,JSON_THROW_ON_ERROR)]);
            (new InventorySettings($db))->configure($id,$policy);
            $db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,NULL,NULL,?,?)',[$user['id'],$id,self::CREATED_AUDIT,json_encode(['shipmentSince'=>gmdate('c',$since),'modules'=>$modules]),gmdate('c')]);
            return $id;
        });
    }
}
