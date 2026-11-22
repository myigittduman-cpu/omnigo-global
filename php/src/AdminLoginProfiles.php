<?php
declare(strict_types=1);
namespace Omnigo;

/** Only the two already-known administrators appear on the public account selector. */
final class AdminLoginProfiles
{
    private const PROFILES=[
        'application_owner'=>['name'=>'Yiğit Duman','emails'=>['yigit.duman@omnigoglobal.com','m.yigittduman@gmail.com'],'initials'=>'YD','username'=>'yigit.duman'],
        ['name'=>'Mertkal Tekkaya','emails'=>['mertkal.tekkaya@omnigoglobal.com','mertkaltekkaya@gmail.com'],'initials'=>'MT','username'=>'mertkal.tekkaya']
    ];
    private static function user(Database $db,array $profile): ?array
    {
        $users=$db->all("SELECT id,name,email FROM users WHERE role='admin' AND email IN (?,?)",$profile['emails']);
        $users=array_values(array_filter($users,fn($u)=>mb_strtolower(trim($u['name']))===mb_strtolower($profile['name'])));
        // Do not guess between duplicates, aliases or demo accounts.
        return count($users)===1?$users[0]:null;
    }
    public static function canManageApplication(Database $db,array $actor):bool
    {
        if(($actor['role']??null)!=='admin'||empty($actor['id']))return false;
        $owner=self::user($db,self::PROFILES['application_owner']);
        return $owner!==null&&(int)$owner['id']===(int)$actor['id'];
    }
    public static function seed(Database $db): void
    {
        foreach(self::PROFILES as $profile){$user=self::user($db,$profile);if(!$user||$db->one('SELECT user_id FROM user_login_names WHERE user_id=?',[$user['id']]))continue;LoginIdentity::save($db,(int)$user['id'],$profile['username']);}
    }
    public static function listing(Database $db): array
    {
        $result=[];foreach(self::PROFILES as $profile){$user=self::user($db,$profile);$result[]=['name'=>$profile['name'],'initials'=>$profile['initials'],'username'=>$user?LoginIdentity::read($db,(int)$user['id']):null];}return $result;
    }
}
