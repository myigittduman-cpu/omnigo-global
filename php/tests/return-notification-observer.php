<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,DeliveryCipher,NotificationSchema,DeliverySchema,SupportSchema,ShipmentSchema,PushDevices,MobilePushDevices,PushWorker,ReturnNotificationObserver,OperationalNotifications,NotificationAudience,CustomerNotificationPolicy,NotificationLanguage};
$count=0;function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);DeliverySchema::install($db);SupportSchema::migrate($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test mağaza'),(2,'Diğer mağaza')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici A','hash','admin',NULL),(2,'b@example.test','Yönetici B','hash','admin',NULL),(3,'c@example.test','Müşteri A','hash','client',1),(4,'d@example.test','Müşteri B','hash','client',2)");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);$desktop=[];
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();$keys=['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')];
foreach([1,2,3,4] as $id){
 $token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);$user=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];
 foreach(['https://fcm.googleapis.com/fcm/send/','https://web.push.apple.com/'] as $host)$devices->save($user,'web',['subscription'=>['endpoint'=>$host.'return-test-'.$id,'keys'=>$keys]]);
 $desktop[$id]=$devices->save($user,'desktop',['deviceId'=>hash('sha256','desktop-'.$id)]);
 foreach(['android','ios'] as $kind)$mobile->save($user,['kind'=>$kind,'appId'=>'com.omnigoglobal.'.($id<3?'admin':'client'),'installationId'=>hash('sha256',$kind.$id),'token'=>$kind==='ios'?hash('sha256','ios'.$id):str_repeat('x',140).$id,'environment'=>'production','sound'=>true,'displayVersion'=>2]);
}
function snapshot(Database $db,string $provider,array $items,int $company=1):void{
 $state=['returnRequests'=>['items'=>$items,'lastSuccessAt'=>gmdate('c')]];if($provider==='hepsiburada')$state=['financial'=>$state];$kind='sync_'.$provider;
 $row=$db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
 if($row)$db->run('UPDATE records SET payload=?,updated=? WHERE id=?',[json_encode($state),gmdate('c'),$row['id']]);
 else $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($state),gmdate('c')]);
}
$statuses=['hepsiburada'=>'NewRequest','n11'=>'REQUESTED','idefix'=>'waiting_vendor_approve','pazarama'=>'7','koctas'=>'WAITING_ACCEPTANCE','pttavm'=>'returned','trendyol'=>'Returned'];
$observer=new ReturnNotificationObserver($db,$cipher);
foreach($statuses as $provider=>$status)snapshot($db,$provider,[['id'=>'old','status'=>$status]]);
$observer->run();check((int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===0,'İlk geçmiş taraması cihazları eski iadelerle doldurmaz');
$new=['id'=>'new','status'=>'NewRequest','orderNumber'=>'123','quantity'=>1];
snapshot($db,'hepsiburada',[['id'=>'old','status'=>'NewRequest'],$new,$new,['id'=>'invoice','status'=>'NewRequest','claimType'=>'MissingInvoice']]);
$observer->run();$observer->run();
$rows=$db->all("SELECT * FROM order_notifications WHERE event_type='operation'");check(count($rows)===1,'Tekrarlı satır ve taramalar tek iade bildirimi üretir; fatura talebi iade değildir');
$event=$rows[0];$summary=json_decode($event['payload'],true);check($summary['isNewReturn']===true,'Yeni iade ilk kez bulunduğu bilgisi korunur');
$notice=new OperationalNotifications($db,$cipher);check($notice->summary(1,(int)$event['id'])['message']==='Yeni iade talebi geldi.','Zilde yeni iade mesajı');check($notice->detail(1,(int)$event['id'])['claimId']==='new','Şifreli detayda doğru talep');
foreach([1,2,3,4] as $id){$u=$db->one('SELECT * FROM users WHERE id=?',[$id]);check((NotificationAudience::permits($u,$event)&&CustomerNotificationPolicy::recipient($db,$u,$event))===($id<3),'Yalnız yöneticiler: '.$id);}
$seen=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$seen){$seen[]=['subscription'=>$subscription,'message'=>$message];return ['success'=>true,'expired'=>false,'status'=>201];});
while($worker->runOne()){}
check(count($seen)===8,'İki yöneticinin web, iOS PWA, Android ve iOS kayıtlarına gönderilir');
foreach($seen as $sent){check(str_contains($sent['message']['body'],'Yeni iade talebi geldi.'),'Ortak yeni iade metni');check(str_contains($sent['message']['url'],'view=returns'),'Tıklama iade sayfasına gider');check($sent['message']['sound']===true,'Ses açık korunur');if(isset($sent['subscription']['appId']))check($sent['subscription']['appId']==='com.omnigoglobal.admin','Müşteri mobil uygulaması almaz');}
foreach($desktop as $id=>$registration){$items=$devices->poll($registration['token'],0,true,true,true)['items'];check(count($items)===($id<3?1:0),'Masaüstü alıcı ayrımı '.$id);}
// A transient transport failure must stay queued and retry without re-observing the return.
snapshot($db,'hepsiburada',[['id'=>'old','status'=>'NewRequest'],[...$new,'status'=>'Accepted']]);$observer->run();
$last=$db->one("SELECT * FROM order_notifications WHERE event_type='operation' ORDER BY id DESC LIMIT 1");check(!json_decode($last['payload'],true)['isNewReturn'],'Sonraki durum değişikliği yeni talep sayılmaz');
$retry=new PushWorker($db,$devices,fn()=>['success'=>false,'expired'=>false,'status'=>503]);$retry->runOne();
$pending=$db->one("SELECT * FROM push_deliveries WHERE event_id=? AND attempts=1",[$last['id']]);check($pending['state']==='pending'&&(int)$pending['next_run']>time(),'Geçici bağlantı hatası yeniden denenir');
$db->run('UPDATE push_deliveries SET next_run=0 WHERE id=?',[$pending['id']]);while($worker->runOne()){}
check(count($seen)===16,'Tek durum olayı bütün yönetici cihazlarına tekrar kuyruğuyla teslim edilir');
// Every supported source uses the same policy. No live orders are touched.
foreach($statuses as $provider=>$status){if($provider==='hepsiburada')continue;snapshot($db,$provider,[['id'=>'old','status'=>$status],['id'=>'next','status'=>$status]]);}
$observer->run();$observer->run();check((int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===8,'Yedi kaynağın yeni iade gözlemi ortak ve tekildir');
check(NotificationLanguage::text('Yeni iade talebi geldi.','en')==='A new return request has arrived.','İngilizce bildirim');
check(NotificationLanguage::text('Yeni iade talebi geldi.','de')==='Eine neue Rückgabeanfrage ist eingegangen.','Almanca bildirim');
$mail=new \Omnigo\MailQueue($db,['mail'=>['enabled'=>true,'from'=>'no-reply@example.test','transport'=>'mail','key'=>base64_encode(str_repeat('m',32))]]);
$mailEvents=new \Omnigo\OperationalMailEvents($db,$cipher);$mailEvents->enqueue($mail);$messages=[];
while($mail->runOne(function($to,$subject,$body)use(&$messages){$messages[]=compact('to','subject','body');return true;})){}
check(count($messages)===8,'Her yeni iade veya durum değişikliği ortak Omnigo posta kutusuna bir kez gider');
check($messages[0]['to']==='admin@omnigoglobal.com'&&str_contains($messages[0]['subject'],'Yeni iade talebi')&&str_contains($messages[0]['body'],'view=returns')&&str_contains($messages[0]['body'],'İade talebi: new'),'İade e-postası doğru alıcı, talep kimliği ve sayfa içerir');
check(str_contains($messages[1]['subject'],'durumu güncellendi')&&!str_contains($messages[1]['subject'],'Yeni iade'),'Durum değişikliği yeni talep olarak gösterilmez');
check($mailEvents->enqueue($mail)===0&&!$mail->runOne(fn()=>throw new RuntimeException('Tekrar posta')),'Aynı aktarım iade e-postalarını çoğaltmaz');
echo "İade bildirimleri: $count kontrol geçti.\n";
