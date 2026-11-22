<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerAccountAssignment
{
    /** The caller locks the affected companies. The target store gets a fresh set of grants. */
    public static function assign(Database $db,int $user,?int $from,?int $to,?string $role=null): void
    {
        if($from===$to)return;
        if($from===null)$role??=CustomerAccountRoles::MANAGER;
        $navigationLayout=null;
        foreach(array_filter([$from,$to]) as $company){
            $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$profile=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            if($company===$from){
                $role=CustomerAccountRoles::roles($profile)[$user]??null;
                $navigationLayout=CustomerNavigationLayout::stored($profile['workspacePolicy']??[])[$user]??null;
                if(!$row)continue;
                unset($profile['workspacePolicy']['users'][$user],$profile['workspacePolicy']['roles'][$user],$profile['workspacePolicy']['navigationLayouts'][$user]);
            }else{
                $config=(new CustomerPermissions($db,$company))->configuration();
                $profile=CustomerAccountRoles::initialize($profile,$config,$user,$role,$from===null);
                if($navigationLayout!==null)$profile['workspacePolicy']['navigationLayouts'][$user]=$navigationLayout;
            }
            $payload=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($row)$db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$company]);else $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$payload]);
        }
    }
    public static function move(Database $db,array $actor,int $user,int $from,int $to): void
    {
        Security::admin($actor);if($from===$to)return;
        if(!$db->one('SELECT id FROM companies WHERE id=?',[$to]))Http::fail(404,'Bağlanacak müşteri bulunamadı.');
        self::assign($db,$user,$from,$to);
        $db->run('UPDATE users SET company_id=? WHERE id=?',[$to,$user]);
        (new CustomerManagement($db))->revoke($user);
        // Mobile registrations can outlive a session; revoke them explicitly on reassignment.
        $tables=$db->mysql()?array_map(fn($r)=>array_values($r)[0],$db->all('SHOW TABLES')):array_column($db->all("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        if(in_array('notification_devices',$tables,true)){
            $db->run('UPDATE notification_devices SET enabled=0,generation=generation+1 WHERE user_id=?',[$user]);
            if(in_array('push_deliveries',$tables,true))$db->run("UPDATE push_deliveries SET state='cancelled' WHERE device_id IN (SELECT id FROM notification_devices WHERE user_id=?) AND state IN ('pending','sending')",[$user]);
        }
        $db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$actor['id'],$to,'Müşteri kullanıcısının mağazası değiştirildi',$user,json_encode(['companyId'=>$from]),json_encode(['companyId'=>$to]),gmdate('c')]);
    }
}
