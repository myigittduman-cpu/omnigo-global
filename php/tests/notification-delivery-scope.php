<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,MailQueue,OperationalMailEvents,NotificationMailPolicy,MobileNotificationPolicy,MobilePushMessage,OperationalNotifications,UserChangeNotifications,ShipmentChangeNotifications,UserNotificationMail,CancellationRequestEvents};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Test Firma'),(2,'Diğer Firma')");$db->run("INSERT INTO users VALUES(1,'admin@omnigoglobal.com','Yönetici','x','admin',NULL),(2,'client@example.test','Müşteri Hesabı','x','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('n',32));$mail=new MailQueue($db,['mail'=>['enabled'=>true,'from'=>'no-reply@example.test','transport'=>'mail','key'=>base64_encode(str_repeat('q',32))]]);
$events=new OperationalMailEvents($db,$cipher);$sent=[];$transport=function($to,$subject,$body)use(&$sent){$sent[]=[$to,$subject,$body];return true;};
foreach(['integration','operation'] as $type){$payload=['type'=>$type,'to'=>'admin@omnigoglobal.com','detail'=>['events'=>['shipment_added']]];$db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,1,?,'pending',0,?)",['legacy-'.$type,$mail->encrypt($payload),gmdate('c')]);}
while($mail->runOne($transport)){}check($sent===[],'Eski kuyruktaki bağlantı ve aktarım postaları gönderilmez');
check(count($db->all("SELECT id FROM mail_outbox WHERE state='cancelled'"))===2,'Engellenen eski işler yeniden denenmez');
check($mail->enqueueIntegrationAlert(111,'Test Firma')&&count($db->all('SELECT id FROM mail_outbox'))===2,'Yeni bağlantı postası oluşmaz');
foreach(['new_order','cancelled','cancellation_requested','document_uploaded','user_change'] as $type){$db->transaction(fn()=>$events->record(1,1,['events'=>[$type],'provider'=>'n11','actorName'=>'Test İşlemci','action'=>'Kaydı güncelledi']));}
$db->transaction(fn()=>$events->record(1,1,['events'=>['shipment_added'],'provider'=>'n11']));
$events->enqueue($mail);while($mail->runOne($transport)){}check(count($sent)===5,'Yalnız izinli iş e-postaları gönderilir');
check(str_contains($sent[2][1],'iptal talebi')&&str_contains($sent[2][2],'henüz kesinleşmedi'),'Talep kesin iptal olarak sunulmaz');
check($events->enqueue($mail)===0,'Tekrar tarama posta çoğaltmaz');
foreach(['admin-mfa','privacy-deletion','password-reset'] as $type)check(NotificationMailPolicy::allows(['type'=>$type]),'Hesap güvenliği postaları korunur');
foreach(['web','desktop','android','ios'] as $kind){
 foreach(['integration','unknown'] as $type)check(!MobileNotificationPolicy::allows(['kind'=>$kind],['event_type'=>$type]),'Bağlantı hiçbir cihazda kesinti oluşturmaz');
 foreach(['new_order','cancelled','cancellation_requested'] as $event){$row=$event==='new_order'?['event_type'=>'order']:['event_type'=>'operation','payload'=>json_encode(['events'=>[$event]])];check(MobileNotificationPolicy::allows(['kind'=>$kind],$row),'İzinli sipariş uyarısı '.$kind);}
}
check(MobilePushMessage::create(['sound'=>false],['event_type'=>'order'],'test')['sound']===true,'Eski sessiz tercih yeni siparişi susturmaz');
$request=['event_type'=>'operation','payload'=>'{"events":["cancellation_requested"]}'];check(str_contains(MobilePushMessage::create(['sound'=>true],$request,'test')['body'],'iptal talebi'),'Mobil talep başlığı');
check(str_contains(OperationalNotifications::presentation('operation',['events'=>['cancellation_requested']])['title'],'iptal talebi'),'Web talep başlığı');
$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Deniz Örnek','operatorName'=>'Eski operatör adı'];
$db->transaction(fn()=>(new UserChangeNotifications($db))->record($client,1,99,100,'Destek mesajı eklendi'));
$events->enqueue($mail);while($mail->runOne($transport)){}check(count($sent)===6&&str_contains($sent[5][2],'Deniz Örnek')&&str_contains(mb_strtolower($sent[5][2]),'destek mesajı'),'Yeni kullanıcı işlemi adı ve eylemi ile e-posta');
$events->enqueue($mail);check(!$mail->runOne($transport),'Kullanıcı e-postası tekil');
$order=['provider'=>'Pazarama','details'=>['products'=>[['lineId'=>'A','status'=>'18'],['lineId'=>'B','status'=>'7']]]];$state=CancellationRequestEvents::advance($order,[]);check($state['new']===['A'],'Yalnız açık iptal talebi, iade talebi değil');
$order['cancellationRequestHistory']=$state['seen'];check(CancellationRequestEvents::advance($order,$order)['new']===[],'Aynı iptal talebi tekrar bildirilmez');
echo "Bildirim kapsamı: 4 cihaz, e-posta, eski kuyruk, kullanıcı adı, iptal talebi, tekrar ve güvenlik postaları geçti.\n";
