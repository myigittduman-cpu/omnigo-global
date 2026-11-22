<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountSettingsSchema,NotificationSchema,UserPreferences,PushDevices,MobilePushDevices,DeliveryCipher,MobilePushMessage,MobileNotificationPresentation,NotificationLanguage};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
AccountSettingsSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','A','test-only','admin',NULL),(2,'b@example.test','B','test-only','client',1),(3,'c@example.test','C','test-only','client',2)");
$users=[];foreach([1,2,3] as $id){$token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);$users[$id]=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token,'operatorName'=>'Örnek Kişi'];}
$prefs=new UserPreferences($db);foreach([1=>'en',2=>'de'] as $id=>$lang)$prefs->save($users[$id],['preferences'=>[...UserPreferences::defaults(),'language'=>$lang],'version'=>0]);
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
$base=['kind'=>'android','appId'=>'com.omnigoglobal.client','installationId'=>str_repeat('a',64),'token'=>str_repeat('x',140),'environment'=>'production','sound'=>true];
$binding=$mobile->save($users[2],$base);$device=$devices->current($users[2],$binding['id']);
check($devices->language($device)==='de','Müşterinin işlemciye özel dili kullanılır');
check(!array_key_exists('localeProfile',$devices->mobileSubscription($device)),'Dil profil anahtarı sağlayıcıya gönderilmez');
$prefs->save($users[2],['preferences'=>[...UserPreferences::defaults(),'language'=>'en'],'version'=>1]);
check($devices->language($device)==='en','Kaydedilen dil yeniden cihaz kaydı veya kuyruk değişikliği gerektirmez');
check($devices->current($users[2],$binding['id'])['generation']===$device['generation'],'Dil değişimi bekleyen bildirimleri geçersiz kılmaz');
$mobile->refresh($binding['refreshToken'],['token'=>str_repeat('z',150)],$devices);
check($devices->language($devices->current($users[2],$binding['id']))==='en','Token yenilemesinde dil profili korunur');
$devices->attachLanguage([...$users[2],'operatorName'=>'Diğer Kişi'],$devices->current($users[2],$binding['id']));
check($devices->language($devices->current($users[2],$binding['id']))==='tr','Aynı hesapta farklı işlemci diğer kişinin tercihini almaz');
check(NotificationLanguage::read($db,['user_id'=>3],NotificationLanguage::profile($users[2]))==='tr','Başka müşteri tercihi okunmaz');
$event=['id'=>17,'company_id'=>1,'company'=>'Örnek Türkçe Firma','provider'=>'hepsiburada','event_type'=>'order','amountCents'=>2029910,'payload'=>'{}'];
$source=$event;$original=MobilePushMessage::create([],$event,'tag','admin');
check($original['body']==='Örnek Türkçe Firma; Hepsiburada’dan 20.299,10₺ tutarında yeni bir siparişiniz var.','Türkçe bildirim aynı kalır');
foreach(['en','de'] as $lang){
 $message=MobilePushMessage::create([],$event,'tag','admin',$lang);
 check($message['url']===$original['url']&&$message['tag']===$original['tag']&&$message['sound']===$original['sound'],'Dil yalnız sunumu değiştirir');
 check(str_contains($message['body'],'Örnek Türkçe Firma')&&str_contains($message['body'],'Hepsiburada'),'Firma ve pazaryeri adları korunur');
 check(str_contains($message['body'],$lang==='de'?'20.299,10 TRY':'20,299.10 TRY'),'Tutar değişmeden bölgesel biçimlenir');
 $cancel=MobileNotificationPresentation::create([...$event,'event_type'=>'operation','payload'=>'{"events":["cancelled"]}'],'admin',$lang);
 check(str_ends_with($cancel['body'],$lang==='de'?'Bestellung storniert.':'Order cancelled.'),'İptal bildirimi çevrilir');
}
check($event===$source,'Kaynak sipariş değişmez');
$desktop=$devices->save($users[1],'desktop',['deviceId'=>str_repeat('d',64),'sound'=>true]);
$orders=new \Omnigo\OrderNotifications($db);
$db->transaction(fn()=>$orders->observe(1,['provider'=>'n11','orderNumber'=>'locale-test-only','createdAt'=>gmdate('c')]));
$feed=$devices->poll($desktop['token'],$desktop['cursor']);
check(count($feed['items'])===1,'Masaüstü dil tercihi sipariş bildirimini filtrelemez');
check($feed['items'][0]['presentation']['title']==='OG Yönetici Portalı','Eski Windows sürümünün kayıtlı başlığı korunur');
check(str_contains($feed['items'][0]['presentation']['body'],'new order'),'Windows gövdesi İngilizcedir');
echo "Bildirim dili: müşteri/işlemci izolasyonu, token yenileme, mevcut kuyruk, Türkçe uyumluluk, tutar ve hedef koruması geçti.\n";
