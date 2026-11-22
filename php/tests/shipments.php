<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentRepository,ShipmentRoutes,DeliveryCipher,Security,HttpError,NotificationSchema,SupportSchema,BackupArchive};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata: '.$error->getCode());}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL),(2,'client@example.test','Müşteri','hash','client',1)");
$key=tempnam(sys_get_temp_dir(),'shipment-key');file_put_contents($key,random_bytes(32));
try{
 $cipher=new DeliveryCipher($key);$repository=new ShipmentRepository($db,$cipher);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client'];
 $selection=ShipmentRepository::selection(['city'=>'Ankara','provider'=>'Hepsiburada','month'=>'2026-06']);
 check($repository->listing(1,$selection)['total']===0,'Başlangıçta sahte kayıt olmamalı.');
 $fixture=['orderedAt'=>'2026-06-07T13:15:00+03:00','customerName'=>'ÖRNEK Müşteri','recipientName'=>'ÖRNEK Alıcı','phone'=>'TEST','deliveryAddress'=>'ÖRNEK teslimat','province'=>'Ankara','district'=>'Çankaya','productName'=>'ÖRNEK Ürün','invoiceCents'=>123456,'quantity'=>2,'invoiceName'=>'ÖRNEK Fatura','invoiceAddress'=>'ÖRNEK fatura adresi','recipientEmail'=>'example@example.test','customerType'=>'Bireysel','packageStatus'=>'Hazırlanıyor','privateField'=>'Gizli'];
 $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(?,?,?,?,?,?,?,?)',[1,1,...$selection,$cipher->seal(1,'shipment:1',$fixture),gmdate('c'),gmdate('c')]);
 $db->run('UPDATE shipment_entries SET completed=1 WHERE id=1');
 $entry=$repository->listing(1,$selection)['items'][0];
 check(!$entry['cancelled']&&!isset($entry['completed']),'Eski tamamlama işareti iptal sayılmamalı ve arayüze dönmemeli.');
 fails(fn()=>$repository->toggle($admin,1,1,$selection,['field'=>'completed','value'=>true,'version'=>1]),400);
 check($entry['customerName']!==$entry['recipientName']&&$entry['deliveryAddress']!==$entry['invoiceAddress'],'Müşteri/alıcı ve teslimat/fatura alanları ayrı kalmalı.');
 check(!isset($entry['privateField']),'Yalnızca tanımlı alanlar döner.');
 check(!str_contains($db->one('SELECT payload FROM shipment_entries')['payload'],'ÖRNEK'),'Kişisel bilgiler şifreli saklanmalı.');
 check($repository->listing(2,$selection)['total']===0,'Firmalar ayrılmalı.');
 foreach([['Eskişehir','Hepsiburada','2026-06'],['Ankara','n11','2026-06'],['Ankara','Hepsiburada','2026-07']] as $other)check($repository->listing(1,$other)['total']===0,'Şehir/pazaryeri/ay ayrımı korunmalı.');
 fails(fn()=>(new ShipmentRoutes($db,new Security($db,[])))->handle('/api/admin/shipments','GET',$client),403);
 fails(fn()=>$repository->toggle($client,1,1,$selection,['field'=>'cancelled','value'=>true,'version'=>1]),403);
 fails(fn()=>$repository->toggle($admin,2,1,$selection,['field'=>'cancelled','value'=>true,'version'=>1]),404);
 fails(fn()=>$repository->toggle($admin,1,1,['Ankara','n11','2026-06'],['field'=>'cancelled','value'=>true,'version'=>1]),404);
 fails(fn()=>$repository->toggle($admin,1,1,$selection,['field'=>'payload','value'=>true,'version'=>1]),400);
 fails(fn()=>$repository->toggle($admin,1,1,$selection,['field'=>'cancelled','value'=>'true','version'=>1]),400);
 $saved=$repository->toggle($admin,1,1,$selection,['field'=>'cancelled','value'=>true,'version'=>1]);
 check($saved['cancelled']&&!$saved['invoiced']&&$saved['version']===2,'İptal kaydolmalı.');
 fails(fn()=>$repository->toggle($admin,1,1,$selection,['field'=>'invoiced','value'=>true,'version'=>1]),409);
 $repository->toggle($admin,1,1,$selection,['field'=>'invoiced','value'=>true,'version'=>2]);
 $repository->toggle($admin,1,1,$selection,['field'=>'cancelled','value'=>false,'version'=>3]);
 $saved=$repository->listing(1,$selection)['items'][0];check(!$saved['cancelled']&&$saved['invoiced'],'Kutucuklar bağımsız; iptal kaldırılabilir.');
 $repository->toggle($admin,1,1,$selection,['field'=>'invoiced','value'=>false,'version'=>4]);
 check(!$repository->listing(1,$selection)['items'][0]['invoiced'],'Fatura işareti kaldırılabilir.');
 check(count($db->all('SELECT * FROM audit'))===4,'Değişiklikler denetim izine kaydolmalı.');
 $saved=$repository->toggle($admin,1,1,$selection,['field'=>'shipped','value'=>true,'version'=>5]);
 check($saved['shipped']&&!$saved['invoiced']&&!$saved['cancelled'],'Sevk işareti diğer kutucuklardan bağımsız olmalı.');
 $repository->toggle($admin,1,1,$selection,['field'=>'invoiced','value'=>true,'version'=>6]);
 $repository->toggle($admin,1,1,$selection,['field'=>'shipped','value'=>false,'version'=>7]);
 $saved=$repository->listing(1,$selection)['items'][0];check(!$saved['shipped']&&$saved['invoiced'],'Sevk işareti kaldırılınca fatura korunmalı.');
 check($db->one("SELECT COUNT(*) AS total FROM audit WHERE action='shipment.shipped'")['total']==2,'Sevk değişiklikleri kaydedilmeli.');
 $all=ShipmentRepository::selection(['city'=>'all','provider'=>'Hepsiburada','month'=>'2026-06'],true);
 fails(fn()=>ShipmentRepository::selection(['city'=>'all','provider'=>'Hepsiburada','month'=>'2026-06']),400);
 fails(fn()=>ShipmentRepository::selection(['city'=>'unknown','provider'=>'Hepsiburada','month'=>'2026-06'],true),400);
 fails(fn()=>$repository->toggle($admin,2,1,$all,['field'=>'shipped','value'=>true,'version'=>8]),404);
 fails(fn()=>$repository->toggle($admin,1,1,['all','n11','2026-06'],['field'=>'shipped','value'=>true,'version'=>8]),404);
 $saved=$repository->toggle($admin,1,1,$all,['field'=>'shipped','value'=>true,'version'=>8]);
 check($saved['shipped']&&$saved['invoiced'],'Tüm şehirler görünümünde işaretleme çalışmalı, mevcut işaretler korunmalı.');
 foreach(['2026-05','2027-01'] as $month)fails(fn()=>ShipmentRepository::selection(['city'=>'Ankara','provider'=>'Hepsiburada','month'=>$month]),400);
 $backup=BackupArchive::read(BackupArchive::create($db,str_repeat('s',32)),str_repeat('s',32));
 check(count($backup['tables']['shipment_entries']['rows'])===1,'Sevkiyat kaydı yedeğe dahil olmalı.');
 echo "Sevkiyat: yetki, firma/şehir/pazaryeri/ay ayrımı, şifreleme, iki kutucuk, çakışma koruması, denetim izi ve yedek testi geçti.\n";
}finally{unlink($key);}
