<?php
declare(strict_types=1);
namespace Omnigo;

final class Security
{
    public function __construct(private Database $db, private array $config) { ApplicationAdditiveSchema::ensure($db); AccountDirectorySchema::ensure($db); }
    public static function publicUser(array $user): array
    {
        return ['id'=>(int)$user['id'],'email'=>$user['email'],'name'=>$user['name'],'role'=>$user['role'],'companyId'=>isset($user['company_id'])?(int)$user['company_id']:null,'operatorName'=>$user['role']==='client'?$user['name']:null,'username'=>$user['username']??null,'avatarVersion'=>$user['avatarVersion']??null,'mustChangePassword'=>!empty($user['mustChangePassword'])];
    }
    public static function hashPassword(string $password): string
    {
        // SHA-384 avoids bcrypt's 72-byte truncation; the encoded input is 64 bytes.
        return password_hash(base64_encode(hash('sha384',$password,true)),PASSWORD_BCRYPT,['cost'=>12]);
    }
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify(base64_encode(hash('sha384',$password,true)),$hash);
    }
    public static function admin(array $user): void { if ($user['role'] !== 'admin') Http::fail(403,'Bu işlem için yönetici yetkisi gerekiyor.'); }
    public function company(array $user, mixed $id): int
    {
        if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1) Http::fail(400,'Firma seçin.');
        if ($user['role'] !== 'admin' && (int)$id !== (int)$user['company_id']) Http::fail(403,'Bu firmaya erişemezsiniz.');
        if (!$this->db->one('SELECT id FROM companies WHERE id=?',[(int)$id])) Http::fail(404,'Firma bulunamadı.');
        return (int)$id;
    }
    private function cookie(string $token, int $expires): void
    {
        setcookie(PortalAccess::cookieName($this->config),$token,['expires'=>$expires,'path'=>'/','secure'=>str_starts_with($this->config['origin'],'https://'),'httponly'=>true,'samesite'=>'Strict']);
    }
    public function user(): array
    {
        $token = $_COOKIE[PortalAccess::cookieName($this->config)] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) Http::fail(401,'Giriş yapmanız gerekiyor.');
        $user = $this->db->one('SELECT u.*,s.token,s.csrf,s.expires AS session_expires FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.token=? AND s.expires>?',[hash('sha256',$token),time()]);
        if (!$user || !$this->active((int)$user['id'])) Http::fail(401,'Oturumunuz sona erdi. Tekrar giriş yapın.');
        PortalAccess::assertRole($this->config, $user['role'], 401);
        (new AdminMfaStore($this->db,$this->config))->assertSession($user);
        if (SessionLifetime::persistent((int)$user['session_expires'])) $this->cookie($token,SessionLifetime::cookieExpiry(true));
        $user['mustChangePassword']=CustomerInitialPassword::required($this->db,$user);
        $user['username']=LoginIdentity::read($this->db,(int)$user['id']);
        $user['avatarVersion']=(new AccountPortraitSource($this->db))->metadata($user);
        return (new ClientSessionOperator($this->db))->resolve($user,null);
    }
    public function csrf(array $user): void
    {
        if (!hash_equals($user['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) Http::fail(403,'İşlem doğrulanamadı. Sayfayı yenileyin.');
    }
    public function active(int $id): bool
    {
        $control=$this->db->one('SELECT active FROM account_controls WHERE user_id=?',[$id]);
        return (!$control || (int)$control['active']===1)&&!$this->db->one('SELECT user_id FROM account_profiles WHERE user_id=? AND deleted_at IS NOT NULL',[$id]);
    }
    public function consumeAttempt(string $key): void
    {
        $now = time();
        $this->db->transaction(function() use($key,$now) {
            $sql = $this->db->mysql() ? 'INSERT IGNORE INTO login_attempts(attempt_key,attempt_count,reset_at) VALUES(?,0,?)' : 'INSERT OR IGNORE INTO login_attempts(attempt_key,attempt_count,reset_at) VALUES(?,0,?)';
            $this->db->run($sql,[$key,$now+900]);
            $row = $this->db->one('SELECT * FROM login_attempts WHERE attempt_key=?'.$this->db->lock(),[$key]);
            $count = (int)$row['reset_at'] <= $now ? 0 : (int)$row['attempt_count'];
            if ($count >= 10) Http::fail(429,'Çok fazla giriş denemesi. 15 dakika sonra tekrar deneyin.');
            $this->db->run('UPDATE login_attempts SET attempt_count=?,reset_at=? WHERE attempt_key=?',[$count+1,(int)$row['reset_at']<=$now?$now+900:$row['reset_at'],$key]);
        });
    }
    public function login(array $data): array
    {
        if (isset($data['keepSession']) && !is_bool($data['keepSession'])) Http::fail(400,'Oturum tercihini kontrol edin.');
        $persistent = ($data['keepSession'] ?? false) === true;
        $expires = SessionLifetime::expiry($persistent);
        $mode=$data['loginType']??'email';$identifier=$data['identifier']??$data['email']??null;
        if(!in_array($mode,['email','username','auto'],true)||!is_string($identifier)||strlen($identifier)>1024||!is_string($data['password']??null)||strlen($data['password'])>128)Http::fail(400,'Giriş bilgilerini kontrol edin.');
        $candidate=LoginIdentity::find($this->db,$mode,$identifier);
        $budgetIdentity=$candidate['email']??($mode.':'.mb_strtolower(trim($identifier)));
        $ip=$_SERVER['REMOTE_ADDR']??'unknown';
        $user=(new LoginAttemptBudget($this->db))->verify($budgetIdentity,$ip,function()use($mode,$identifier,$data){
            $user=LoginIdentity::find($this->db,$mode,$identifier);
            $hash=$user['password']??'$2y$12$yFmTkWuqbVKDiMPssWn5eOVHxIOvDyCy91ozOmVUOTBNsj21LSy2i';
            $valid=self::verifyPassword($data['password'],$hash);
            return $user&&$valid&&$this->active((int)$user['id'])?$user:null;
        });
        if(!$user)Http::fail(401,'E-posta, kullanıcı adı veya şifre hatalı.');
        $user['mustChangePassword']=CustomerInitialPassword::required($this->db,$user);
        $user['username']=LoginIdentity::read($this->db,(int)$user['id']);
        PortalAccess::assertRole($this->config, $user['role']);
        if((new AdminMfaStore($this->db,$this->config))->enabled($user))return (new AdminEmailMfa($this->db,$this->config))->start($user,'login',$persistent);
        $token = bin2hex(random_bytes(32)); $csrf = bin2hex(random_bytes(32));
        $this->db->transaction(function() use($user,$token,$csrf,$expires) {
            $current=$this->db->one('SELECT password FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            if (!$current || $current['password']!==$user['password'] || !$this->active((int)$user['id'])) Http::fail(401,'Hesap bilgileri değişti. Yeniden giriş yapın.');
            if((new AdminMfaStore($this->db,$this->config))->enabled($user))Http::fail(401,'Güvenlik tercihi değişti. Yeniden giriş yapın.');
            PushSession::prune($this->db);
            $this->db->run('INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[hash('sha256',$token),$user['id'],$csrf,$expires]);
            AccountLoginActivity::record($this->db,(int)$user['id']);
        });
        $this->cookie($token,SessionLifetime::cookieExpiry($persistent));
        $user['avatarVersion']=(new AccountPortraitSource($this->db))->metadata($user);
        return ['user'=>self::publicUser($user),'csrf'=>$csrf,'demo'=>false];
    }
    public function logout(array $user): void
    {
        $this->db->run('UPDATE notification_devices SET enabled=0 WHERE user_id=? AND session_token=?',[$user['id'],$user['token']]);
        $this->db->run('DELETE FROM sessions WHERE token=?',[$user['token']]);
        $this->cookie('',time()-3600);
    }
    public function confirmMfa(array $data,?array $actor=null): array
    {
        PortalAccess::assertRole($this->config,'admin');
        $session=(new AdminEmailMfa($this->db,$this->config))->verify($data,$actor,function($user,$persistent,$verified){
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$csrf=bin2hex(random_bytes(32));$expires=SessionLifetime::expiry($persistent);
            PushSession::prune($this->db);
            $this->db->run('INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',[$hash,$user['id'],$csrf,$expires]);
            AccountLoginActivity::record($this->db,(int)$user['id']);
            if($verified)(new AdminMfaStore($this->db,$this->config))->markSession($hash,(int)$user['id'],$expires);
            $user['username']=LoginIdentity::read($this->db,(int)$user['id']);
            $user['avatarVersion']=(new AccountPortraitSource($this->db))->metadata($user);
            return ['cookie'=>$token,'expires'=>SessionLifetime::cookieExpiry($persistent),'user'=>self::publicUser($user),'csrf'=>$csrf,'demo'=>false,'mfaEnabled'=>$verified];
        });
        $this->cookie($session['cookie'],$session['expires']);unset($session['cookie'],$session['expires']);return $session;
    }
    public function sessionPreference(array $user, ?array $data = null): array
    {
        if ($data === null) return ['keepSession'=>SessionLifetime::persistent((int)$user['session_expires'])];
        if (!is_bool($data['keepSession']??null)) Http::fail(400,'Oturum tercihini kontrol edin.');
        if (!$data['keepSession']) {
            $this->logout($user);
            return ['keepSession'=>false,'signedOut'=>true];
        }
        $this->db->transaction(function() use($user) {
            $account=$this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            $session=$this->db->one('SELECT expires FROM sessions WHERE token=? AND user_id=?'.$this->db->lock(),[$user['token'],$user['id']]);
            if (!$account || !$session || (int)$session['expires']<=time() || !$this->active((int)$user['id'])) Http::fail(401,'Oturumunuz sona erdi. Tekrar giriş yapın.');
            (new AdminMfaStore($this->db,$this->config))->assertSession($user);
            $this->db->run('UPDATE sessions SET expires=? WHERE token=? AND user_id=?',[SessionLifetime::UNTIL_REVOKED,$user['token'],$user['id']]);
            if ($user['role']==='admin' && (new AdminMfaStore($this->db,$this->config))->enabled($user)) {
                $this->db->run('UPDATE admin_mfa_sessions SET expires=? WHERE token=? AND user_id=?',[SessionLifetime::UNTIL_REVOKED,$user['token'],$user['id']]);
            }
        });
        $this->cookie($_COOKIE[PortalAccess::cookieName($this->config)],SessionLifetime::cookieExpiry(true));
        return ['keepSession'=>true,'signedOut'=>false];
    }
}
