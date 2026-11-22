<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,SessionLifetime,AdminMfaSchema,AdminMfaStore,PortalAccess,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $fn):void{try{$fn();}catch(HttpError $e){check($e->getCode()===401,'Yetkisiz istek kodu');return;}throw new RuntimeException('Oturum reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AdminMfaSchema::install($db);\Omnigo\AccountSettingsSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$hash=Security::hashPassword('Test-Only-Strong-Password-957!');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici',?,'admin',NULL),(2,'client@example.test','Müşteri',?,'client',1)",[$hash,$hash]);
$db->run("INSERT INTO admin_mfa_accounts(user_id,email,enabled,updated) VALUES(1,'admin@example.test',1,?)",[time()]);
foreach(['admin'=>1,'client'=>2] as $role=>$id){
 $config=['origin'=>'https://'.$role.'.example.test','portal_role'=>$role,'admin_mfa'=>true];$security=new Security($db,$config);
 $raw=bin2hex(random_bytes(32));$token=hash('sha256',$raw);$expires=SessionLifetime::expiry(true);
 check($expires>time()+10*365*86400,'Kalıcı oturum 30 günle sınırlı olmamalı');
 $db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',$expires]);
 if($role==='admin')(new AdminMfaStore($db,$config))->markSession($token,$id,$expires);
 $_COOKIE[PortalAccess::cookieName($config)]=$raw;
 $user=$security->user();check($security->sessionPreference($user)['keepSession'],'Açık tercih okunmalı');
 check(SessionLifetime::cookieExpiry(true)<=time()+400*86400,'Tarayıcı sınırı korunmalı');
 // A normal session that would have expired after the old 30-day boundary is rejected.
 $db->run('UPDATE sessions SET expires=? WHERE token=?',[time()-1,$token]);denied(fn()=>$security->user());
 $db->run('UPDATE sessions SET expires=? WHERE token=?',[$expires,$token]);$user=$security->user();
 $db->run('UPDATE sessions SET expires=? WHERE token=?',[time()+28800,$token]);
 $user=$security->user();check(!$security->sessionPreference($user)['keepSession'],'Geçici oturum seçili görünmemeli');
 $security->sessionPreference($user,['keepSession'=>true]);
 check((int)$db->one('SELECT expires FROM sessions WHERE token=?',[$token])['expires']===$expires,'Yalnız mevcut oturum uzatılmalı');
 if($role==='admin')check((int)$db->one('SELECT expires FROM admin_mfa_sessions WHERE token=?',[$token])['expires']===$expires,'MFA doğrulaması aynı yaşam süresini kullanmalı');
 $other=bin2hex(random_bytes(32));$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$other,$id,'other-csrf',$expires]);
 $security->sessionPreference($security->user(),['keepSession'=>false]);denied(fn()=>$security->user());
 check((bool)$db->one('SELECT token FROM sessions WHERE token=?',[$other]),'Diğer cihazın oturumu korunmalı');
 denied(fn()=>$security->sessionPreference($user,['keepSession'=>true]));
}
check(SessionLifetime::expiry(false)<=time()+28800,'Seçilmemiş oturum politikası korunmalı');
echo "Kalıcı oturum: iki rol, MFA süresi, iptal, cihaz ayrımı ve tekrar etkinleştirme koruması geçti.\n";
