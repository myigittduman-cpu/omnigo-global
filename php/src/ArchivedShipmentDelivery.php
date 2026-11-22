<?php
declare(strict_types=1);
namespace Omnigo;

/** Only an existing holder of the former read permission may open an archived attachment. */
final class ArchivedShipmentDelivery
{
    private const PAGE='shipmentDeliveryDocuments';

    public static function flags(array $profile,array $user): ?array
    {
        if(($user['role']??'')==='admin')return CustomerTableVisibility::ALL;
        if(($user['role']??'')!=='client')return null;
        if(($profile['modules']['shipments']??true)===false)return null;
        $id=(int)($user['id']??0);
        $policy=$profile['workspacePolicy']??[];
        $role=$policy['roles'][$id]??CustomerAccountRoles::MANAGER;
        if(array_key_exists('archivedDeliveryReaders',$profile)){
            $saved=((array)$profile['archivedDeliveryReaders'])[$id]??null;
            return ($saved['role']??null)===$role?$saved['flags']:null;
        }
        $selection=$profile['setupOptions']??null;
        if($selection!==null&&!in_array(self::PAGE,$selection['live'],true)
            &&!in_array('deliveryDocumentUpload',$selection['manual'],true))return null;

        $pages=$policy['pages']??[];
        $audience=$pages[self::PAGE]??$pages['shipments']??'shown';
        // The former page was eligible for the same store roles as shipments.
        if(!CustomerSectionAudience::allows('shipments',$audience,'client',$role))return null;
        $grants=$policy['users'][$id]??null;
        if($grants===null){
            if($role===CustomerAccountRoles::LOGISTICS){
                // Newly provisioned accounts have their own current-format grant snapshot.
                if(isset($pages['cityProducts']))return null;
                $grants=[self::PAGE=>['view'=>true,'documents'=>true]];
            }else $grants=$policy['defaults']??[];
        }
        $delivery=$grants[self::PAGE]??null;
        if($delivery===null){
            if(isset($grants['cityProducts']))return null;
            $shipment=$grants['shipments']??[];
            $delivery=['view'=>$shipment['view']??true,'documents'=>$shipment['documents']??false];
        }
        if(($delivery['view']??false)!==true||($delivery['documents']??false)!==true)return null;
        return ['cancellations'=>($delivery[CustomerTableVisibility::CANCELLATIONS]??false)===true,
            'returns'=>($delivery[CustomerTableVisibility::RETURNS]??false)===true,'afterCancellationTurnover'=>false];
    }

    /** Freeze the old account IDs before a settings save replaces the legacy permission map. */
    public static function snapshot(Database $db,int $company,array &$profile): void
    {
        if(array_key_exists('archivedDeliveryReaders',$profile))return;
        $readers=[];
        foreach($db->all("SELECT id,role FROM users WHERE company_id=? AND role='client'",[$company]) as $user){
            $flags=self::flags($profile,$user);
            if($flags!==null)$readers[(int)$user['id']]=['flags'=>$flags,
                'role'=>$profile['workspacePolicy']['roles'][$user['id']]??CustomerAccountRoles::MANAGER];
        }
        $profile['archivedDeliveryReaders']=(object)$readers;
    }
}

