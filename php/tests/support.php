<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,SupportSchema,SupportRepository,HttpError,BackupArchive,Repository};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function fails(callable $action,int $code):void{try{$action();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $error){check($error->getCode()===$code,'Hata kodu farklı.');}}
function fresh():Database{$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($db);Omnigo\NotificationSchema::migrate($db);return $db;}
$db=fresh();SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Firma Bir')");$db->run("INSERT INTO companies VALUES(2,'Firma İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$db->run("INSERT INTO users VALUES(2,'client@example.test','Müşteri','hash','client',1)");
$db->run("INSERT INTO users VALUES(3,'other@example.test','Diğer müşteri','hash','client',2)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$other=['id'=>3,'role'=>'client','company_id'=>2];
$repo=new SupportRepository($db);check($repo->ready(),'Şema hazır olmalı.');
$request=['subject'=>'Satış raporu sorusu','category'=>'question','body'=>'Bu ayın raporunu paylaşabilir misiniz?','requestId'=>str_repeat('a',48)];
$id=$repo->create($client,1,$request);check($repo->create($client,1,$request)===$id,'Aynı talep çoğaltılmamalı.');
check(count($repo->detail($admin,1,$id)['messages'])===1,'İlk mesaj bir kez saklanmalı.');
fails(fn()=>$repo->detail($other,1,$id),403);fails(fn()=>$repo->detail($other,2,$id),404);fails(fn()=>$repo->create($other,1,$request),403);
$reply=['body'=>'Raporunuzu hazırlıyoruz.','requestId'=>str_repeat('b',48)];
$repo->reply($admin,1,$id,$reply);$repo->reply($admin,1,$id,$reply);
$detail=$repo->detail($client,1,$id);check(count($detail['messages'])===2&&$detail['status']==='answered','Yanıt müşteriye bir kez yansımalı.');
fails(fn()=>$repo->status($client,1,$id,'closed'),403);
$repo->status($admin,1,$id,'closed');fails(fn()=>$repo->reply($client,1,$id,['body'=>'Test','requestId'=>str_repeat('c',48)]),409);
$repo->status($admin,1,$id,'open');$repo->reply($client,1,$id,['body'=>'Ek sorum var.','requestId'=>str_repeat('c',48)]);
check($repo->detail($admin,1,$id)['status']==='open','Müşteri mesajı yönetici yanıtı beklemeli.');
fails(fn()=>$repo->reply($other,2,$id,$reply),404);fails(fn()=>$repo->status($other,1,$id,'closed'),403);
fails(fn()=>$repo->create($client,1,[...$request,'requestId'=>'invalid']),400);
for($i=0;$i<9;$i++)$repo->create($client,1,[...$request,'requestId'=>str_pad(dechex($i),48,'0',STR_PAD_LEFT)]);
fails(fn()=>$repo->create($client,1,[...$request,'requestId'=>str_repeat('d',48)]),429);
check(count($repo->listing($other,2)['items'])===0,'Firmalar arası talep görünmemeli.');
$key=random_bytes(32);$archive=BackupArchive::create($db,$key);$copy=fresh();BackupArchive::restore($copy,$archive,$key);
check((int)$copy->one('SELECT COUNT(*) AS n FROM support_tickets')['n']===10,'Destek talepleri yedekten dönmeli.');
check((int)$copy->one('SELECT COUNT(*) AS n FROM support_messages')['n']===12,'Destek mesajları yedekten dönmeli.');
$legacy=new Database(['dsn'=>'sqlite::memory:']);$legacy->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$oldArchive=BackupArchive::create($legacy,$key);BackupArchive::restore(fresh(),$oldArchive,$key);
echo "Destek: firma izolasyonu, çift gönderim koruması, yanıt, durum, hız sınırı ve eski/yeni yedek geri yükleme geçti.\n";
