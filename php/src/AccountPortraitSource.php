<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer portraits follow their current company, without copying personal images. */
final class AccountPortraitSource
{
    public function __construct(private Database $db){}
    public static function version(array $user,?array $company,?array $personal):?int
    {
        if($user['role']==='client')return empty($company['logo'])?null:(int)$company['version'];
        return empty($personal['avatar'])?null:(int)$personal['version'];
    }
    public function metadata(array $user):?int
    {
        if($user['role']==='client'){
            $row=$this->db->one('SELECT version,CASE WHEN logo IS NULL OR logo=\'\' THEN 0 ELSE 1 END AS logo FROM company_profiles WHERE company_id=?',[$user['company_id']]);
            return self::version($user,$row,null);
        }
        $row=$this->db->one('SELECT version,CASE WHEN avatar IS NULL THEN 0 ELSE 1 END AS avatar FROM account_profiles WHERE user_id=? AND deleted_at IS NULL',[$user['id']]);
        return self::version($user,null,$row);
    }
    public function image(array $user):?string
    {
        if($user['role']==='client')return $this->db->one('SELECT logo FROM company_profiles WHERE company_id=?',[$user['company_id']])['logo']??null;
        return $this->db->one('SELECT avatar FROM account_profiles WHERE user_id=? AND deleted_at IS NULL',[$user['id']])['avatar']??null;
    }
}
