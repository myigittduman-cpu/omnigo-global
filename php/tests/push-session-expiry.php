<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,NotificationSchema,PushDevices,MobilePushDevices,PushSession,PushWorker,DeliveryCipher,Security,PortalAccess,HttpError,AccountDirectory};

function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $action):void{try{$action();}catch(HttpError $error){check($error->getCode()===401,'Yetkisiz istek kodu korunur');return;}throw new RuntimeException('401 bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);
\Omnigo\ClientSessionOperator::install($db);\Omnigo\AccountSettingsSchema::install($db);\Omnigo\AdminMfaSchema::install($db);
$password='Synthetic-Only-Password-20261104!';$hash=Security::hashPassword($password);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo',?,'admin',NULL),(2,'client@example.test','Müşteri',?,'client',1),(3,'other@example.test','Diğer',?,'client',2)",[$hash,$hash,$hash]);
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();$registrations=[];
foreach([1,2] as $userId)foreach(['web','desktop','android','ios'] as $kind){
    $raw=bin2hex(random_bytes(32));$token=hash('sha256',$raw);
    $user=[...$db->one('SELECT * FROM users WHERE id=?',[$userId]),'token'=>$token];
    $db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$userId,'csrf',time()+28800]);
    $installation=hash('sha256',$userId.':'.$kind);
    $data=$kind==='web'?['subscription'=>['endpoint'=>'https://web.push.apple.com/'.$installation,'keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]]]:['deviceId'=>$installation];
    if(in_array($kind,['android','ios'],true)){
        $data=['kind'=>$kind,'appId'=>'com.omnigoglobal.'.($userId===1?'admin':'client'),'installationId'=>$installation,'token'=>$kind==='ios'?str_repeat('a',64):str_repeat('a',140),'environment'=>'production','sound'=>true];
        $saved=$mobile->save($user,$data);
    }else $saved=$devices->save($user,$kind,$data);
    $registrations[$userId.':'.$kind]=['user'=>$user,'raw'=>$raw,'saved'=>$saved];
}
$db->run('UPDATE sessions SET expires=?',[time()-1]);
foreach($registrations as $registration)check($devices->usable($devices->current($registration['user'],$registration['saved']['id'])),'İki rolde tüm bildirim türleri giriş süresinden bağımsız kalır');
foreach([1,2] as $id){
    $config=['origin'=>'https://'.($id===1?'yonetim':'portal').'.example.test','portal_role'=>$id===1?'admin':'client'];
    $_COOKIE[PortalAccess::cookieName($config)]=$registrations[$id.':web']['raw'];
    denied(fn()=>(new Security($db,$config))->user());
}
$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[str_repeat('e',64),3,'csrf',time()-1]);
PushSession::prune($db);
check(count($db->all('SELECT token FROM sessions'))===8,'Etkin cihaz bağları korunur; bağlı olmayan eski oturum temizlenir');
$_SERVER['REMOTE_ADDR']='push-session-expiry-test';
$otherLogin=(new Security($db,['origin'=>'https://portal.example.test','portal_role'=>'client']))->login(['email'=>'other@example.test','password'=>$password]);
check($otherLogin['user']['id']===3,'Başka müşterinin normal giriş akışı çalışır');
foreach($registrations as $registration)check($devices->usable($devices->current($registration['user'],$registration['saved']['id'])),'Başka hesap girişindeki temizlik mevcut bildirim cihazlarını kesmez');
check((int)$db->one('SELECT expires FROM sessions WHERE token=?',[$registrations['2:web']['user']['token']])['expires']<time(),'Bildirim sürekliliği etkileşim oturumunu uzatmaz');
check($devices->poll($registrations['2:desktop']['saved']['token'],null,false,false,false,'client')['items']===[],'Müşteri arka plan sorgusu çalışır');
denied(fn()=>$devices->poll($registrations['2:desktop']['saved']['token'],null,false,false,false,'admin'));
$mobile->refresh($registrations['2:android']['saved']['refreshToken'],['token'=>str_repeat('z',150)],$devices);
check($devices->mobileSubscription($devices->current($registrations['2:android']['user'],$registrations['2:android']['saved']['id']))['token']===str_repeat('z',150),'FCM yenilemesi etkileşim oturumu dolduktan sonra da çalışır');
$sent=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$sent){$sent[]=$message;return ['success'=>true,'expired'=>false,'status'=>200];});
foreach(['2:web','2:android','2:ios'] as $key)$devices->test($registrations[$key]['user'],$registrations[$key]['saved']['id']);
while($worker->runOne()){}
check(count($sent)===3,'Süresi dolmuş girişte web, FCM ve APNs deneme kuyruğu gönderilir');
$config=['origin'=>'https://portal.example.test','portal_role'=>'client'];
(new Security($db,$config))->logout($registrations['2:desktop']['user']);
denied(fn()=>$devices->poll($registrations['2:desktop']['saved']['token'],null));
check($devices->usable($devices->current($registrations['2:web']['user'],$registrations['2:web']['saved']['id'])),'Bir cihazdaki açık çıkış diğer cihazı durdurmaz');
$db->run('DELETE FROM sessions WHERE token=?',[$registrations['2:ios']['user']['token']]);
check(!$devices->usable($devices->current($registrations['2:ios']['user'],$registrations['2:ios']['saved']['id'])),'Silinen oturum bağı bildirim erişimini iptal eder');
$db->run('UPDATE notification_devices SET expires=? WHERE id=?',[time()-1,$registrations['1:android']['saved']['id']]);
$db->run('UPDATE notification_devices SET enabled=0 WHERE id=?',[$registrations['1:ios']['saved']['id']]);
PushSession::prune($db);
foreach(['1:android','1:ios'] as $key){
    check(!$devices->usable($devices->current($registrations[$key]['user'],$registrations[$key]['saved']['id'])),'Kapalı veya süresi dolmuş cihaz kullanılamaz');
    check(!$db->one('SELECT token FROM sessions WHERE token=?',[$registrations[$key]['user']['token']]),'Artık gerekli olmayan oturum bağı temizlenir');
}
$db->run("UPDATE users SET password='changed-hash' WHERE id=2");
check(!$devices->usable($devices->current($registrations['2:android']['user'],$registrations['2:android']['saved']['id'])),'Parola değişikliği native cihazı geçersiz kılar');
check(!$devices->usable($devices->current($registrations['2:web']['user'],$registrations['2:web']['saved']['id'])),'Parola değişikliği web cihazını geçersiz kılar');
(new AccountDirectory($db))->revoke(1);
check(!$devices->usable($devices->current($registrations['1:web']['user'],$registrations['1:web']['saved']['id'])),'Hesap oturumları iptal edilince yönetici cihazı kapanır');
echo "Bildirim oturumu: iki rol ve dört cihaz türünde süre sonrası teslim, FCM yenileme, kapalı giriş, açık çıkış, iptal, parola ve cihaz süresi kontrolleri geçti.\n";
