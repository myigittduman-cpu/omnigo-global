<?php
declare(strict_types=1);
namespace Omnigo;

/** Permanent credentials assigned by an Omnigo administrator to a store user. */
final class StoreAccountCredentials
{
    private const KEY='customer-initial-password';
    public static function suggestion(Database $db,int $company): array
    {
        $row=$db->one('SELECT name FROM companies WHERE id=?',[$company]);if(!$row)Http::fail(404,'Müşteri bulunamadı.');
        $profile=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$data=$profile?json_decode($profile['payload'],true,32,JSON_THROW_ON_ERROR):[];
        $sequence=(int)($data['initialPasswordSequence']??0)+1;
        return ['sequence'=>$sequence,'password'=>bin2hex(random_bytes(10))];
    }
    public static function validate(mixed $password): string
    {
        if(!is_string($password)||trim($password)===''||strlen($password)>128||str_contains($password,"\0"))Http::fail(400,'Şifre boş olamaz ve UTF-8 biçiminde 128 baytı aşamaz.');
        return $password;
    }
    public static function account(array $data): array
    {
        return [...Validation::account($data,true),'passwordSequence'=>$data['passwordSequence']??null,
            'legacySuggestion'=>($data['temporaryPassword']??false)===true&&isset($data['passwordSequence'])];
    }
    public static function clear(Database $db,int $id): void
    {
        $db->run('DELETE FROM user_preferences WHERE user_id=? AND profile_key=?',[$id,hash('sha256',self::KEY)]);
    }
    public static function set(Database $db,array $actor,int $company,int $id,array $data): void
    {
        Security::admin($actor);$hash=Security::hashPassword(self::validate($data['password']??null));
        TransactionRetry::run($db,function()use($db,$actor,$company,$id,$hash){
            $management=new CustomerManagement($db);$db->one('SELECT id FROM users WHERE id=?'.$db->lock(),[$id]);$management->customer($company,$id);
            $db->run('UPDATE users SET password=? WHERE id=?',[$hash,$id]);self::clear($db,$id);$management->revoke($id);
            $db->run('UPDATE notification_devices SET enabled=0 WHERE user_id=?',[$id]);
            $management->audit($actor,$company,'Mağaza kullanıcısının şifresi değiştirildi',$id);
        });
    }
}
