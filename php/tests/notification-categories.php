<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,ShipmentSchema,SupportRepository,NotificationFeed,NotificationState,NotificationCategory,UserChangeNotifications,PushDevices,MobilePushDevices,PushWorker,DeliveryCipher,OrderNotifications,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $e){check($e->getCode()===$status,'Hata kodu yanlış.');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma'),(2,'Başka firma')");
$db->run("INSERT INTO users VALUES(1,'admin@test.test','Yönetici','hash','admin',NULL),(2,'client@test.test','Müşteri','hash','client',1),(3,'other@test.test','Diğer','hash','client',2)");
$users=[];foreach([1,2,3] as $id){$token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);$users[$id]=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];}
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
$base=['kind'=>'android','appId'=>'com.omnigoglobal.admin','installationId'=>str_repeat('a',64),'token'=>str_repeat('x',140),'environment'=>'production','sound'=>true];
$mobile->save($users[1],$base);$mobile->save($users[1],[...$base,'kind'=>'ios','installationId'=>str_repeat('b',64),'token'=>str_repeat('a',64)]);
$mobile->save($users[2],[...$base,'appId'=>'com.omnigoglobal.client','installationId'=>str_repeat('c',64)]);
$mobile->save($users[3],[...$base,'appId'=>'com.omnigoglobal.client','installationId'=>str_repeat('d',64)]);
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();$devices->save($users[1],'web',['subscription'=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/category-test','keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]]]);
$support=new SupportRepository($db);$request=['subject'=>'Özel konu','category'=>'question','body'=>'GİZLİ MESAJ İÇERİĞİ','requestId'=>str_repeat('a',48)];
$ticket=$support->create($users[2],1,$request);$support->create($users[2],1,$request);
$reply=['body'=>'GİZLİ YANIT','requestId'=>str_repeat('b',48)];$support->reply($users[2],1,$ticket,$reply);$support->reply($users[2],1,$ticket,$reply);
fails(fn()=>$support->status($users[2],1,$ticket,'closed'),403);fails(fn()=>$support->status($users[2],1,$ticket,'open'),403);
$support->reply($users[1],1,$ticket,['body'=>'Yönetici cevabı','requestId'=>str_repeat('c',48)]);
check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===3,'Tekrarlanan istek, aynı durum ve yönetici işlemi yeni müşteri bildirimi üretmemeli.');
$seen=[];$worker=new PushWorker($db,$devices,function($subscription,$payload)use(&$seen){$seen[]=['app'=>$subscription['appId']??'web','payload'=>$payload];return ['success'=>true,'expired'=>false,'status'=>201];});
while($worker->runOne()){}
check(count($seen)===7&&array_count_values(array_column($seen,'app'))===['com.omnigoglobal.admin'=>4,'web'=>2,'com.omnigoglobal.client'=>1],'Dört müşteri işlemi yönetici web, Android ve iOS cihazlarına gitmeli.');
check(!str_contains(json_encode($seen,JSON_UNESCAPED_UNICODE),'GİZLİ')&&!str_contains(json_encode($db->all('SELECT payload FROM order_notifications'),JSON_UNESCAPED_UNICODE),'GİZLİ'),'Push ve genel olay kaydı özel mesaj içermemeli.');
foreach($seen as $message){if($message['app']==='com.omnigoglobal.client'){check(str_contains($message['payload']['body'],'cevap verdi'),'Müşteri yalnız kendisine gönderilen yanıtı alır');continue;}check(str_contains($message['payload']['url'],'view=support')&&str_contains($message['payload']['url'],'noticeId='),'Destek bildirimi doğru ayrıntıya gitmeli.');}
$feed=new NotificationFeed($db,$cipher);$state=new NotificationState($db);
$list=$feed->listing(1,1,0,'all','users');check($list['counts']['all']===2&&count($list['items'])===2,'Açılış push zarfı ikinci zil öğesi olmamalı.');
$userEvent=array_values(array_filter($list['items'],fn($x)=>$x['type']==='user'))[0];
check((new UserChangeNotifications($db))->detail(1,$userEvent['id'])['targetId']===$ticket,'Kullanıcı olayı kendi talebine bağlanmalı.');
fails(fn()=>(new UserChangeNotifications($db))->detail(2,$userEvent['id']),404);
$insert=function(string $type,array $payload=[])use($db,$devices){$db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[1,'n11',bin2hex(random_bytes(32)),$type,json_encode($payload),gmdate('c')]);$id=$db->insertId();$devices->enqueue($id);return $id;};
foreach(range(1,35) as $i)$insert('order');
$insert('integration',['service'=>'n11','status'=>'error','message'=>'Bağlantı sorunu']);
$insert('integration',['service'=>'n11','status'=>'recovered','message'=>'Aktarım tamamlandı','catchupVerified'=>true]);
check($feed->listing(1,1,0,'all','connections')['counts']['all']===0,'Kaldırılmış bağlantı olayları görünmez.');
check(count($feed->listing(1,1,0,'all','users')['items'])===2,'Diğer türlerin sayfalaması kullanıcı olaylarını gizlememeli.');
$first=$feed->listing(1,1,0,'all','marketplaces');$second=$feed->listing(1,1,1,'all','marketplaces');
check(count($first['items'])===30&&count($second['items'])===5&&$first['hasMore']&&!$second['hasMore'],'Kategori filtresi SQL sayfalamasından önce uygulanmalı.');
check($first['totalUnread']===37&&$first['categoryCounts']['users']['unread']===2,'Zil ve kategori sayaçları birlikte doğru olmalı.');
$limits=$feed->listing(1,1,0)['through'];$insert('order');
$state->bulk(1,1,$limits,false,'connections');
check($feed->listing(1,1,0,'all','connections')['counts']['read']===0&&$feed->listing(1,1,0,'all','marketplaces')['counts']['unread']===36,'Okundu işlemi diğer kategorilere taşmamalı.');
$batch=$state->bulk(1,1,$limits,true,'marketplaces');
check($feed->listing(1,1,0,'all','marketplaces')['counts']['all']===1,'Toplu silmede sonradan gelen sipariş korunmalı.');
check($feed->listing(1,1,0,'all','users')['counts']['all']===2&&$feed->listing(2,1,0,'all','marketplaces')['counts']['all']===0,'Silme başka kategori veya kullanıcıyı etkilememeli.');
$state->undo(1,1,$batch,'users');check($feed->listing(1,1,0,'all','marketplaces')['counts']['all']===1,'Yanlış kategoriyle geri alma diğer bölümü değiştirmemeli.');
$state->undo(1,1,$batch,'marketplaces');$state->undo(1,1,$batch,'marketplaces');check($feed->listing(1,1,0,'all','marketplaces')['counts']['all']===36,'Geri alma tekrar güvenli olmalı.');
fails(fn()=>NotificationCategory::validate('unknown'),400);
$before=(int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n'];
try{$db->transaction(function()use($db,$users,$ticket){(new UserChangeNotifications($db))->record($users[2],1,$ticket,999999,'Destek mesajı eklendi');throw new RuntimeException('rollback');});}catch(RuntimeException){}
check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===$before,'Geri alınan değişiklik bildirim üretmemeli.');
while($worker->runOne()){}check(!$worker->runOne(),'Kuyruk tamamlandığında yeniden gönderim olmamalı.');
check(count($seen)===7+36*3,'Yeni sipariş üç yönetici cihazına gitmeli; bağlantı hiçbir cihazda uyarı vermemeli.');
$poll=(new OrderNotifications($db))->arrivals(0,true,true,true);
check(count(array_filter($poll['items'],fn($x)=>$x['category']==='users'))===2,'Açık uygulama akışı kullanıcı olaylarını da almalı.');
echo "Bildirim kategorileri: filtre/sayfalama, sayaç, kategoriye özel toplu işlemler, destek tekrar koruması, web/Android/iOS kuyruğu, firma/rol sınırı, gizlilik ve rollback geçti.\n";
