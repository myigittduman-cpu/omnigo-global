<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,MobilePushDevices,MobilePushRegistration,MobilePushSender,MobilePushTokens,MobilePushHttp,PushDevices,PushWorker,DeliveryCipher,OrderNotifications,HttpError};
function check(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function fails(callable $action,int $status):void {try{$action();throw new RuntimeException('Hata bekleniyordu');}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Firma A'),(2,'Firma B')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Admin','hash','admin',NULL),(2,'client@example.test','Client','hash','client',1),(3,'other@example.test','Other','hash','client',2)");
$users=[];foreach([1,2,3] as $id){$token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);$users[$id]=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];}
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
$base=['kind'=>'android','appId'=>'com.omnigoglobal.client','installationId'=>str_repeat('a',64),'token'=>str_repeat('x',140),'environment'=>'production','sound'=>true];
$private=[...$base,'appId'=>'com.omnigoglobal.admin.privateapp'];
check(MobilePushRegistration::validate($private,$users[1])['appId']===$private['appId'],'Özel Android yönetici paketinin FCM hedefi korunur');
fails(fn()=>MobilePushRegistration::validate($private,$users[2]),400);
fails(fn()=>MobilePushRegistration::validate([...$private,'kind'=>'ios','token'=>str_repeat('a',64)],$users[1]),400);
fails(fn()=>MobilePushRegistration::validate([...$private,'appId'=>'com.omnigoglobal.admin.privateapp.fake'],$users[1]),400);
fails(fn()=>MobilePushRegistration::validate([...$base,'appId'=>'com.omnigoglobal.admin'],$users[2]),400);
fails(fn()=>MobilePushRegistration::validate([...$base,'token'=>'unsafe/token'],$users[2]),400);
fails(fn()=>MobilePushRegistration::validate([...$base,'environment'=>'sandbox'],$users[2]),400);
check(MobilePushRegistration::validate($base,$users[2])['displayVersion']===1,'Eski APK uyumluluğu');
check(MobilePushRegistration::validate([...$base,'displayVersion'=>2],$users[2])['displayVersion']===2,'Yeni APK özelliği korunur');
fails(fn()=>MobilePushRegistration::validate([...$base,'displayVersion'=>3],$users[2]),400);
$client=$mobile->save($users[2],$base);$admin=$mobile->save($users[1],[...$base,'appId'=>'com.omnigoglobal.admin','installationId'=>str_repeat('b',64)]);
$stored=$devices->current($users[2],$client['id']);check(!str_contains($stored['payload'],$base['token']),'Cihaz belirteci şifrelenir');
$again=$mobile->save($users[2],[...$base,'displayVersion'=>2]);check($again['refreshToken']===null,'Aynı kayıt gereksiz gizli anahtar üretmez');
check($devices->mobileSubscription($devices->current($users[2],$client['id']))['displayVersion']===2,'APK güncellemesi aynı kayıtta yeni bildirim biçimini etkinleştirir');
check($stored['generation']===$devices->current($users[2],$client['id'])['generation'],'Aynı kayıt bekleyen işleri bozmaz');
$mobile->refresh($client['refreshToken'],['token'=>str_repeat('z',150)],$devices);
check($devices->mobileSubscription($devices->current($users[2],$client['id']))['token']===str_repeat('z',150),'Arka planda token yenilenir');
check($devices->mobileSubscription($devices->current($users[2],$client['id']))['displayVersion']===2,'Arka plan yenilemesi yeni bildirim biçimini korur');
$notifications=new OrderNotifications($db);$order=['provider'=>'n11','orderNumber'=>'private-order','createdAt'=>gmdate('c')];
$db->transaction(fn()=>$notifications->observe(2,$order));$db->transaction(fn()=>$notifications->observe(2,$order));
$seen=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$seen){$seen[]=['app'=>$subscription['appId'],'message'=>$message];return ['success'=>true,'expired'=>false,'status'=>200];});
while($worker->runOne()){}
check(count($seen)===1&&$seen[0]['app']==='com.omnigoglobal.admin','Başka şirketin olayı müşteriye gitmez; yönetici alır');
$db->transaction(fn()=>$notifications->observe(1,[...$order,'orderNumber'=>'second-private']));while($worker->runOne()){}
check(count($seen)===2,'Siparişler yalnız yöneticiye gider; müşteriye çözüm bildirimi ayrıdır');
$json=json_encode($seen);check(str_contains($json,'Firma A')&&str_contains($json,'Firma B')&&!str_contains($json,'private'),'Firma adı görünür; siparişin özel ayrıntıları taşınmaz');
$devices->test($users[2],$client['id']);$retry=new PushWorker($db,$devices,fn()=>['success'=>false,'expired'=>false,'status'=>429,'retryAfter'=>3600]);$retry->runOne();
check((int)$db->one('SELECT next_run FROM push_deliveries WHERE event_id IS NULL')['next_run']>=time()+3599,'Hız sınırında Retry-After korunur');
$db->run('DELETE FROM sessions WHERE user_id=2');check(!$devices->usable($devices->current($users[2],$client['id'])),'Çıkışta cihaz erişimi kapanır');fails(fn()=>$mobile->refresh($client['refreshToken'],['token'=>str_repeat('w',140)],$devices),401);
$db->run('UPDATE push_deliveries SET next_run=0 WHERE event_id IS NULL');$worker->runOne();check($db->one('SELECT state FROM push_deliveries WHERE event_id IS NULL')['state']==='cancelled','Çıkış sonrası kuyruk gönderilmez');
$new=$mobile->save($users[3],$base);fails(fn()=>$mobile->refresh($client['refreshToken'],['token'=>str_repeat('w',140)],$devices),401);
check($new['refreshToken']!==$client['refreshToken'],'Hesap değişiminde anahtar yenilenir');
$db->run("UPDATE users SET password='newhash' WHERE id=3");check(!$devices->usable($devices->current($users[3],$new['id'])),'Parola değişikliği eski cihaz kaydını geçersiz kılar');
$result=MobilePushSender::result('android',['status'=>404,'body'=>[]]);check(!$result['expired']&&$result['permanent'],'Proje hatası cihazı silmez');
check(MobilePushSender::result('android',['status'=>404,'body'=>['error'=>['details'=>[['@type'=>'type.googleapis.com/google.firebase.fcm.v1.FcmError','errorCode'=>'UNREGISTERED']]]]])['expired'],'Yalnızca FCM geçersiz belirteci kapatılır');
check(MobilePushSender::result('ios',['status'=>410,'body'=>['reason'=>'Unregistered']])['expired'],'APNs geçersiz belirteci kapatılır');
require dirname(__DIR__).'/vendor/push/autoload.php';
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($key,$pem);$file=tempnam(sys_get_temp_dir(),'apns-test');file_put_contents($file,$pem);
try{
 $tokens=new MobilePushTokens(new MobilePushHttp());$config=['teamId'=>'TESTTEAM01','keyId'=>'TESTKEY001','privateKeyFile'=>$file];$jwt=$tokens->apns($config);
 $jws=(new Jose\Component\Signature\Serializer\CompactSerializer())->unserialize($jwt);
 $verifier=new Jose\Component\Signature\JWSVerifier(new Jose\Component\Core\AlgorithmManager([new Jose\Component\Signature\Algorithm\ES256()]));
 check($verifier->verifyWithKey($jws,Jose\Component\KeyManagement\JWKFactory::createFromKey(openssl_pkey_get_details($key)['key']),0),'APNs ES256 imzası doğrulanır');
 check(json_decode($jws->getPayload(),true)['iss']==='TESTTEAM01','APNs takım kimliği doğru');check($jwt===$tokens->apns($config),'Sağlayıcı tokenı kısa süre önbelleklenir');
}finally{unlink($file);}
echo "Mobil push: şirket/rol ayrımı, şifreleme, yenileme, tekrar engeli, kuyruk, retry, çıkış, parola değişimi ve APNs imzası geçti.\n";
