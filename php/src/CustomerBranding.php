<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerBranding
{
    public static function read(Database $db,int $company,?array $user=null): array
    {
        // Public identity only: never load contact details, private notes or logo bytes here.
        $row=$db->one("SELECT c.id,c.name,COALESCE(p.version,0) AS version,CASE WHEN p.logo IS NOT NULL THEN 1 ELSE 0 END AS has_logo,
            JSON_EXTRACT(p.payload,'$.menuLabels') AS menu_labels FROM companies c LEFT JOIN company_profiles p ON p.company_id=c.id WHERE c.id=?",[$company]);
        if(!$row)Http::fail(404,'Müşteri bulunamadı.');
        $identity=['id'=>(int)$row['id'],'name'=>$row['name'],'hasLogo'=>(bool)$row['has_logo'],'brandingVersion'=>(int)$row['version']];
        $identity['menuLabels']=CustomerMenuLabelScopes::effective($db,$company,$user,$row['menu_labels']); 
        return $identity;
    }
}
