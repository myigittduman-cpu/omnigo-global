<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,SupportRepository,SupportResolutionNotifications,NotificationFeed,OrderNotifications,PushDevices,MobilePushDevices,PushWorker,DeliveryCipher,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $e){check($e->getCode()===$status,'Hata kodu yanlış.');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);SupportSchema::migrate($db);\Omnigo\ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma'),(2,'Başka firma')");
$db->run("INSERT INTO users VALUES(1,'admin@test.test','Yönetici','hash','admin',NULL),(2,'client@test.test','Müşteri','hash','client',1),(3,'other@test.test','Diğer','hash','client',2),(4,'colleague@test.test','Diğer kullanıcı','hash','client',1)");
$users=[];foreach([1,2,3,4] as $id){$token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);$users[$id]=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];}
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();
foreach($users as $id=>$user){
 $devices->save($user,'web',['subscription'=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/resolved-'.$id,'keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]]]);
 foreach(['android','ios'] as $kind)$mobile->save($user,['kind'=>$kind,'appId'=>'com.omnigoglobal.'.($id===1?'admin':'client'),'installationId'=>hash('sha256',$id.$kind),'token'=>$kind==='android'?str_repeat('x',140):str_repeat('a',64),'environment'=>'production','sound'=>true]);
}
check($devices->save($users[2],'desktop',['deviceId'=>str_repeat('b',64)])['userId']===2,'Müşteri kendi masaüstü bildirimini kaydedebilir.');
$support=new SupportRepository($db);$resolutions=new SupportResolutionNotifications($db);
$ticket=$support->create($users[2],1,['subject'=>'Özel konu','category'=>'question','body'=>'GİZLİ DESTEK İÇERİĞİ','requestId'=>str_repeat('a',48)]);
$support->reply($users[1],1,$ticket,['body'=>'GİZLİ YANIT','requestId'=>str_repeat('b',48)]);
check($resolutions->listing(2,1,0,'all')['counts']['all']===1,'Omnigo yanıtı yalnız talep sahibine bildirilir.');
fails(fn()=>$support->status($users[2],1,$ticket,'closed'),403);
check($resolutions->listing(2,1,0,'all')['counts']['all']===1,'Müşteri kendi talebini kapatınca çözüm bildirimi üretilmez.');
$replyId=$resolutions->listing(2,1,0,'all')['items'][0]['id'];$resolutions->mark(2,1,$replyId,true,true);
$support->status($users[1],1,$ticket,'open');$support->status($users[1],1,$ticket,'closed');$support->status($users[1],1,$ticket,'closed');
$list=$resolutions->listing(2,1,0,'unread');$id=$list['items'][0]['id'];
check($list['counts']['all']===1&&$list['counts']['unread']===1&&$list['items'][0]['targetId']===$ticket,'Yönetici çözümü tek olay üretmeli.');
foreach([[3,2],[4,1],[2,2]] as [$uid,$company]){check($resolutions->listing($uid,$company,0,'all')['counts']['all']===0,'Yalnız talebi açan kullanıcı görebilir.');fails(fn()=>$resolutions->detail($uid,$company,$id),404);fails(fn()=>$resolutions->mark($uid,$company,$id,false,true),404);}
check(count(array_filter((new NotificationFeed($db,$cipher))->listing(1,1,0,'all')['items'],fn($x)=>$x['type']==='resolved'))===0,'Müşteri çözümü yönetici zilinde görünmez.');
check(count(array_filter((new OrderNotifications($db))->arrivals(0,true,true,true)['items'],fn($x)=>($x['type']??'')==='resolved'))===0,'Yönetici açık uygulama akışı çözüm olayını içermemeli.');
$seen=[];$worker=new PushWorker($db,$devices,function($subscription,$payload)use(&$seen){$seen[]=$payload;return ['success'=>true,'expired'=>false,'status'=>201];});while($worker->runOne()){}
$resolved=array_values(array_filter($seen,fn($x)=>str_contains($x['body'],'destek talebinizi çözüldü')));
check(count($resolved)===3,'Çözüm yalnız sahibinin web, Android ve iOS cihazlarına gitmeli.');
check($resolutions->listing(2,1,0,'unread')['counts']['unread']===1,'Mobil gönderim kısıtlaması müşteri bildirimini ve sayacını korumalı.');
foreach($resolved as $message)check(str_contains($message['url'],'noticeType=user&noticeId='.$id),'1.0.5 ve üstü paketlerin destek ayrıntısı yolu kullanılmalı.');
check(!str_contains(json_encode($seen,JSON_UNESCAPED_UNICODE),'GİZLİ'),'Bildirim gövdesinde özel yazışma olmamalı.');
$resolutions->mark(2,1,$id,false,true);check($resolutions->listing(2,1,0,'read')['counts']['unread']===0,'Tekil okundu sayacı günceller.');
$batch=$resolutions->mark(2,1,$id,true);check($resolutions->listing(2,1,0,'all')['counts']['all']===0,'Temizleme listeyi gizler.');
$support->status($users[1],1,$ticket,'open');$support->status($users[1],1,$ticket,'closed');
check($resolutions->listing(2,1,0,'unread')['counts']['unread']===1,'Yeniden açılıp çözülen talep yeni olay olmalı.');
$resolutions->mark(2,1,$id,true);$resolutions->undo(4,1,$batch);check($resolutions->listing(2,1,0,'all')['counts']['all']===1,'Eski sınır yeni geleni, başka kullanıcı geri alması sahibini etkilemez.');
$resolutions->undo(2,1,$batch);$resolutions->undo(2,1,$batch);check($resolutions->listing(2,1,0,'all')['counts']['all']===2,'Geri alma tekrar güvenli olmalı.');
$before=count($db->all('SELECT * FROM order_notifications'));$jobs=count($db->all('SELECT * FROM push_deliveries'));
try{$db->transaction(function()use($resolutions,$users,$db,$ticket){$resolutions->record($users[1],1,$db->one('SELECT * FROM support_tickets WHERE id=?',[$ticket]),999999);throw new RuntimeException('rollback');});}catch(RuntimeException){}
check(count($db->all('SELECT * FROM order_notifications'))===$before&&count($db->all('SELECT * FROM push_deliveries'))===$jobs,'Rollback olay ve kuyruk kaydını birlikte geri almalı.');
$web=$db->one("SELECT * FROM notification_devices WHERE user_id=2 AND kind='web'");check($devices->usable($web),'Müşteri web cihazı geçerli oturumla kullanılmalı.');$db->run('DELETE FROM sessions WHERE user_id=2');check(!$devices->usable($web),'Çıkışta web cihazı kapanmalı.');
echo "Destek çözümü: rol/firma/kullanıcı ayrımı, web/Android/iOS gönderim kapsamı, tekrar koruması, okunma/silme/geri alma, bağlantı yolu, gizlilik ve rollback geçti.\n";
