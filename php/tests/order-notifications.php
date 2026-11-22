<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,NotificationFeed,NotificationState,OrderNotifications,HttpError};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($db);NotificationSchema::migrate($db);Omnigo\ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma A'),(2,'Firma B')");$db->run("INSERT INTO users VALUES(1,'admin@test.test','Yönetici','hash','admin',NULL),(2,'admin2@test.test','Diğer','hash','admin',NULL)");
$orders=new OrderNotifications($db);$feed=new NotificationFeed($db);$state=new NotificationState($db);
$value=['provider'=>'Pazarama','orderNumber'=>'secret-order-1','createdAt'=>gmdate('c',time()-86400)];
$orders->observe(1,$value);check($feed->listing(1,1,0)['counts']['all']===0,'İlk tarama geçmiş siparişleri bildirmemeli.');
$orders->observe(1,[...$value,'createdAt'=>gmdate('c')]);$orders->observe(1,[...$value,'createdAt'=>gmdate('c')]);
check($feed->listing(1,1,0)['counts']['unread']===1,'Tekrar tarama bir bildirim oluşturmalı.');
$orders->observe(2,[...$value,'createdAt'=>gmdate('c')]);$orders->observe(1,[...$value,'provider'=>'n11','createdAt'=>gmdate('c')]);
check($feed->listing(1,1,0)['counts']['unread']===2&&$feed->listing(1,2,0)['counts']['unread']===1,'Pazaryeri ve firma ayrımı korunmalı.');
check($orders->arrivals(null)['items']===[],'İlk masaüstü açılışında geçmiş sesleri çalmamalı.');
$arrivals=$orders->arrivals(0);check(count($arrivals['items'])===3&&$orders->arrivals($arrivals['cursor'])['items']===[],'Masaüstü cursor tekrar bildirmemeli.');
check(!str_contains(json_encode($arrivals),'secret-order'),'Masaüstüne sipariş/müşteri özel bilgileri aktarılmamalı.');
$id=$feed->listing(1,1,0)['items'][0]['id'];$state->read(1,1,'order',$id);
check($feed->listing(1,1,0)['counts']['unread']===1&&$feed->listing(2,1,0)['counts']['unread']===2,'Okunma kullanıcıya özel olmalı.');
try{$state->read(1,2,'order',$id);throw new RuntimeException('Kapsam hatası bekleniyordu.');}catch(HttpError $e){check($e->getCode()===404,'Başka firmanın sipariş bildirimi okunmamalı.');}
$batch=$state->bulk(1,1,$feed->listing(1,1,0)['through'],true);check($feed->listing(1,1,0)['counts']['all']===0,'Toplu silme siparişleri kapsamalı.');
$state->undo(1,1,$batch);check($feed->listing(1,1,0)['counts']['all']===2,'Sipariş bildirimi geri alınabilmeli.');
echo "Sipariş bildirimleri: geçmiş kayıt, tekrar, firma/kullanıcı ayrımı, cursor ve geri alma kontrolleri geçti.\n";
