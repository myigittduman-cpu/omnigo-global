<?php
declare(strict_types=1);
namespace Omnigo;

/** Legacy modes and a combined field share the same identity, password and MFA checks. */
final class LoginIdentity
{
    public static function install(Database $db): void
    {
        try{$db->one('SELECT user_id FROM user_login_names LIMIT 1');$db->one('SELECT user_id FROM user_login_history LIMIT 1');return;}
        catch(\PDOException $e){if(!in_array((string)$e->getCode(),['42S02','HY000'],true))throw $e;}
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS user_login_names (user_id INTEGER PRIMARY KEY, username TEXT NOT NULL, identity_hash CHAR(64) NOT NULL UNIQUE)'.$suffix);
        $db->run('CREATE TABLE IF NOT EXISTS user_login_history (event_id CHAR(32) PRIMARY KEY,user_id INTEGER NOT NULL, previous_name TEXT, next_name TEXT NOT NULL, created BIGINT NOT NULL)'.$suffix);
        AdminLoginProfiles::seed($db);
    }
    public static function validate(mixed $value): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\p{Cc}\p{Cf}]/u',$value))Http::fail(400,'Kullanıcı adını kontrol edin.');
        $value=trim($value);
        if(mb_strlen($value)<5)Http::fail(400,'Kullanıcı adı en az 5 karakter olmalı.');
        if(strlen($value)>1024)Http::fail(400,'Kullanıcı adı çok uzun.');
        return $value;
    }
    public static function key(string $value): string
    {
        $value=mb_strtolower(str_replace(['İ','I','ı'],'i',trim($value)),'UTF-8');
        $value=str_replace(["i\u{0307}","u\u{0308}","o\u{0308}","c\u{0327}","s\u{0327}","g\u{0306}"],['i','ü','ö','ç','ş','ğ'],$value);
        if(class_exists(\Normalizer::class))$value=\Normalizer::normalize($value,\Normalizer::FORM_C);
        return hash('sha256',$value);
    }
    public static function read(Database $db,int $user): ?string
    {
        self::install($db);return $db->one('SELECT username FROM user_login_names WHERE user_id=?',[$user])['username']??null;
    }
    public static function save(Database $db,int $user,string $value): void
    {
        $value=self::validate($value);$key=self::key($value);
        if($db->one('SELECT user_id FROM user_login_names WHERE identity_hash=? AND user_id<>?',[$key,$user]))Http::fail(409,'Bu kullanıcı adı başka bir hesapta kullanılıyor.');
        try{
            if($db->one('SELECT user_id FROM user_login_names WHERE user_id=?',[$user]))$db->run('UPDATE user_login_names SET username=?,identity_hash=? WHERE user_id=?',[$value,$key,$user]);
            else $db->run('INSERT INTO user_login_names(user_id,username,identity_hash) VALUES(?,?,?)',[$user,$value,$key]);
        }catch(\PDOException $e){if(in_array((string)$e->getCode(),['23000','23505'],true))Http::fail(409,'Bu kullanıcı adı başka bir hesapta kullanılıyor.');throw $e;}
    }
    public static function find(Database $db,string $mode,string $identifier): ?array
    {
        if($mode==='auto'){
            $email=self::find($db,'email',$identifier);$username=self::find($db,'username',$identifier);
            // Never choose a different account when a username collides with another email.
            if($email&&$username&&(int)$email['id']!==(int)$username['id'])return null;
            return $email??$username;
        }
        if($mode==='email')return $db->one('SELECT * FROM users WHERE email=?',[mb_strtolower(trim($identifier))]);
        self::install($db);
        return $db->one('SELECT u.*,n.username FROM users u JOIN user_login_names n ON n.user_id=u.id WHERE n.identity_hash=?',[self::key($identifier)]);
    }
    public static function changeOwn(Database $db,array $actor,array $data): array
    {
        Security::admin($actor);self::install($db);$value=self::validate($data['username']??null);
        TransactionRetry::run($db,function()use($db,$actor,$data,$value){
            $db->one('SELECT id FROM users WHERE id=?'.$db->lock(),[$actor['id']]);$before=self::read($db,(int)$actor['id']);
            if(($data['previousUsername']??null)!==$before)Http::fail(409,'Kullanıcı adı değişmiş. Ayarları yeniden açın.');
            self::save($db,(int)$actor['id'],$value);
            $db->run('INSERT INTO user_login_history(event_id,user_id,previous_name,next_name,created) VALUES(?,?,?,?,?)',[bin2hex(random_bytes(16)),$actor['id'],$before,$value,time()]);
        });
        return ['username'=>$value];
    }
}
