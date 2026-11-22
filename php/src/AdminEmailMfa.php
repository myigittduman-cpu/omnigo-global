<?php
declare(strict_types=1);
namespace Omnigo;

/** Password first, then one single-use email code; no full session exists between factors. */
final class AdminEmailMfa
{
    public const TTL=600;
    private AdminMfaStore $store;
    private MailQueue $mail;
    public function __construct(private Database $db,private array $config)
    {
        $this->store=new AdminMfaStore($db,$config);$this->mail=new MailQueue($db,$config);
    }
    public function status(array $user): array
    {
        Security::admin($user);
        return ['available'=>$this->store->available(),'enabled'=>$this->store->enabled($user),'mailReady'=>$this->mail->ready(),'email'=>self::mask($user['email'])];
    }
    private static function mask(string $email): string
    {
        [$name,$domain]=explode('@',$email,2);return mb_substr($name,0,2).'***@'.$domain;
    }
    private function ready(): void
    {
        if(!$this->store->available())Http::fail(503,'İki aşamalı doğrulama henüz kullanıma hazır değil.');
        if(!$this->mail->ready())Http::fail(503,'Doğrulama e-postası şu anda gönderilemiyor. Lütfen daha sonra tekrar deneyin.');
    }
    private function budget(string $action,int $user): void
    {
        $security=new Security($this->db,$this->config);
        foreach(['ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),'user:'.$user] as $key)$security->consumeAttempt(hash('sha256','mfa:'.$action.':'.$key));
    }
    private function codeHash(string $id,string $code): string
    {
        return hash_hmac('sha256',$id.':'.$code,base64_decode($this->config['mail']['key'],true));
    }
    private function queue(string $id,array $user,string $purpose,string $code): void
    {
        $payload=$this->mail->encrypt(['type'=>'admin-mfa','challenge'=>$id,'to'=>$user['email'],'code'=>$code,'purpose'=>$purpose]);
        $this->db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",['mfa-'.bin2hex(random_bytes(24)),$user['id'],$payload,time(),gmdate('c')]);
    }
    public function start(array $user,string $purpose,bool $persistent=false): array
    {
        Security::admin($user);PortalAccess::assertRole($this->config,'admin');$this->ready();
        if(!in_array($purpose,['login','enable','disable'],true))Http::fail(400,'Doğrulama işlemi geçersiz.');
        $this->budget('send',(int)$user['id']);
        return $this->db->transaction(function()use($user,$purpose,$persistent){
            $current=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            if(!$current||AdminMfaStore::credentialTag($current)!==AdminMfaStore::credentialTag($user)||(new Security($this->db,$this->config))->active((int)$user['id'])!==true)Http::fail(401,'Hesap bilgileri değişti. Yeniden giriş yapın.');
            $enabled=$this->store->enabled($current);
            if(($purpose==='enable'&&$enabled)||($purpose!=='enable'&&!$enabled))Http::fail(409,'Güvenlik tercihi değişti. Sayfayı yenileyin.');
            if($purpose!=='login'&&!$this->db->one('SELECT token FROM sessions WHERE token=? AND user_id=? AND expires>?',[$user['token']??'',$user['id'],time()]))Http::fail(401,'Yeniden giriş yapın.');
            $recent=$this->db->one('SELECT sent_at FROM admin_mfa_challenges WHERE user_id=? AND expires>?',[$user['id'],time()]);
            if($recent&&(int)$recent['sent_at']>time()-60)Http::fail(429,'Yeni kod için bir dakika bekleyin.');
            $this->store->cancelChallenges((int)$user['id']);
            $this->db->run('DELETE FROM admin_mfa_challenges WHERE expires<=?',[time()]);
            $token=bin2hex(random_bytes(32));$id=hash('sha256',$token);$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);$expires=time()+self::TTL;
            $this->db->run('INSERT INTO admin_mfa_challenges(token_hash,user_id,purpose,code_hash,credential_tag,origin,session_token,persistent,expires,attempts,sent_at) VALUES(?,?,?,?,?,?,?,?,?,0,?)',[$id,$user['id'],$purpose,$this->codeHash($id,$code),AdminMfaStore::credentialTag($current),$this->config['origin'],$purpose==='login'?null:$user['token'],$persistent?1:0,$expires,time()]);
            $this->queue($id,$current,$purpose,$code);
            return ['mfaRequired'=>true,'challenge'=>$token,'email'=>self::mask($current['email']),'expiresAt'=>$expires,'resendAt'=>time()+60];
        });
    }
    public function requestSetting(array $user,array $data): array
    {
        Security::admin($user);$this->budget('password',(int)$user['id']);
        if(!is_string($data['password']??null)||strlen($data['password'])>128||!Security::verifyPassword($data['password'],$user['password']))Http::fail(401,'Mevcut şifrenizi kontrol edin.');
        if(!is_bool($data['enabled']??null))Http::fail(400,'Güvenlik tercihi geçersiz.');
        return $this->start($user,$data['enabled']?'enable':'disable');
    }
    private function challenge(array $data,?array $actor): array
    {
        $token=$data['challenge']??null;
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))Http::fail(400,'Doğrulama isteği geçersiz.');
        $row=$this->db->one('SELECT * FROM admin_mfa_challenges WHERE token_hash=?',[hash('sha256',$token)]);
        if(!$row||$row['origin']!==$this->config['origin']||($actor===null?$row['purpose']!=='login':($row['purpose']==='login'||(int)$row['user_id']!==(int)$actor['id']||$row['session_token']!==$actor['token'])))Http::fail(401,'Doğrulama süresi doldu. İşlemi yeniden başlatın.');
        return $row;
    }
    private function valid(array $row,array $user): bool
    {
        $enabled=$this->store->enabled($user);
        return (int)$row['expires']>time()&&(int)$row['attempts']<5&&$user['role']==='admin'&&
            hash_equals($row['credential_tag'],AdminMfaStore::credentialTag($user))&&(new Security($this->db,$this->config))->active((int)$user['id'])&&
            ($row['purpose']==='enable'?!$enabled:$enabled)&&
            ($row['purpose']==='login'||(bool)$this->db->one('SELECT token FROM sessions WHERE token=? AND user_id=? AND expires>?',[$row['session_token'],$user['id'],time()]));
    }
    public function resend(array $data,?array $actor=null): array
    {
        $this->ready();$row=$this->challenge($data,$actor);$this->budget('send',(int)$row['user_id']);
        return $this->db->transaction(function()use($data,$actor,$row){
            $user=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$row['user_id']]);$row=$this->challenge($data,$actor);
            if(!$user||!$this->valid($row,$user))Http::fail(401,'Kodun süresi veya deneme hakkı doldu. İşlemi yeniden başlatın.');
            if((int)$row['sent_at']>time()-60)Http::fail(429,'Yeni kod için bir dakika bekleyin.');
            $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
            $this->db->run('UPDATE admin_mfa_challenges SET code_hash=?,sent_at=? WHERE token_hash=?',[$this->codeHash($row['token_hash'],$code),time(),$row['token_hash']]);
            $this->queue($row['token_hash'],$user,$row['purpose'],$code);
            return ['resendAt'=>time()+60,'expiresAt'=>(int)$row['expires']];
        });
    }
    public function verify(array $data,?array $actor,callable $complete): array
    {
        $this->ready();$row=$this->challenge($data,$actor);$this->budget('verify',(int)$row['user_id']);
        if(!is_string($data['code']??null)||!preg_match('/^\d{6}$/D',$data['code']))Http::fail(400,'E-postadaki 6 haneli kodu yazın.');
        $result=$this->db->transaction(function()use($row,$data,$actor,$complete){
            $user=$this->db->one('SELECT * FROM users WHERE id=?'.$this->db->lock(),[$row['user_id']]);$row=$this->challenge($data,$actor);
            if(!$user||!$this->valid($row,$user))return ['failure'=>'Kodun süresi veya deneme hakkı doldu. İşlemi yeniden başlatın.'];
            if(!hash_equals($row['code_hash'],$this->codeHash($row['token_hash'],$data['code']))){
                $this->db->run('UPDATE admin_mfa_challenges SET attempts=attempts+1 WHERE token_hash=?',[$row['token_hash']]);
                return ['failure'=>(int)$row['attempts']>=4?'Deneme hakkı doldu. İşlemi yeniden başlatın.':'Doğrulama kodu hatalı.'];
            }
            $enabled=$row['purpose']!=='disable';
            if($row['purpose']!=='login')$this->store->change($user,$enabled);else $this->store->cancelChallenges((int)$user['id']);
            return $complete($user,(bool)$row['persistent'],$enabled);
        });
        // Wrong-code attempts must commit, rather than being rolled back by HttpError.
        if(isset($result['failure']))Http::fail(401,$result['failure']);return $result;
    }
    public function mailMessage(array $payload): ?array
    {
        if(!$this->store->available())return null;
        $row=$this->db->one('SELECT * FROM admin_mfa_challenges WHERE token_hash=?',[$payload['challenge']??'']);
        $user=$row?$this->db->one('SELECT * FROM users WHERE id=?',[$row['user_id']]):null;
        if(!$row||!$user||!$this->valid($row,$user)||$payload['to']!==$user['email']||!hash_equals($row['code_hash'],$this->codeHash($row['token_hash'],$payload['code'])))return null;
        $action=match($row['purpose']){'enable'=>'iki aşamalı doğrulamayı açma','disable'=>'iki aşamalı doğrulamayı kapatma',default=>'yönetici girişi'};
        return ['subject'=>'Omnigo Global — Yönetici doğrulama kodu','body'=>"$action işlemi için doğrulama kodunuz:\n\n".$payload['code']."\n\nKod yalnızca bir kez kullanılabilir ve talep edildiği andan itibaren 10 dakika geçerlidir. Kodu yalnızca Omnigo yönetici uygulamasındaki doğrulama alanına yazın; kimseyle paylaşmayın.\n\nBu işlemi siz başlatmadıysanız hesabınızın şifresini değiştirin.\n\nOmnigo Global"];
    }
}
