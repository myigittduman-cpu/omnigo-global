<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,SupportSchema,NotificationSchema,ShipmentSchema,SupportRepository,NotificationFeed,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function fails(callable $fn,int $code):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('İşlem reddedilmeli.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
SupportSchema::migrate($db);NotificationSchema::migrate($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma Bir'),(2,'Firma İki')");
$db->run("INSERT INTO users VALUES(1,'admin@test.test','Omnigo','hash','admin',NULL),(2,'client@test.test','Bir','hash','client',1),(3,'other@test.test','İki','hash','client',2)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$other=['id'=>3,'role'=>'client','company_id'=>2];
$repo=new SupportRepository($db);$feed=new NotificationFeed($db);
$request=['subject'=>'Test talebi','category'=>'issue','body'=>'Örnek konu','requestId'=>str_repeat('a',48)];
$id=$repo->create($client,1,$request);$otherId=$repo->create($other,2,$request);
foreach([$admin,$client] as $actor)check($repo->listing($actor,1)['items'][0]['status']==='open','Yeni talep iki panelde açık olmalı.');
check(count(array_filter($feed->listing(1,1,0)['items'],fn($x)=>$x['type']==='support'))===1,'Yeni destek talebi bildirimi görünmeli.');
$reply=['body'=>'Yanıt verildi','requestId'=>str_repeat('b',48)];$repo->reply($admin,1,$id,$reply);
foreach([$admin,$client] as $actor)check($repo->listing($actor,1)['items'][0]['status']==='answered','Yanıt talebi çözülmüş saymamalı.');
fails(fn()=>$repo->status($client,1,$id,'closed'),403);fails(fn()=>$repo->delete($client,1,$id),403);
fails(fn()=>$repo->status($admin,2,$id,'closed'),404);fails(fn()=>$repo->delete($admin,2,$id),404);
$repo->status($admin,1,$id,'closed');$repo->status($admin,1,$id,'closed');
foreach([$admin,$client] as $actor)check($repo->listing($actor,1)['items'][0]['status']==='closed','Çözüm iki panelde görünmeli.');
$events=$db->all("SELECT payload FROM order_notifications WHERE company_id=1 AND event_type='resolved'");
check(count($events)===2,'Bir yanıt ve bir çözüm bildirimi olmalı; tekrar çözüm çoğalmamalı.');
foreach($events as $event)check(json_decode($event['payload'],true)['recipientId']===2,'Bildirim yalnız talep sahibine ait olmalı.');
fails(fn()=>$repo->reply($client,1,$id,['body'=>'Yeni','requestId'=>str_repeat('c',48)]),409);
$repo->status($admin,1,$id,'open');$repo->reply($client,1,$id,['body'=>'Ek soru','requestId'=>str_repeat('c',48)]);
check($repo->detail($admin,1,$id)['status']==='open','Yeniden açılan talep yanıt kabul etmeli.');
$before=count($repo->detail($admin,1,$id)['messages']);
$repo->delete($admin,1,$id);$repo->delete($admin,1,$id);
foreach([$admin,$client] as $actor){check($repo->listing($actor,1)['items']===[],'Silinen talep iki listeden çıkmalı.');fails(fn()=>$repo->detail($actor,1,$id),404);}
fails(fn()=>$repo->reply($admin,1,$id,['body'=>'Yeni','requestId'=>str_repeat('d',48)]),404);
fails(fn()=>$repo->status($admin,1,$id,'open'),404);
check((int)$db->one('SELECT COUNT(*) n FROM support_messages WHERE ticket_id=?',[$id])['n']===$before,'Mesaj geçmişi korunmalı.');
check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE record_id=? AND action='Destek talebi silindi'",[$id])['n']===1,'Silme yinelenmemeli.');
check(!array_filter($feed->listing(1,1,0)['items'],fn($x)=>$x['type']==='support'),'Silinen talep ilk bildirimden de kalkmalı.');
check($repo->detail($other,2,$otherId)['status']==='open','Başka firmanın talebi değişmemeli.');
$repo->status($admin,2,$otherId,'closed');$repo->delete($admin,2,$otherId);
check($repo->listing($other,2)['items']===[],'Çözülmüş talep de silinebilmeli.');
echo "Destek yönetimi: açık/yanıt/çözüm, iki panel, rol ve firma sınırları, tekrar koruması, bildirim ve silme geçmişi geçti.\n";
