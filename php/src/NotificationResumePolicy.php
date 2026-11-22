<?php
declare(strict_types=1);
namespace Omnigo;

/** Resume delivery only after the current event boundary. Historical inbox rows are untouched. */
final class NotificationResumePolicy
{
    private static function enabled(array $profile,array $user,string $key):bool
    {
        $policy=$profile['workspacePolicy']??[];$role=$user['role'];
        if(($policy['notifications'][$key]??CustomerNotificationPolicy::DEFAULTS[$key])!==true)return false;
        $group=CustomerNotificationPolicy::eventGroup($key);
        if(($policy['notifications'][$group]??CustomerNotificationPolicy::DEFAULTS[$group])!==true)return false;
        if($key==='newOrder')return $role==='admin';
        $customerPage=CustomerOrderNotifications::options()[$key]??null;
        if($role!=='admin'&&!in_array($key,['payments','support'],true)&&$customerPage===null)return false;
        if($role==='admin'&&($key==='payments'||$customerPage!==null))return false;
        $pages=$customerPage!==null?[$customerPage]:match($group){'cancellation','documents'=>['orders'],'stock'=>['inventory'],'returns'=>['returns'],'payments'=>['payments'],'transfer'=>['shipments','deliveries'],'shipment'=>['shipments'],'connections'=>['channels'],default=>['support']};
        $storeRole=CustomerAccountRoles::roles($profile)[$user['id']]??null;
        $grants=(array)($policy['users']??[]);$grants=$grants[$user['id']]??CustomerAccountRoles::defaultsFor($storeRole,$policy['defaults']??CustomerPermissionCatalog::defaults());
        foreach($pages as $page){
            if(($profile['modules'][$page]??true)===false)continue;
            if(CustomerSectionAudience::allows($page,$policy['pages'][$page]??'shown',$role,$storeRole)
                &&($role==='admin'||!empty($grants[$page]['view'])))return true;
        }
        return false;
    }
    public static function update(Database $db,int $company,array $before,array $after):array
    {
        $floors=$before['notificationResume']??[];$boundary=null;
        foreach($db->all("SELECT id,role FROM users WHERE role='admin' OR company_id=?",[$company]) as $user)foreach(array_keys(CustomerNotificationPolicy::DEFAULTS) as $key){
            if(self::enabled($before,$user,$key)||!self::enabled($after,$user,$key))continue;
            if($boundary===null){
                try{$order=(int)($db->one('SELECT MAX(id) AS id FROM order_notifications WHERE company_id=?',[$company])['id']??0);}catch(\PDOException $e){if(!in_array((string)$e->getCode(),['42S02','HY000'],true))throw $e;$order=0;}
                $boundary=['order'=>$order,'audit'=>(int)($db->one('SELECT MAX(id) AS id FROM audit WHERE company_id=?',[$company])['id']??0)];
            }$floors[$user['id']][$key]=$boundary;
        }return $floors;
    }
    public static function afterBoundary(Database $db,int $company,int $user,string $key,int $id,string $source='order'):bool
    {
        return $id>(int)(self::forUser($db,$company,$user)[$key][$source]??-1);
    }
    public static function forUser(Database $db,int $company,int $user):array
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$profile=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        return $profile['notificationResume'][$user]??[];
    }
    public static function mailAllowed(Database $db,int $user,string $id,array $payload):bool
    {
        if(($payload['type']??'')!=='operation')return true;
        $detail=$payload['detail']??[];$company=(int)($detail['companyId']??0);if(!$company)return true;
        $actor=$db->one('SELECT id,role,company_id FROM users WHERE id=?',[$user]);if(!$actor)return false;
        if(preg_match('/^user-notification-(\d+)$/D',$id,$source)){
            $event=$db->one('SELECT * FROM order_notifications WHERE id=? AND company_id=?',[(int)$source[1],$company]);
            return $event!==null&&CustomerNotificationPolicy::recipient($db,$actor,$event);
        }
        $events=$detail['events']??[];$key=in_array('user_change',$events,true)?'users':(in_array('new_order',$events,true)?'newOrder':ActivityNotification::key($detail));
        if(!preg_match('/^(operation|user-notification)-(\d+)$/D',$id,$match))return false;
        return (new CustomerNotificationPolicy($db,$actor,$company))->allowsActivity($key,$events,(int)$match[2],
            $match[1]==='operation'?'audit':'order');
    }
}
