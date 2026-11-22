<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,DeliveryCipher,PushDevices,PushWorker,OrderNotifications,OperationalMailEvents,OperationalNotifications,NotificationFeed,NotificationState,NotificationSchema,SupportSchema,ShipmentSchema,ShipmentWriter,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);\Omnigo\DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','hash','admin',NULL),(2,'c@example.test','Ayşe','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('n',32));$devices=new PushDevices($db,$cipher);$user=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>str_repeat('a',64)];
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();$keys=['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')];
$registrations=[];foreach(['https://fcm.googleapis.com/fcm/send/one','https://web.push.apple.com/two'] as $endpoint)$registrations[]=$devices->save($user,'web',['subscription'=>['endpoint'=>$endpoint,'keys'=>$keys]]);
$native=$devices->save($user,'desktop',['deviceId'=>str_repeat('d',64)]);
$events=new OperationalMailEvents($db,$cipher);$notice=new OperationalNotifications($db,$cipher);
$db->transaction(fn()=>$events->record(1,1,['events'=>['cancelled'],'provider'=>'n11','orderNumber'=>'PRIVATE-123','city'=>'Ankara','cancelledQuantity'=>1]));
$id=(int)$db->one('SELECT MAX(id) id FROM order_notifications')['id'];
$audit=(int)$db->one("SELECT MAX(id) id FROM audit WHERE action='operations.mail'")['id'];
$db->transaction(fn()=>$notice->publish(1,'operation',$audit,'n11',['events'=>['cancelled']]));
check(count($db->all('SELECT * FROM push_deliveries'))===2,'Her web cihazına yalnızca bir bildirim');
check($devices->poll($native['token'],0,true,true)['items']===[],'Eski masaüstü iptali yeni sipariş sanmaz');
$poll=$devices->poll($native['token'],0,true,true,true);check(count($poll['items'])===1&&$poll['items'][0]['title']==='Sipariş iptal edildi','Yeni masaüstü iptali doğru tanır');
$feed=new NotificationFeed($db,$cipher);$list=$feed->listing(1,1,0);check($list['counts']['unread']===1&&$list['items'][0]['type']==='operation','Zilde iptal görünür');
check($notice->detail(1,$id)['orderNumber']==='PRIVATE-123','Sipariş detayı doğru');
try{$notice->detail(2,$id);throw new RuntimeException('Şirket sızıntısı');}catch(HttpError $e){check($e->getCode()===404,'Şirket sınırı');}
$state=new NotificationState($db);$state->read(1,1,'operation',$id);check($feed->listing(1,1,0)['counts']['unread']===0,'Okundu');$batch=$state->bulk(1,1,$list['through'],true);check($feed->listing(1,1,0)['counts']['all']===0,'Sil');$state->undo(1,1,$batch);check($feed->listing(1,1,0)['counts']['all']===1,'Geri al');
$seen=[];$worker=new PushWorker($db,$devices,function($subscription,$payload)use(&$seen){$seen[]=$payload;return ['success'=>true,'expired'=>false,'status'=>201];});while($worker->runOne()){}
check(count($seen)===2&&$seen[0]['title']==='Omnigo Global Yönetici Portalı'&&str_contains($seen[0]['body'],'Sipariş iptal edildi.')&&!str_contains(json_encode($seen),'PRIVATE'),'Cihazda uygulama başlığı ve kısa iptal metni var, özel müşteri bilgisi yok');
check(str_contains($seen[0]['url'],'noticeType=operation')&&str_contains($seen[0]['url'],'orderCompany=1'),'Tıklama şirkete ve olaya yönlenir');
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek','recipientName'=>'Alıcı','phone'=>'05000000000','province'=>'Ankara','district'=>'Çankaya','deliveryAddress'=>'Adres','productName'=>'Ürün','invoiceAmount'=>'10,00','quantity'=>'1','invoiceName'=>'Firma','invoiceAddress'=>'Adres','recipientEmail'=>'a@example.test','customerType'=>'Bireysel','taxNumber'=>''];
$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Ayşe'];$writer=new ShipmentWriter($db,$cipher);$created=$writer->create($client,1,['all','n11','2026-10'],['record'=>$draft,'requestId'=>str_repeat('a',48)]);$writer->create($client,1,['all','n11','2026-10'],['record'=>$draft,'requestId'=>str_repeat('a',48)]);
check($feed->listing(1,1,0)['counts']['all']===2,'Müşteri audit ve push tek zil öğesi oluşturur');
while($worker->runOne()){}check(count($seen)===4&&str_contains($seen[2]['body'],'Ayşe')&&str_contains($seen[2]['body'],'ekleme yaptı'),'Müşteri değişikliği iki cihaza hesabın adıyla gider');
$db->transaction(fn()=>$notice->publish(1,'shipment',999,'n11',['operation'=>'document','fields'=>['invoiceDocument']]));while($worker->runOne()){}check(count($seen)===6&&str_contains($seen[4]['body'],'Fatura sisteme yüklendi'),'Yüklenen fatura kendi bildirim başlığını kullanır');
$db->transaction(fn()=>$events->record(1,1,['events'=>['shipment_added'],'provider'=>'Koçtaş','shipmentCount'=>1]));while($worker->runOne()){}check(count($seen)===6,'Aktarım tamamlandı yalnız zil listesinde kalmalı');
check($feed->listing(1,1,0)['counts']['all']===3,'Sessiz aktarım kalıcı zil kaydını korumalı');
$before=count($db->all('SELECT id FROM order_notifications'));try{$db->transaction(function()use($events){$events->record(1,1,['events'=>['cancelled'],'provider'=>'n11']);throw new RuntimeException('rollback');});}catch(RuntimeException){}check(count($db->all('SELECT id FROM order_notifications'))===$before,'Başarısız kayıt bildirim üretmez');
$devices->change($user,$registrations[1]['id'],['enabled'=>false,'sound'=>true]);$db->transaction(fn()=>$events->record(1,2,['events'=>['cancelled'],'provider'=>'n11']));while($worker->runOne()){}check(count($seen)===7,'Kapalı cihaz gönderim dışında');
echo "Operasyon bildirimleri: iptal, aktarım, müşteri değişikliği/yükleme, iki cihaz, masaüstü uyumluluğu, tekilleştirme, şirket sınırı, zil işlemleri ve rollback geçti.\n";
