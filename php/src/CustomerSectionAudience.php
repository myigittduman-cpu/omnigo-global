<?php
declare(strict_types=1);
namespace Omnigo;

/** Store section visibility is independent of each account's action grants. */
final class CustomerSectionAudience
{
    private const ROLES = [
        'admin' => ['admin'],
        'accounting_logistics' => [CustomerAccountRoles::LOGISTICS],
        'store_manager' => [CustomerAccountRoles::MANAGER],
        'admin_logistics' => ['admin', CustomerAccountRoles::LOGISTICS],
        'admin_manager' => ['admin', CustomerAccountRoles::MANAGER],
        'store_roles' => [CustomerAccountRoles::LOGISTICS, CustomerAccountRoles::MANAGER],
        'all_roles' => ['admin', CustomerAccountRoles::LOGISTICS, CustomerAccountRoles::MANAGER],
    ];

    public static function validatePages(array $pages): void
    {
        $catalog=CustomerPermissionCatalog::pages();
        if(array_diff_key($pages,$catalog))Http::fail(400,'Bölüm seçimini kontrol edin.');
        foreach(array_keys($catalog) as $page){
            $value=$pages[$page]??null;
            if(!is_string($value)||(!self::enabled($value)&&!in_array($value,['hidden','removed'],true))){
                Http::fail(400,'Her bölüm için görünürlük seçin.');
            }
        }
    }

    public static function enabled(string $audience): bool
    {
        return isset(self::ROLES[$audience])||in_array($audience,['shown','client'],true);
    }

    public static function allows(string $page,string $audience,string $role,?string $storeRole=null): bool
    {
        if(!in_array($role,['admin','client'],true))return false;
        $group=$role==='admin'?'admin':($storeRole===CustomerAccountRoles::LOGISTICS?CustomerAccountRoles::LOGISTICS:CustomerAccountRoles::MANAGER);
        if($group===CustomerAccountRoles::LOGISTICS&&$page==='support')return false;
        if(isset(self::ROLES[$audience]))return in_array($group,self::ROLES[$audience],true);
        return in_array($audience,['shown',$role],true)
            &&($group!==CustomerAccountRoles::LOGISTICS||CustomerAccountRoles::allowsPage($page));
    }

    public static function hasStoreRole(string $page,string $audience): bool
    {
        return self::allows($page,$audience,'client')||self::allows($page,$audience,'client',CustomerAccountRoles::LOGISTICS);
    }
}
