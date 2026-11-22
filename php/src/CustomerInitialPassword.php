<?php
declare(strict_types=1);
namespace Omnigo;

/** Temporary credentials are admin-only, hash-only and unusable for customer data until changed. */
final class CustomerInitialPassword
{
    private const KEY='customer-initial-password';
    public static function suggestion(Database $db,int $company): array
    {
        $row=$db->one('SELECT name FROM companies WHERE id=?',[$company]);if(!$row)Http::fail(404,'Müşteri bulunamadı.');
        $profile=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$data=$profile?json_decode($profile['payload'],true,32,JSON_THROW_ON_ERROR):[];
        $name=strtr($row['name'],['İ'=>'I','ı'=>'i','Ş'=>'S','ş'=>'s','Ğ'=>'G','ğ'=>'g','Ü'=>'U','ü'=>'u','Ö'=>'O','ö'=>'o','Ç'=>'C','ç'=>'c']);
        $prefix=preg_split('/\s+/',trim($name))[0];$prefix=preg_replace('/[^a-zA-Z0-9]/','',$prefix);$prefix=ucfirst(strtolower($prefix?:'Musteri'));
        if(strlen($prefix)<5)$prefix.='Portal';
        $sequence=(int)($data['initialPasswordSequence']??0)+1;
        return ['sequence'=>$sequence,'password'=>$prefix.'.'.$sequence.'*'];
    }
    public static function validate(mixed $password): string
    {
        if(!is_string($password)||mb_strlen($password)<8||strlen($password)>128||str_contains($password,"\0"))Http::fail(400,'Geçici şifre 8–128 karakter olmalı.');
        return $password;
    }
    public static function account(array $data): array
    {
        $password=self::validate($data['password']??null);
        $account=Validation::account([...$data,'password'=>str_repeat('x',12)]);
        return [...$account,'password'=>$password,'temporaryPassword'=>true,'passwordSequence'=>$data['passwordSequence']??null];
    }
    public static function mark(Database $db,int $id,string $hash): void
    {
        $key=hash('sha256',self::KEY);$db->run('DELETE FROM user_preferences WHERE user_id=? AND profile_key=?',[$id,$key]);
        $db->run('INSERT INTO user_preferences(user_id,profile_key,payload,version,updated) VALUES(?,?,?,1,?)',[$id,$key,json_encode(['credentialTag'=>hash('sha256',$hash)],JSON_THROW_ON_ERROR),time()]);
    }
    public static function required(Database $db,array $user): bool
    {
        if(($user['role']??'')!=='client')return false;
        $row=$db->one('SELECT payload FROM user_preferences WHERE user_id=? AND profile_key=?',[$user['id'],hash('sha256',self::KEY)]);
        return $row&&hash_equals(json_decode($row['payload'],true,8,JSON_THROW_ON_ERROR)['credentialTag']??'',hash('sha256',$user['password']));
    }
    public static function set(Database $db,array $actor,int $company,int $id,array $data): void
    {
        Security::admin($actor);$hash=Security::hashPassword(self::validate($data['password']??null));
        TransactionRetry::run($db,function()use($db,$actor,$company,$id,$hash){
            $management=new CustomerManagement($db);$db->one('SELECT id FROM users WHERE id=?'.$db->lock(),[$id]);$management->customer($company,$id);
            $db->run('UPDATE users SET password=? WHERE id=?',[$hash,$id]);self::mark($db,$id,$hash);$management->revoke($id);
            $db->run('UPDATE notification_devices SET enabled=0 WHERE user_id=?',[$id]);
            $management->audit($actor,$company,'Müşteri için geçici şifre belirlendi',$id);
        });
    }
    public static function complete(Database $db,array $user,array $data): void
    {
        $account=Validation::account(['email'=>$user['email'],'name'=>$user['name'],'password'=>$data['password']??null]);
        if(!is_string($data['currentPassword']??null)||!Security::verifyPassword($data['currentPassword'],$user['password']))Http::fail(401,'Geçici şifrenizi kontrol edin.');
        if(Security::verifyPassword($account['password'],$user['password']))Http::fail(400,'Geçici şifrenizden farklı bir şifre seçin.');
        $hash=Security::hashPassword($account['password']);
        TransactionRetry::run($db,function()use($db,$user,$hash){
            $current=$db->one('SELECT * FROM users WHERE id=?'.$db->lock(),[$user['id']]);
            if(!$current||!hash_equals($current['password'],$user['password'])||!self::required($db,$current))Http::fail(409,'Şifre bilgisi değişti. Yeniden giriş yapın.');
            $db->run('UPDATE users SET password=? WHERE id=?',[$hash,$user['id']]);
            $db->run('DELETE FROM user_preferences WHERE user_id=? AND profile_key=?',[$user['id'],hash('sha256',self::KEY)]);
            (new CustomerManagement($db))->revoke((int)$user['id']);
        });
    }
}
