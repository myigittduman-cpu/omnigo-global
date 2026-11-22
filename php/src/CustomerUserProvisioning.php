<?php
declare(strict_types=1);
namespace Omnigo;

/** A new account receives an explicit snapshot; later edits affect only that account. */
final class CustomerUserProvisioning
{
    public static function create(Database $db,array $actor,int $company,array $account): int
    {
        Security::admin($actor);
        $role=CustomerAccountRoles::forCreation($account['storeRole']??null);
        LoginIdentity::install($db);if(isset($account['username']))$account['username']=LoginIdentity::validate($account['username']);$hash=Security::hashPassword(StoreAccountCredentials::validate($account['password']??null));
        return TransactionRetry::run($db,function()use($db,$actor,$company,$account,$hash,$role){
            $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]);
            if(($account['passwordSequence']??null)!==null){$suggestion=StoreAccountCredentials::suggestion($db,$company);if($account['passwordSequence']!==$suggestion['sequence'])Http::fail(409,'Bu müşteride yeni bir kullanıcı oluşturuldu. Şifre önerisini yenileyin.');}
            if(!empty($account['legacySuggestion'])&&hash_equals(CustomerInitialPassword::suggestion($db,$company)['password'],$account['password']))
                Http::fail(409,'Kullanıcı formunu yeniden açıp şifrenizi belirleyin.');
            $db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)',[$account['email'],$account['name'],$hash,'client',$company]);$id=$db->insertId();
            if(isset($account['username']))LoginIdentity::save($db,$id,$account['username']);
            $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);$profile=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            if(($account['passwordSequence']??null)!==null)$profile['initialPasswordSequence']=(int)($profile['initialPasswordSequence']??0)+1;
            $config=(new CustomerPermissions($db,$company))->configuration();
            $profile=CustomerAccountRoles::initialize($profile,$config,$id,$role);
            $payload=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($row)$db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$company]);
            else $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$payload]);
            $db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,NULL,?,?)',[$actor['id'],$company,'Müşteri kullanıcısı oluşturuldu ve yetkileri tanımlandı',$id,json_encode(['storeRole'=>$role,'grants'=>$profile['workspacePolicy']['users'][$id]],JSON_UNESCAPED_UNICODE),gmdate('c')]);
            return $id;
        });
    }
}
