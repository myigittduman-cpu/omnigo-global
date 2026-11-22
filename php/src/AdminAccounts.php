<?php
declare(strict_types=1);
namespace Omnigo;

final class AdminAccounts
{
    public function __construct(private Database $db) {}

    /** Change addresses on the same accounts; keep passwords, roles and session identities. */
    public function changeEmails(array $profiles): array
    {
        return $this->db->transaction(function() use($profiles): array {
            if(!$this->db->one('SELECT id FROM install_state WHERE id=1'.$this->db->lock()))throw new \RuntimeException('Kurulum kaydı bulunamadı.');
            $result=[];
            foreach($profiles as $profile){
                $email=mb_strtolower(trim($profile['email']??''));
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>200||!is_int($profile['id']??null)||$profile['id']<1)throw new \InvalidArgumentException('Yönetici bilgileri geçersiz.');
                $user=$this->db->one('SELECT id,email,name,role,company_id FROM users WHERE id=?'.$this->db->lock(),[$profile['id']]);
                if(!$user||$user['role']!=='admin'||$user['company_id']!==null||$user['name']!==$profile['name']||!in_array($user['email'],[$profile['previousEmail'],$email],true))throw new \RuntimeException('Mevcut hesap eşleşmiyor.');
                $duplicate=$this->db->one('SELECT id FROM users WHERE email=?',[$email]);
                if($duplicate&&(int)$duplicate['id']!==(int)$user['id'])throw new \RuntimeException('E-posta başka bir hesapta kullanılıyor.');
                if($user['email']!==$email){
                    $this->db->run('UPDATE users SET email=? WHERE id=?',[$email,$user['id']]);
                    // Old-address recovery links must no longer authorize a password change.
                    $this->db->run('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
                }
                $result[]=['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$email];
            }
            return $result;
        });
    }

    /** Provision named accounts without changing existing credentials or sending mail. */
    public function ensure(array $profiles): array
    {
        return $this->db->transaction(function() use($profiles): array {
            if(!$this->db->one('SELECT id FROM install_state WHERE id=1'.$this->db->lock()))throw new \RuntimeException('Kurulum kaydı bulunamadı.');
            $result=[];
            foreach($profiles as $profile){
                $email=mb_strtolower(trim($profile['email']??''));
                $name=trim($profile['name']??'');
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>200||$name===''||mb_strlen($name)>100)throw new \InvalidArgumentException('Yönetici bilgileri geçersiz.');
                $existing=$this->db->one('SELECT id,name,role,company_id FROM users WHERE email=?',[$email]);
                if($existing){
                    if($existing['role']!=='admin'||$existing['company_id']!==null||$existing['name']!==$name||!(new Security($this->db,[]))->active((int)$existing['id']))throw new \RuntimeException('Mevcut hesap eşleşmiyor.');
                    $result[]=['id'=>(int)$existing['id'],'name'=>$name,'email'=>$email,'created'=>false];
                    continue;
                }
                // Discard the unpredictable initial secret; the owner sets a password via recovery.
                $hash=Security::hashPassword(bin2hex(random_bytes(32)));
                $this->db->run("INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,'admin',NULL)",[$email,$name,$hash]);
                $result[]=['id'=>$this->db->insertId(),'name'=>$name,'email'=>$email,'created'=>true];
            }
            return $result;
        });
    }
}
