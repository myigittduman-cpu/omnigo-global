<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{MobilePushConfig,MobilePushHttp,MobilePushSender};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$file=tempnam(sys_get_temp_dir(),'omnigo-mobile-config-');
try{
 check(!MobilePushConfig::ready('android',[]),'Eksik kurulum hazır gösterilmez');
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
 $account=['type'=>'service_account','project_id'=>'test-only-project','client_email'=>'push@test-only-project.iam.gserviceaccount.com','private_key'=>$pem];
 $config=['fcm'=>['enabled'=>true,'projectId'=>'test-only-project','serviceAccountFile'=>$file]];
 file_put_contents($file,json_encode($account));check(MobilePushConfig::ready('android',$config),'Doğru hizmet hesabı kabul edilir');
 $requests=[];$http=new MobilePushHttp(function($url,$headers,$body)use(&$requests){
  $requests[]=compact('url','headers','body');
  if(str_contains($url,'oauth2.googleapis.com'))return ['status'=>200,'body'=>['access_token'=>'test-access-token','expires_in'=>3600]];
  return ['status'=>200,'body'=>['name'=>'test-message']];
 });
 $sender=new MobilePushSender($config,$http);
 $subscription=['kind'=>'android','token'=>'test-device-token','appId'=>'com.omnigoglobal.admin'];
 $message=['title'=>'Omnigo','body'=>'Yeni bildirim','sound'=>true,'url'=>'/?view=shipments','tag'=>'omnigo-1'];
 check($sender->send($subscription,$message)['success'],'FCM gönderimi başarılı');
 check($sender->send($subscription,$message)['success']&&count($requests)===3,'Yetkilendirme tokenı tekrar kullanılır');
 $payload=json_decode($requests[1]['body'],true)['message'];
 check($payload['android']['priority']==='HIGH'&&$payload['android']['ttl']==='3600s','Arka plan teslimat ayarları');
 check($payload['android']['restricted_package_name']===$subscription['appId']&&$payload['android']['notification']['channel_id']==='omnigo_updates','Uygulama ve kanal sınırı');
 check($payload['data']['url']===$message['url']&&$payload['notification']['body']==='Yeni bildirim','Bildirim ve yönlendirme birlikte gönderilir');
 check($sender->send([...$subscription,'displayVersion'=>2],$message)['success'],'Yeni APK veri bildirimi');
 $modern=json_decode(end($requests)['body'],true)['message'];
 check(!isset($modern['notification'])&&!isset($modern['android']['notification']),'Çift bildirim oluşmamalı');
 check($modern['data']['title']===$message['title']&&$modern['data']['body']===$message['body'],'Tam uygulama adı ve ayrıntı taşınır');
 check($modern['android']['priority']==='HIGH'&&$modern['android']['restricted_package_name']===$subscription['appId'],'Yeni paket kapsamı korunur');
 foreach([['project_id'=>'wrong-project'],['type'=>'authorized_user'],['private_key'=>'invalid-key'],['client_email'=>'attacker@example.test']] as $patch){
  file_put_contents($file,json_encode([...$account,...$patch]));check(!MobilePushConfig::ready('android',$config),'Bozuk/eşleşmeyen hesap kabul edilmez');
  $public=json_encode(MobilePushConfig::status('android',$config));check(!str_contains($public,$file)&&!str_contains($public,'private_key'),'Tanılama gizli bilgi içermez');
 }
 file_put_contents($file,'not-json');check(!MobilePushConfig::ready('android',$config),'Bozuk JSON hazır gösterilmez');
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($key,$pem);file_put_contents($file,$pem);
 $apns=['apns'=>['enabled'=>true,'teamId'=>'TESTTEAM01','keyId'=>'TESTKEY001','privateKeyFile'=>$file]];
 check(MobilePushConfig::ready('ios',$apns),'Doğru APNs anahtarı kabul edilir');
 $appleSender=new MobilePushSender($apns,$http);
 $appleSubscription=['kind'=>'ios','token'=>'test-device-token','appId'=>'com.omnigoglobal.client','environment'=>'production'];
 $appleMessage=['title'=>'PRIVATE_COMPANY','body'=>'PRIVATE_BUYER · PRIVATE_AMOUNT · PRIVATE_ORDER · PRIVATE_CHAT',
  'sound'=>true,'url'=>'/?view=orders&orderCompany=12&noticeType=order&noticeId=5','tag'=>'PRIVATE_TAG'];
 check($appleSender->send($appleSubscription,$appleMessage,'en')['success'],'APNs gönderimi başarılı');
 $request=end($requests);
 $applePayload=json_decode($request['body'],true,16,JSON_THROW_ON_ERROR);
 check($request['url']==='https://api.push.apple.com/3/device/test-device-token','Üretim APNs sunucusu korunur');
 check(in_array('apns-topic: com.omnigoglobal.client',$request['headers'],true),'Apple uygulama kimliği korunur');
 check($applePayload['aps']['alert']===['title'=>'Omnigo Global İş Ortağım','body'=>'You have a new notification. View the details in the app.'],'APNs sabit başlığı ve yerelleştirilmiş genel metni kullanır');
 check($applePayload['aps']['sound']==='default'&&$applePayload['url']===$appleMessage['url'],'Apple ses tercihi ve portal hedefi korunur');
 check(!str_contains($request['body'],'PRIVATE_')&&$applePayload['tag']===hash('sha256',$appleMessage['tag'])&&in_array('apns-collapse-id: '.$applePayload['tag'],$request['headers'],true),'APNs yükü ve birleştirme etiketi hassas veri taşımaz');

 foreach(['/?orderCompany=12&view=shipments&noticeType=shipment&noticeId=5','/?orderCompany=12','/?view=deliveries','/?view=connections','/?view=inventory','/?view=payments','/?view=returns','/?view=support'] as $route){
  $appleSender->send($appleSubscription,[...$appleMessage,'url'=>$route]);
  check(json_decode(end($requests)['body'],true)['url']===$route,'Apple iç hedefi korunur: '.$route);
 }
 foreach(['https://example.test','/?view=orders&orderCompany=PRIVATE_NAME','/?view=orders&orderNumber=0123456','/?chatThread=42&message=PRIVATE_TEXT'] as $route){
  $appleSender->send($appleSubscription,[...$appleMessage,'url'=>$route]);
  check(json_decode(end($requests)['body'],true)['url']==='/','Apple izinsiz gönderim hedefini reddeder: '.$route);
 }
 $appleSender->send([...$appleSubscription,'appId'=>'com.omnigoglobal.admin','environment'=>'sandbox'],[...$appleMessage,'url'=>'/?chatThread=42','sound'=>false,'tag'=>str_repeat('a',64)]);
 $chatRequest=end($requests);
 $chatPayload=json_decode($chatRequest['body'],true);
 check($chatRequest['url']==='https://api.sandbox.push.apple.com/3/device/test-device-token','Apple deneme ortamı korunur');
 check($chatPayload['aps']['alert']===['title'=>'Omnigo Global Yönetici Portalı','body'=>'Yeni sohbet mesajınız var.']&&$chatPayload['url']==='/?chatThread=42','Apple sohbet bildirimi yalnız genel metin ve iç konuşma kimliği taşır');
 check(!isset($chatPayload['aps']['sound'])&&$chatPayload['tag']===str_repeat('a',64),'Apple sessiz gönderim ve mevcut opak etiket korunur');
 $appleSender->send($appleSubscription,[...$appleMessage,'url'=>'/'],'de');
 check(json_decode(end($requests)['body'],true)['aps']['alert']['body']==='Testbenachrichtigung; die mobile Benachrichtigungsverbindung funktioniert.','Apple deneme bildiriminin amacı korunur');

 $apns['apns']['teamId']='wrong';check(!MobilePushConfig::ready('ios',$apns),'Bozuk Apple kimliği kabul edilmez');
 echo "Mobil kurulum: FCM ve APNs yapılandırma, bildirim içeriği, portal hedefi ve gizlilik testleri geçti.\n";
}finally{unlink($file);}
