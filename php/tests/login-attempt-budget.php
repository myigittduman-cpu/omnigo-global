<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,AdminMfaSchema,LoginAttemptBudget,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,'Beklenmeyen hata: '.$e->getMessage());return;}throw new RuntimeException('İşlem reddedilmedi.');}
function database():Database{$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AdminMfaSchema::install($db);return $db;}

// Reproduce the user's flow: repeated valid password entries, two mistakes, then a new MFA code.
$db=database();$password='Local-Password-Only-0991!';$email='admin@example.invalid';
$db->run("INSERT INTO users VALUES(1,?,'Test',?,'admin',NULL)",[$email,Security::hashPassword($password)]);
$db->run('INSERT INTO admin_mfa_accounts(user_id,email,enabled,updated) VALUES(?,?,1,?)',[1,$email,time()]);
$config=['origin'=>'https://admin.example.invalid','portal_role'=>'admin','admin_mfa'=>true,'mail'=>['enabled'=>true,'from'=>'test@example.invalid','transport'=>'mail','key'=>base64_encode(str_repeat('k',32))]];
$_SERVER['REMOTE_ADDR']='isolated-login-test';$security=new Security($db,$config);
foreach(['ip:'.$_SERVER['REMOTE_ADDR'],'email:'.$email,'login-failure:ip:'.$_SERVER['REMOTE_ADDR'],'login-failure:email:'.$email] as $oldKey)$db->run('INSERT INTO login_attempts VALUES(?,10,?)',[hash('sha256',$oldKey),time()+900]);
for($i=0;$i<8;$i++){$result=$security->login(['email'=>$email,'password'=>$password]);check($result['mfaRequired']===true,'Doğru parola e-posta doğrulamasına geçmeli.');$db->run('UPDATE admin_mfa_challenges SET sent_at=0');}
for($i=0;$i<2;$i++)denied(fn()=>$security->login(['email'=>$email,'password'=>'wrong']),401);
$result=$security->login(['email'=>$email,'password'=>$password]);
check($result['mfaRequired']===true&&!isset($result['user'],$result['csrf']),'İki yanlış şifreden sonra doğru şifre kod adımını açmalı; oturum koddan önce açılmamalı.');
check((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE state='pending'")['n']===1,'Yeni doğrulama e-postası sıraya alınmalı.');
check((int)$db->one('SELECT attempt_count FROM login_attempts WHERE attempt_key=?',[hash('sha256','login-short:pair:'.$_SERVER['REMOTE_ADDR'].':'.$email)])['attempt_count']===0,'Doğru şifre bu hesabın kısa süreli hata sayacını sıfırlamalı.');
check((int)$db->one('SELECT COUNT(*) n FROM sessions')['n']===0,'Kod doğrulanmadan gerçek oturum oluşmamalı.');

// Verify accounting separately from password hashing and email send limits.
$db=database();$guard=new LoginAttemptBudget($db);$user=['id'=>1];
for($i=0;$i<30;$i++)check($guard->verify('first@test.invalid','shared-ip',fn()=>$user)===$user,'Doğru şifreler hata sınırını doldurmamalı.');
for($i=0;$i<9;$i++)check($guard->verify('first@test.invalid','shared-ip',fn()=>null)===null,'Yanlış deneme kaydedilmeli.');
check($guard->verify('second@test.invalid','shared-ip',fn()=>$user)===$user,'Aynı IP üzerindeki doğru giriş çalışmalı.');
check($guard->verify('first@test.invalid','shared-ip',fn()=>null)===null,'Onuncu başarısız deneme kaydedilmeli.');
$called=false;denied(fn()=>$guard->verify('first@test.invalid','shared-ip',function()use(&$called,$user){$called=true;return $user;}),429);
check(!$called,'Aynı hesap için kısa aralıkta sınırsız parola kontrolü çalışmamalı.');
check($guard->verify('third@test.invalid','shared-ip',fn()=>$user)===$user,'Aynı ağdaki bağımsız hesap ortak IP hatalarıyla kilitlenmemeli.');
$window=$db->one('SELECT reset_at FROM login_attempts WHERE attempt_key=?',[hash('sha256','login-short:email:first@test.invalid')]);
check((int)$window['reset_at']<=time()+30,'Bekleme en fazla 30 saniye olmalı.');
try{$guard->verify('first@test.invalid','shared-ip',fn()=>null);}catch(HttpError $error){check(str_contains($error->getMessage(),'saniye')&&!str_contains($error->getMessage(),'dakika'),'Giriş uzun süreli kilit mesajı göstermemeli.');}
$db->run('UPDATE login_attempts SET reset_at=?',[time()-1]);
check($guard->verify('first@test.invalid','shared-ip',fn()=>$user)===$user,'Süresi dolmuş kısıtlama doğru girişi engellememeli.');
for($i=0;$i<2;$i++)$guard->verify('first@test.invalid','new-ip',fn()=>null);
$guard->verify('first@test.invalid','new-ip',fn()=>$user);
check((int)$db->one('SELECT attempt_count FROM login_attempts WHERE attempt_key=?',[hash('sha256','login-short:email:first@test.invalid')])['attempt_count']===0,'Doğru şifre hesabın hata sayacını sıfırlamalı.');

$db=database();$guard=new LoginAttemptBudget($db);
for($i=0;$i<10;$i++)$guard->verify('shared@test.invalid','different-ip-'.$i,fn()=>null);
denied(fn()=>$guard->verify('shared@test.invalid','another-ip',fn()=>$user),429);
check($guard->verify('unrelated@test.invalid','unrelated-ip',fn()=>$user)===$user,'Bağımsız hesap ve IP etkilenmemeli.');
echo "Giriş denemeleri: eski 15 dakika kilitlerinden geçiş, ortak IP hesap izolasyonu, en fazla 30 saniye bekleme, dağıtık hatalı deneme sınırı, doğru giriş ve MFA kontrolleri geçti.\n";
