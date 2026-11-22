<?php
declare(strict_types=1);
namespace Omnigo;

final class PasswordRecovery
{
    public const MESSAGE='Hesap uygunsa şifre yenileme bağlantısı e-posta adresine gönderilecektir.';
    public function __construct(private Database $db,private array $config) {}
    public function request(string $email,?array $actor=null): void
    {
        $mail=new MailQueue($this->db,$this->config);
        if (!$mail->ready()) Http::fail(503,'E-posta hizmeti henüz etkin değil. Omnigo ekibiyle iletişime geçin.');
        $security=new Security($this->db,$this->config);
        foreach (['reset-ip:'.($_SERVER['REMOTE_ADDR']??'local'),'reset-email:'.$email] as $key) $security->consumeAttempt(hash('sha256',$key));
        $user=$this->db->one('SELECT u.*,COALESCE(c.active,1) AS active FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.email=?',[$email]);
        if (!$user || !(int)$user['active'] || (!$actor && !empty($this->config['portal_role']) && $user['role']!==$this->config['portal_role'])) return;
        $origin=$user['role']==='admin'?'https://yonetim.omnigoglobal.com':'https://portal.omnigoglobal.com';
        $token=bin2hex(random_bytes(32));
        // Fragment avoids placing reset secrets in HTTP URLs and server logs.
        $link=$origin.'/#reset='.$token;
        $payload=$mail->encrypt(['to'=>$user['email'],'link'=>$link,'tokenHash'=>hash('sha256',$token)]);
        $this->db->transaction(function() use($user,$actor,$token,$payload) {
            $current=$this->db->one('SELECT email FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            $control=$this->db->one('SELECT active FROM account_controls WHERE user_id=?',[$user['id']]);
            if (!$current || $current['email']!==$user['email'] || ($control && !(int)$control['active'])) return;
            $this->db->run('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
            $this->db->run("UPDATE mail_outbox SET state='cancelled',payload=NULL WHERE user_id=? AND state IN ('pending','running') AND id NOT LIKE 'health-%' AND id NOT LIKE 'privacy-delete-%'",[$user['id']]);
            $this->db->run('INSERT INTO password_resets(token_hash,user_id,expires) VALUES(?,?,?)',[hash('sha256',$token),$user['id'],time()+1800]);
            $this->db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",[bin2hex(random_bytes(32)),$user['id'],$payload,time(),gmdate('c')]);
            if ($actor && $user['company_id']) (new CustomerManagement($this->db))->audit($actor,(int)$user['company_id'],'Şifre yenileme e-postası istendi',(int)$user['id']);
        });
    }
    public function complete(array $data): void
    {
        $token=$data['token']??'';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) Http::fail(400,'Bağlantı geçersiz veya süresi dolmuş.');
        $account=Validation::account(['email'=>'validation@example.test','name'=>'Kontrol','password'=>$data['password']??null]);
        $hash=Security::hashPassword($account['password']);
        $this->db->transaction(function() use($token,$hash) {
            $reset=$this->db->one('SELECT * FROM password_resets WHERE token_hash=?',[hash('sha256',$token)]);
            if (!$reset) Http::fail(400,'Bağlantı geçersiz veya süresi dolmuş.');
            $user=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$reset['user_id']]);
            $reset=$this->db->one('SELECT * FROM password_resets WHERE token_hash=? AND expires>?',[hash('sha256',$token),time()]);
            $control=$this->db->one('SELECT active FROM account_controls WHERE user_id=?',[$user['id']]);
            if (!$reset || ($control && !(int)$control['active'])) Http::fail(400,'Bağlantı geçersiz veya süresi dolmuş.');
            PortalAccess::assertRole($this->config,$user['role']);
            $this->db->run('UPDATE users SET password=? WHERE id=?',[$hash,$user['id']]);
            (new CustomerManagement($this->db))->revoke((int)$user['id']);
            if ($user['company_id']) (new CustomerManagement($this->db))->audit($user,(int)$user['company_id'],'Müşteri şifresi yenilendi',(int)$user['id']);
        });
    }
}
