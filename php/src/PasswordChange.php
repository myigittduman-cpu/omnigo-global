<?php
declare(strict_types=1);
namespace Omnigo;

/** Authenticated password rotation: current password plus a session-bound email code. */
final class PasswordChange
{
    public function __construct(private Database $db,private array $config){}
    private function budget(array $user,string $operation): void
    {
        $security=new Security($this->db,$this->config);
        foreach(['user:'.$user['id'],'ip:'.($_SERVER['REMOTE_ADDR']??'local')] as $key)$security->consumeAttempt(hash('sha256','password-change:'.$operation.':'.$key));
    }
    private function current(array $user): array
    {
        $current=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
        if(!$current||!(new Security($this->db,$this->config))->active((int)$user['id'])||!$this->db->one('SELECT token FROM sessions WHERE token=? AND user_id=? AND expires>?',[$user['token'],$user['id'],time()]))Http::fail(401,'Oturumunuz sona erdi. Yeniden giriş yapın.');
        PortalAccess::assertRole($this->config,$current['role']);return $current;
    }
    private function codeHash(string $tokenHash,string $code): string
    {
        return hash_hmac('sha256',$tokenHash.':'.$code,base64_decode($this->config['mail']['key'],true));
    }
    private function enqueue(array $user,string $tokenHash,string $code): void
    {
        $mail=new MailQueue($this->db,$this->config);
        $payload=$mail->encrypt(['type'=>'password-change','to'=>$user['email'],'userId'=>(int)$user['id'],'tokenHash'=>$tokenHash,'code'=>$code]);
        $this->db->run("UPDATE mail_outbox SET state='cancelled',payload=NULL WHERE user_id=? AND id LIKE 'password-change-%' AND state IN ('pending','running')",[$user['id']]);
        $this->db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",['password-change-'.bin2hex(random_bytes(16)),$user['id'],$payload,time(),gmdate('c')]);
    }
    public function request(array $user,array $data): array
    {
        $this->budget($user,'request');
        if(!(new MailQueue($this->db,$this->config))->ready()||!empty($this->config['store_review']))Http::fail(503,'Şifre değişikliği için e-posta hizmeti şu anda kullanılamıyor.');
        $currentPassword=$data['currentPassword']??null;
        if(!is_string($currentPassword)||strlen($currentPassword)>128||!Security::verifyPassword($currentPassword,$user['password']))Http::fail(400,'Mevcut şifreniz doğru değil.');
        $account=Validation::account(['email'=>'validation@example.test','name'=>'Kontrol','password'=>$data['newPassword']??null]);
        if(!is_string($data['confirmPassword']??null)||!hash_equals($account['password'],$data['confirmPassword']))Http::fail(400,'Yeni şifreler aynı olmalı.');
        if(hash_equals($currentPassword,$account['password']))Http::fail(400,'Yeni şifreniz mevcut şifrenizden farklı olmalı.');
        $hash=Security::hashPassword($account['password']);$token=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$token);$code=(string)random_int(100000,999999);
        $this->db->transaction(function()use($user,$hash,$tokenHash,$code){
            $current=$this->current($user);
            if(!hash_equals(AdminMfaStore::credentialTag($user),AdminMfaStore::credentialTag($current)))Http::fail(409,'Hesap bilgileri değişti. Yeniden giriş yapın.');
            $old=$this->db->one('SELECT sent_at FROM password_change_challenges WHERE user_id=?',[$user['id']]);
            if($old&&(int)$old['sent_at']>time()-60)Http::fail(429,'Yeni kod için bir dakika bekleyin.');
            $this->db->run('DELETE FROM password_change_challenges WHERE user_id=? OR expires<=?',[$user['id'],time()]);
            $this->db->run('INSERT INTO password_change_challenges(user_id,token_hash,session_token,credential_tag,password_hash,code_hash,expires,attempts,sent_at) VALUES(?,?,?,?,?,?,?,0,?)',[$user['id'],$tokenHash,$user['token'],AdminMfaStore::credentialTag($current),$hash,$this->codeHash($tokenHash,$code),time()+600,time()]);
            $this->enqueue($current,$tokenHash,$code);
        });
        [$local,$domain]=explode('@',$user['email'],2);
        return ['token'=>$token,'email'=>mb_substr($local,0,1).'***@'.$domain,'expiresIn'=>600];
    }
    private function challenge(array $user,mixed $token,array $current): array
    {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))Http::fail(400,'Doğrulama işlemi geçersiz. Yeni kod isteyin.');
        $row=$this->db->one('SELECT * FROM password_change_challenges WHERE user_id=?',[$user['id']]);
        if(!$row||$row['expires']<=time()||!hash_equals($row['token_hash'],hash('sha256',$token))||!hash_equals($row['session_token'],$user['token'])||!hash_equals($row['credential_tag'],AdminMfaStore::credentialTag($current)))Http::fail(400,'Kod geçersiz veya süresi dolmuş. Yeni kod isteyin.');
        return $row;
    }
    public function resend(array $user,array $data): array
    {
        $this->budget($user,'resend');
        if(!(new MailQueue($this->db,$this->config))->ready())Http::fail(503,'E-posta hizmeti şu anda kullanılamıyor.');
        return $this->db->transaction(function()use($user,$data){
            $current=$this->current($user);$row=$this->challenge($user,$data['token']??null,$current);
            if($row['sent_at']>time()-60)Http::fail(429,'Yeni kod için bir dakika bekleyin.');
            $code=(string)random_int(100000,999999);
            $this->db->run('UPDATE password_change_challenges SET code_hash=?,sent_at=? WHERE user_id=?',[$this->codeHash($row['token_hash'],$code),time(),$user['id']]);
            $this->enqueue($current,$row['token_hash'],$code);return ['sent'=>true];
        });
    }
    public function verify(array $user,array $data): void
    {
        $valid=$this->db->transaction(function()use($user,$data){
            $current=$this->current($user);$row=$this->challenge($user,$data['token']??null,$current);$code=$data['code']??'';
            if(!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($row['code_hash'],$this->codeHash($row['token_hash'],$code))){
                $this->db->run('UPDATE password_change_challenges SET attempts=attempts+1 WHERE user_id=?',[$user['id']]);return false;
            }
            $this->db->run('UPDATE users SET password=? WHERE id=?',[$row['password_hash'],$user['id']]);
            (new CustomerManagement($this->db))->revoke((int)$user['id']);
            $this->db->run('UPDATE notification_devices SET enabled=0 WHERE user_id=?',[$user['id']]);
            $this->db->run('DELETE FROM password_change_challenges WHERE user_id=?',[$user['id']]);
            if($user['company_id'])(new CustomerManagement($this->db))->audit($user,(int)$user['company_id'],'Hesap şifresi e-posta koduyla değiştirildi',(int)$user['id']);
            return true;
        });
        if(!$valid)Http::fail(400,'Onay kodu doğru değil. Kodu kontrol edin.');
    }
    public function mailMessage(array $payload): ?array
    {
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$payload['userId']??0]);
        $row=$this->db->one('SELECT * FROM password_change_challenges WHERE user_id=?',[$payload['userId']??0]);
        if(!$user||!$row||$user['email']!==($payload['to']??null)||$row['expires']<=time()||!(new Security($this->db,$this->config))->active((int)$user['id'])||!hash_equals($row['credential_tag'],AdminMfaStore::credentialTag($user))||!hash_equals($row['token_hash'],$payload['tokenHash']??'')||!hash_equals($row['code_hash'],$this->codeHash($row['token_hash'],$payload['code']??''))||!$this->db->one('SELECT token FROM sessions WHERE token=? AND user_id=? AND expires>?',[$row['session_token'],$user['id'],time()]))return null;
        return ['subject'=>'Omnigo Global — Şifre değişikliği onay kodu','body'=>"Şifre değişikliği onay kodunuz: ".$payload['code']."\n\nKod 10 dakika geçerlidir. Şifreniz ancak panelde bu kodla onayladığınızda değişir. Bu işlemi siz başlatmadıysanız kodu paylaşmayın.\n\nOmnigo Global"];
    }
}
