<?php
declare(strict_types=1);
namespace Omnigo;

/** Presentation preference only; never changes device ownership or delivery eligibility. */
final class NotificationLanguage
{
    public static function profile(array $user): ?string
    {
        if(($user['role']??'')==='admin')return hash('sha256','admin:admin');
        if(($user['role']??'')!=='client'||empty($user['operatorName']))return null;
        return hash('sha256','client:'.mb_strtolower(ClientSessionOperator::name($user['operatorName'])));
    }
    public static function read(Database $db,array $device,?string $profile): string
    {
        if(!$profile){
            $user=$db->one('SELECT role FROM users WHERE id=?',[$device['user_id']]);
            $profile=self::profile($user??[]);
        }
        if(!$profile||!preg_match('/^[a-f0-9]{64}$/D',$profile))return 'tr';
        try{$row=$db->one('SELECT payload FROM user_preferences WHERE user_id=? AND profile_key=?',[$device['user_id'],$profile]);}
        catch(\PDOException){return 'tr';} // Older schemas retain their existing Turkish notifications.
        $language=json_decode($row['payload']??'{}',true)['language']??'tr';
        return in_array($language,['tr','en','de'],true)?$language:'tr';
    }
    public static function text(string $source,string $language,array $values=[]): string
    {
        static $catalog=null;
        if($language!=='tr'&&$catalog===null)$catalog=json_decode(file_get_contents(__DIR__.'/notification-translations.json'),true,32,JSON_THROW_ON_ERROR);
        $message=$language==='tr'?$source:($catalog[$source][$language]??$source);
        return preg_replace_callback('/\{(\d+)\}/',fn($m)=>array_key_exists((int)$m[1],$values)?(string)$values[(int)$m[1]]:$m[0],$message);
    }
}
