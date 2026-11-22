<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,ShipmentSchema,ShipmentOrdering,ShipmentImportParser,ShipmentImporter,ShipmentRepository,DeliveryCipher,BackupArchive};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function database():Database{$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);SupportSchema::migrate($db);return $db;}
$columns=['29.09.2026 10:11','Sipariş Veren','Alıcı Adı','905551234567',"Teslimat adresi\nİkinci satır",'ankara','Sincan','Ürün','28.811,60','4','Fatura adresi','sample@hepsifatura.com','Fatura Adı','Kurumsal',''];
$stream=fopen('php://temp','w+');fputcsv($stream,$columns,"\t",'"','');rewind($stream);$source=stream_get_contents($stream);fclose($stream);
$items=ShipmentImportParser::parse($source,'2026-09');$p=$items[0]['payload'];
foreach(['customerName'=>1,'recipientName'=>2,'phone'=>3,'deliveryAddress'=>4,'district'=>6,'productName'=>7,'invoiceAddress'=>10,'recipientEmail'=>11,'invoiceName'=>12,'customerType'=>13,'packageStatus'=>14] as $field=>$index)check($p[$field]===$columns[$index],"Alan yanlış eşleşti: $field");
check($p['invoiceCents']===2881160&&$p['quantity']===4,'Tutar aynen korunmalı; adetle yeniden çarpılmamalı.');
check($p['province']==='Ankara'&&$p['orderedAt']==='2026-09-29T10:11:00+03:00','Şehir normalleştirme ve Türkiye saati doğru olmalı.');
$key=str_repeat('q',32);$cipher=DeliveryCipher::withKey($key);$db=database();ShipmentSchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$importer=new ShipmentImporter($db,$cipher);$result=$importer->import(1,$items,hash('sha256',$source));check($result['added']===1,'Kayıt eklenmeli.');
$db->run('UPDATE shipment_entries SET cancelled=1,invoiced=1,shipped=1 WHERE id=1');
$repeat=$importer->import(1,$items,hash('sha256',$source));check($repeat['added']===0&&$repeat['skipped']===1,'Tekrar aynı kayıt eklenmemeli.');
$stored=$db->one('SELECT * FROM shipment_entries WHERE id=1');check($stored['cancelled']==1&&$stored['invoiced']==1&&$stored['shipped']==1,'Tekrar aktarım kutucukları sıfırlamamalı.');
$batch=[];for($i=0;$i<55;$i++){$item=$items[0];$item['sourceRow']=$i+1;$item['payload']['orderedAt']=sprintf('2026-09-%02dT%02d:00:00+03:00',1+($i%27),$i%24);$batch[]=$item;}
$importer->import(1,$batch,hash('sha256','older-batch'));$repository=new ShipmentRepository($db,$cipher);$selection=['Ankara','Hepsiburada','2026-09'];
$page0=$repository->listing(1,$selection);$all=[];
check($page0['pageSize']===10&&count($page0['items'])===10,'Bir sayfada 10 kayıt olmalı.');
for($page=0;$page<ceil($page0['total']/10);$page++){$slice=$repository->listing(1,$selection,$page);check(count($slice['items'])<=10,'Sayfa sınırı aşılmamalı.');$all=[...$all,...$slice['items']];}
check(count(array_unique(array_column($all,'id')))===56,'Sayfalar arasında kayıt tekrarı veya kaybı olmamalı.');
check($repository->listing(1,$selection,999)['page']===5,'Son sayfayı aşan seçim son sayfaya dönmeli.');
check(count($all)===56&&$all[0]['id']===1,'En yeni tarih eklenme sırasından bağımsız en üstte olmalı.');
for($i=1;$i<count($all);$i++)check(strtotime($all[$i-1]['orderedAt'])>=strtotime($all[$i]['orderedAt']),'Sayfa sınırında da tarih sırası korunmalı.');
check(!isset($all[0]['import']),'İçe aktarma bilgileri arayüze aktarılmamalı.');
// An archive made before ordered_at existed must remain restorable.
$old=database();$old->run('CREATE TABLE shipment_entries (id INTEGER PRIMARY KEY,company_id INT NOT NULL,city VARCHAR(40),provider VARCHAR(30),month VARCHAR(7),payload MEDIUMTEXT,completed INT DEFAULT 0,invoiced INT DEFAULT 0,version INT DEFAULT 1,created VARCHAR(35),updated VARCHAR(35))');$old->run("INSERT INTO companies VALUES(1,'Örnek')");
$old->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(1,1,?,?,?,?,?,?)',['Ankara','Hepsiburada','2026-09',$cipher->seal(1,'shipment:1',$p),gmdate('c'),gmdate('c')]);
$old->run('UPDATE shipment_entries SET completed=1,invoiced=1');
$archive=BackupArchive::create($old,$key);$target=database();ShipmentSchema::install($target);BackupArchive::restore($target,$archive,$key);
check($target->one('SELECT ordered_at FROM shipment_entries')['ordered_at']==='2026-09-29T07:11:00Z','Eski yedek sipariş zamanı geri yüklenmeli.');
check((int)$target->one('SELECT cancelled FROM shipment_entries')['cancelled']===0,'Eski tamamlandı işareti iptal olmamalı.');
$old->run("ALTER TABLE shipment_entries ADD COLUMN ordered_at VARCHAR(20) NOT NULL DEFAULT ''");ShipmentOrdering::backfill($old,$cipher);
$archive099=BackupArchive::create($old,$key);$target099=database();ShipmentSchema::install($target099);BackupArchive::restore($target099,$archive099,$key);
$restored=$target099->one('SELECT * FROM shipment_entries');check((int)$restored['invoiced']===1&&(int)$restored['cancelled']===0,'0.9.9 yedeğinde fatura korunmalı, iptal kapalı başlamalı.');
$old->run('ALTER TABLE shipment_entries ADD COLUMN cancelled INT NOT NULL DEFAULT 0');$old->run('UPDATE shipment_entries SET cancelled=1');
$target0910=database();ShipmentSchema::install($target0910);BackupArchive::restore($target0910,BackupArchive::create($old,$key),$key);
$restored0910=$target0910->one('SELECT * FROM shipment_entries');check((int)$restored0910['shipped']===0&&(int)$restored0910['cancelled']===1&&(int)$restored0910['invoiced']===1,'0.9.10 yedeğinde yeni sevk işareti kapalı başlamalı; diğerleri korunmalı.');
$old->run('ALTER TABLE shipment_entries ADD COLUMN shipped INT NOT NULL DEFAULT 0');$old->run('UPDATE shipment_entries SET shipped=1');
$target0912=database();ShipmentSchema::install($target0912);BackupArchive::restore($target0912,BackupArchive::create($old,$key),$key);
$restored0912=$target0912->one('SELECT * FROM shipment_entries');check($restored0912['row_status']===null&&\Omnigo\ShipmentStatus::resolve($restored0912)==='cancelled','0.9.12 yedeği ve eski iptal işareti korunmalı.');
$old->run('UPDATE shipment_entries SET shipped=0');
ShipmentSchema::install($old);ShipmentSchema::install($old);$migrated=$old->one('SELECT * FROM shipment_entries');
check((int)$migrated['invoiced']===1&&(int)$migrated['completed']===1&&(int)$migrated['cancelled']===1&&(int)$migrated['shipped']===0,'Tekrarlanan geçiş mevcut işaretleri değiştirmemeli.');
$db->run("UPDATE shipment_entries SET row_status='delivered' WHERE id=1");
$targetCurrent=database();ShipmentSchema::install($targetCurrent);BackupArchive::restore($targetCurrent,BackupArchive::create($db,$key),$key);
$restoredStatus=$targetCurrent->one('SELECT row_status FROM shipment_entries WHERE id=1');check($restoredStatus['row_status']==='delivered','Yeni renk seçimi yedekte korunmalı.');
$restoredCurrent=$targetCurrent->one('SELECT invoiced,cancelled,shipped FROM shipment_entries WHERE id=1');check((int)$restoredCurrent['invoiced']===1&&(int)$restoredCurrent['cancelled']===1&&(int)$restoredCurrent['shipped']===1,'Yeni yedek üç kutucuğu da korumalı.');
echo "Aktarım: 15 kaynak alanı, çok satırlı adres, tutar/adet, müşteri/alıcı/fatura ayrımı, tekrar engeli, kutucuk koruması, sayfalar arası tarih sırası ve eski yedek geri yükleme geçti.\n";
