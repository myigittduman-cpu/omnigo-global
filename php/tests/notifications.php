<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,SupportSchema,SupportRepository,NotificationFeed,InboxRepository,NotificationSchema,NotificationState,BackupArchive,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($db);NotificationSchema::migrate($db);Omnigo\ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Vizyona'),(2,'Diğer mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@test.test','Yönetici','hash','admin',NULL),(2,'client@test.test','Müşteri','hash','client',1),(3,'other@test.test','Diğer','hash','client',2)");
$support=new SupportRepository($db);$feed=new NotificationFeed($db);$inbox=new InboxRepository($db);
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];
$ticket=['subject'=>'Rapor sorusu','category'=>'question','body'=>'Örnek içerik','requestId'=>str_repeat('a',48)];
$ticketId=$support->create($client,1,$ticket);
$support->reply($client,1,$ticketId,['body'=>'Ek açıklama','requestId'=>str_repeat('b',48)]);
$support->reply($admin,1,$ticketId,['body'=>'Yönetici yanıtı','requestId'=>str_repeat('c',48)]);
$support->create($admin,1,[...$ticket,'requestId'=>str_repeat('d',48)]);
$support->create(['id'=>3,'role'=>'client','company_id'=>2],2,$ticket);
$thread=['id'=>'example','title'=>'Ürün sorusu','updated'=>'2026-10-08T12:00:00Z','replyNeeded'=>true,'recipients'=>[],'entities'=>[],'fingerprint'=>str_repeat('d',64),'partial'=>false,'messages'=>[]];
foreach(range(1,32) as $i)$thread['messages'][]=['id'=>'message-'.$i,'sender'=>'CUSTOMER_USER','body'=>'Özel içerik','created'=>'2026-10-08T12:00:00Z','attachments'=>0];
$thread['messages'][]=['id'=>'outgoing','sender'=>'SHOP_USER','body'=>'Yanıt','created'=>'2026-10-08T12:00:00Z','attachments'=>0];
$thread['messages'][]=['id'=>'operator','sender'=>'OPERATOR_USER','body'=>'Sistem','created'=>'2026-10-08T12:00:00Z','attachments'=>0];
$inbox->save(1,$thread);$inbox->save(2,[...$thread,'messages'=>[$thread['messages'][0]]]);
foreach(range(1,32) as $i)$db->run('INSERT INTO order_notifications(company_id,provider,order_key,created) VALUES(?,?,?,?)',[1,'n11',hash('sha256','order-'.$i),'2026-10-08T12:00:00Z']);
$db->run('INSERT INTO order_notifications(company_id,provider,order_key,created) VALUES(?,?,?,?)',[2,'n11',hash('sha256','other-order'),'2026-10-08T12:00:00Z']);
$first=$feed->listing(1,1,0);$second=$feed->listing(1,1,1);$all=[...$first['items'],...$second['items']];
foreach($all as $event)check(!empty($event['createdAt'])&&($event['type']!=='marketplace'||$event['createdAt']==='2026-10-08T12:00:00Z'),'Bildirim kaynak tarihi korunmalı.');
check(count($first['items'])===30&&$first['hasMore']&&!$second['hasMore'],'Sayfalama doğru olmalı.');
check(count($all)===34,'32 sipariş, 1 talep ve 1 müşteri yanıtı görünmeli.');
check(!in_array('marketplace',array_column($all,'type'),true)&&$first['through']['marketplace']===0,'Eski soru kayıtları listede ve sayaçta görünmemeli.');
check(count(array_unique(array_map(fn($r)=>$r['type'].':'.$r['id'],$all)))===34,'Sayfalar aynı kaydı tekrarlamamalı.');
check(count(array_filter($all,fn($r)=>$r['type']==='support'&&$r['id']===$ticketId))===1,'Talep açılışı iki kez görünmemeli.');
foreach($all as $row){check($row['company']==='Vizyona','Firma izolasyonu korunmalı.');check(!isset($row['body'])&&!isset($row['preview'])&&!isset($row['payload']),'Özel metin aktarılmamalı.');}
check(count($feed->listing(1,2,0)['items'])===2,'Diğer mağazanın sadece kendi kayıtları olmalı.');
$state=new NotificationState($db);$limits=$first['through'];
$state->read(1,1,'support',$ticketId);check($feed->listing(1,1,0,'read')['counts']['read']===1,'Tek talep okunmalı.');
try{$state->read(1,2,'support',$ticketId);throw new RuntimeException('Kapsam hatası bekleniyordu.');}catch(HttpError $e){check($e->getCode()===404,'Başka firma talebi okunmamalı.');}
$newThread=[...$thread,'id'=>'later','messages'=>[['id'=>'new-arrival','sender'=>'CUSTOMER_USER','body'=>'Yeni soru','created'=>'2026-10-09T12:00:00Z','attachments'=>0]]];
$inbox->save(1,$newThread);
$db->run('INSERT INTO order_notifications(company_id,provider,order_key,created) VALUES(?,?,?,?)',[1,'n11',hash('sha256','new-order'),'2026-10-09T12:00:00Z']);
$state->bulk(1,1,$limits,false);$state->bulk(1,1,$limits,false);
check(count($feed->listing(1,1,0,'unread')['items'])===1,'Toplu okuma sonradan gelen siparişe dokunmamalı.');
check($feed->listing(1,1,0,'all')['counts']['read']===34,'Tüm sayfalar okunmalı.');
check((new InboxRepository($db))->summary(1,0,false,1)['unreadCount']===33,'Gizlenen soru kayıtlarının okunma durumu değiştirilmemeli.');
try{$feed->listing(3,1,0,'all');throw new RuntimeException('Başka müşterinin bildirimlerine erişildi.');}catch(HttpError $e){check($e->getCode()===403,'Başka müşterinin bildirimlerine erişim reddedilir.');}
$batch=$state->bulk(1,1,$limits,true);
check($feed->listing(1,1,0,'all')['counts']['all']===1,'Silme tüm sayfalara uygulanmalı, yeni sipariş korunmalı.');
try{$feed->listing(3,1,0,'all');throw new RuntimeException('Firma sınırı aşıldı');}catch(HttpError $e){check($e->getCode()===403,'Silme başka firmanın erişimini açmamalı.');}
check($feed->listing(1,2,0,'all')['counts']['all']===2,'Silme başka firmayı etkilememeli.');
$state->undo(1,2,$batch);check($feed->listing(1,1,0,'all')['counts']['all']===1,'Yanlış firma geri alma işlemini uygulamamalı.');
$state->undo(1,1,$batch);$state->undo(1,1,$batch);
check($feed->listing(1,1,0,'all')['counts']['all']===35,'Geri alma idempotent olmalı.');
check($feed->listing(1,1,0,'all')['counts']['read']===34,'Geri alma okunma durumunu korumalı.');
$batch=$state->bulk(1,1,$limits,true);$laterBatch=$state->bulk(1,1,$feed->listing(1,1,0)['through'],true);$state->undo(1,1,$laterBatch);
check($feed->listing(1,1,0)['counts']['all']===1,'Yeni silme geri alınırken eski silinenler dönmemeli.');
check((int)$db->one('SELECT COUNT(*) AS n FROM inbox_messages')['n']===36,'Konuşma mesajları korunmalı.');
check((int)$db->one('SELECT COUNT(*) AS n FROM support_tickets')['n']===3,'Destek talepleri korunmalı.');
$key=random_bytes(32);$archive=BackupArchive::create($db,$key);$snapshot=BackupArchive::read($archive,$key);
check(isset($snapshot['tables']['notification_states']),'Bildirim durumu yedeklenmeli.');
$copy=new Database(['dsn'=>'sqlite::memory:']);$copy->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($copy);NotificationSchema::migrate($copy);Omnigo\ShipmentSchema::install($copy);BackupArchive::restore($copy,$archive,$key);
check((new NotificationFeed($copy))->listing(1,1,0)['counts']===$feed->listing(1,1,0)['counts'],'Yedekten dönünce durum korunmalı.');
echo "Bildirimler: filtreler, kullanıcı/firma izolasyonu, toplu işlemler, yeni gelen kayıt koruması, geri alma ve yedekleme geçti.\n";
