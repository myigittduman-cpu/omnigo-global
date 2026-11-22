<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared second password; a fixed user lease survives navigation and re-login. */
final class MarketplaceGate
{
    private const LEASE_SECONDS=900;
    public function __construct(private Database $db,private ?\Closure $clock=null){}
    private function now():int{return $this->clock?($this->clock)():time();}
    private function userKey(array $user):string
    {
        $row=$this->db->one('SELECT password_hash FROM marketplace_admin_lock WHERE id=1');
        return hash('sha256','management-user-v2:'.$user['id'].':'.($row['password_hash']??''));
    }
    public static function install(Database $db):void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS marketplace_admin_lock (id INT PRIMARY KEY,password_hash VARCHAR(255) NOT NULL,actor_id INT NOT NULL,updated VARCHAR(35) NOT NULL)'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS marketplace_admin_grants (session_key VARCHAR(64) NOT NULL,company_id INT NOT NULL,expires BIGINT NOT NULL,PRIMARY KEY(session_key,company_id))'.$tail);
    }
    public static function protects(string $path,string $method='GET'):bool
    {
        if($path==='/api/management/logo'&&$method==='GET')return false;
        if($path==='/api/users'&&$method==='POST')return true;
        if(in_array($path,['/api/management/new-records','/api/management/menu-labels','/api/management/role-defaults'],true))return true;
        return (bool)preg_match('#^/api/(?:management/(?:marketplaces(?:-bulk|/[a-z0-9]+)?|stores(?:/\d+)?|permissions(?:-bulk)?|permission-template|customer|initial-password|logo|users/\d+(?:/(?:reset|sessions|password))?)|admin/users(?:/\d+(?:/(?:portrait|sessions))?)?|integrations/(?:koctas|hepsiburada|n11|pazarama|pttavm|idefix|trendyol)(?:/credentials)?)$#D',$path);
    }
    public static function globalScope(string $path):bool{return str_starts_with($path,'/api/admin/users')||in_array($path,['/api/management/permissions-bulk','/api/management/permission-template','/api/management/role-defaults'],true);}
    private static function session(array $user):string
    {
        Security::admin($user);$token=$user['token']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))Http::fail(401,'Oturum doğrulanamadı.');return $token;
    }
    public function status(array $user,int $company,?string $proof=null):array
    {
        $session=self::session($user);$now=$this->now();
        $grant=$this->db->one('SELECT g.expires FROM marketplace_admin_grants g JOIN sessions s ON s.token=? WHERE g.session_key=? AND g.company_id=0 AND s.user_id=? AND s.expires>? AND g.expires>?',[$session,$this->userKey($user),$user['id'],$now,$now]);
        return ['configured'=>(bool)$this->db->one('SELECT id FROM marketplace_admin_lock WHERE id=1'),'granted'=>$grant!==null,'expiresAt'=>(int)($grant['expires']??0),'serverNow'=>$now];
    }
    public function require(array $user,int $company):void
    {
        if(!$this->status($user,$company)['granted'])Http::fail(423,'Yönetim alanı kilitli. Omnigo Admin şifresiyle doğrulayın.');
    }
    public function workspaceAccess(array $user,int $company):array
    {
        self::session($user);
        if($company<1||!$this->db->one('SELECT id FROM companies WHERE id=?',[$company]))Http::fail(404,'Müşteri bulunamadı.');
        $status=$this->status($user,$company);
        if(!$status['granted'])Http::fail(423,'Müşteri değiştirmek için Omnigo Admin şifresiyle doğrulayın.');
        return ['allowed'=>true,'companyId'=>$company,'expiresAt'=>$status['expiresAt'],'serverNow'=>$status['serverNow']];
    }
    public function revoke(array $user,int $company,string $proof):void
    {
        self::session($user);
        $this->db->run('DELETE FROM marketplace_admin_grants WHERE session_key=? AND company_id=0',[$this->userKey($user)]);
    }
    public function unlock(array $user,int $company,array $data):array
    {
        $session=self::session($user);$password=$data['password']??null;
        if(!is_string($password)||strlen($password)>512||strlen($password)<1)Http::fail(400,'Omnigo Admin şifresini girin.');
        return TransactionRetry::run($this->db,function()use($user,$company,$session,$password,$data){
            $now=$this->now();
            $active=$this->db->one('SELECT expires FROM sessions WHERE token=? AND user_id=? AND expires>?',[$session,$user['id'],$now]);
            if(!$active)Http::fail(401,'Giriş oturumunuz sona erdi. Yeniden giriş yapın.');
            $this->db->one('SELECT id FROM install_state WHERE id=1'.$this->db->lock());
            $row=$this->db->one('SELECT password_hash FROM marketplace_admin_lock WHERE id=1');
            if(!$row){
                if(($data['setup']??false)!==true||!is_string($data['confirmation']??null)||!hash_equals($password,$data['confirmation'])||mb_strlen($password)<12)Http::fail(400,'İlk şifre en az 12 karakter olmalı ve iki alan aynı olmalı.');
                $this->db->run('INSERT INTO marketplace_admin_lock(id,password_hash,actor_id,updated) VALUES(1,?,?,?)',[Security::hashPassword($password),$user['id'],gmdate('c')]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'Omnigo Admin ek şifresi oluşturuldu',null,null,json_encode(['configured'=>true]),gmdate('c')]);
            }elseif(!Security::verifyPassword($password,$row['password_hash']))Http::fail(403,'Omnigo Admin şifresi doğru değil.');
            elseif(($data['setup']??false)===true)Http::fail(409,'Ek şifre başka bir yetkili tarafından oluşturulmuş. Mevcut şifreyle tekrar doğrulayın.');
            $this->db->run('DELETE FROM marketplace_admin_grants WHERE expires<=?',[$now]);
            $proof=bin2hex(random_bytes(32));$key=$this->userKey($user);$expires=$now+self::LEASE_SECONDS;
            $this->db->run('DELETE FROM marketplace_admin_grants WHERE session_key=? AND company_id=0',[$key]);
            $this->db->run('INSERT INTO marketplace_admin_grants(session_key,company_id,expires) VALUES(?,0,?)',[$key,$expires]);
            return ['granted'=>true,'grant'=>$proof,'expiresAt'=>$expires,'serverNow'=>$now];
        });
    }
}
