<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,Security,ShipmentSchema,NotificationSchema,SupportSchema,ShipmentRepository,ShipmentWriter,ShipmentDocuments,ShipmentBulkStatus,ShipmentDeletion,ShipmentChangeNotifications,NotificationFeed,NotificationState,NotificationRoutes,DeliveryCipher,HttpError,BackupArchive};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $status):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata: '.$error->getMessage());return;}throw new RuntimeException('İşlem engellenmeliydi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','x','admin',NULL),(2,'b@example.test','Ayşe Örnek','x','client',1),(3,'c@example.test','Diğer','x','client',2),(4,'d@example.test','Diğer Omnigo','x','admin',NULL)");
$admin=['id'=>1,'role'=>'admin','name'=>'Yönetici'];$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Ayşe Örnek'];$other=['id'=>3,'role'=>'client','company_id'=>2,'name'=>'Diğer'];
$cipher=DeliveryCipher::withKey(str_repeat('n',32));$repo=new ShipmentRepository($db,$cipher);$writer=new ShipmentWriter($db,$cipher);$deletion=new ShipmentDeletion($db,$cipher);$events=new ShipmentChangeNotifications($db,$cipher);$feed=new NotificationFeed($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek Müşteri','recipientName'=>'Örnek Alıcı','phone'=>'05000000000','province'=>'Ankara','district'=>'Çankaya','deliveryAddress'=>'Örnek adres','productName'=>'Örnek ürün','invoiceAmount'=>'100,25','quantity'=>'1','invoiceName'=>'Örnek Firma','invoiceAddress'=>'Fatura adresi','recipientEmail'=>'ornek@example.test','customerType'=>'Bireysel','taxNumber'=>''];
$count=fn()=>(int)$db->one("SELECT COUNT(*) AS n FROM audit WHERE action='shipment.client_change'")['n'];
$last=fn()=>$events->detail(1,(int)$db->one("SELECT MAX(id) AS id FROM audit WHERE action='shipment.client_change' AND company_id=1")['id']);
$created=$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)]);$id=$created['id'];
check($count()===1&&$last()['actorName']==='Ayşe Örnek'&&$last()['rowId']===$id,'Ekleyen kişi ve sabit satır numarası');
$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)]);check($count()===1,'İstek tekrarı bildirim çoğaltmamalı');
$writer->update($admin,1,$id,$scope,['version'=>1,'record'=>$draft]);check($count()===1,'Yönetici değişikliği müşteriden bildirim olmamalı');
$writer->update($client,1,$id,$scope,['version'=>2,'record'=>$draft]);check($count()===1,'Değişmeyen içerik bildirilmemeli');
$edited=[...$draft,'phone'=>'05001112233','quantity'=>'2'];$writer->update($client,1,$id,$scope,['version'=>3,'record'=>$edited]);
check($last()['changes']===[['field'=>'phone','before'=>'05000000000','after'=>'05001112233'],['field'=>'quantity','before'=>1,'after'=>2]],'Yalnızca değişen alanlar ve doğru eski/yeni değerler');
$db->run("UPDATE users SET name='Yeni isim' WHERE id=2");check($last()['actorName']==='Ayşe Örnek','Geçmiş bildirimin kişi adı değişmemeli');
denied(fn()=>$writer->update($client,1,$id,$scope,['version'=>3,'record'=>$draft]),409);check($count()===2,'Çakışan kayıt bildirilmemeli');
$repo->toggle($client,1,$id,$scope,['field'=>'rowStatus','value'=>'shipped','version'=>4]);
$bulk=new ShipmentBulkStatus($db,$cipher);$bulk->save($client,1,$scope,['items'=>[['id'=>$id,'version'=>5]],'value'=>'completed']);check($count()===4,'Tekli ve toplu durum değişikliği');
$bulk->save($client,1,$scope,['items'=>[['id'=>$id,'version'=>6]],'value'=>'completed']);check($count()===4,'Aynı durum yeniden bildirilmemeli');
$folder=sys_get_temp_dir().'/omnigo-notice-'.bin2hex(random_bytes(6));$docs=new ShipmentDocuments($db,$cipher,$folder);
$image=imagecreatetruecolor(12,12);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
try{
 $docs->save($client,1,$id,'invoice',$scope,7,str_repeat('d',48),$bytes);$docs->save($client,1,$id,'invoice',$scope,7,str_repeat('d',48),$bytes);check($count()===5&&$last()['changes'][0]['field']==='invoiceDocument','Belge yalnızca bir kez bildirilir');
 denied(fn()=>$deletion->save($other,1,$id,$scope,['version'=>8]),403);denied(fn()=>$deletion->save($client,1,$id,['all','Pazarama','2026-10'],['version'=>8]),404);
 denied(fn()=>$deletion->save($client,1,$id,$scope,['version'=>7]),409);
 $deletion->save($client,1,$id,$scope,['version'=>8]);$deletion->save($client,1,$id,$scope,['version'=>8]);check($count()===6&&$repo->listing(1,$scope)['total']===0&&$repo->export(1,$scope)['total']===0,'Silme iki panelden ve dışa aktarmadan çıkar, tekrar bildirim üretmez');
 check($deletion->latest($client,1,$scope)===['id'=>$id,'version'=>9]&&$deletion->latest($admin,1,$scope)===null,'Geri alma yalnızca silen kullanıcıya ait');
 denied(fn()=>$deletion->save($admin,1,$id,$scope,['version'=>9],true),403);
 $deletion->save($client,1,$id,$scope,['version'=>9],true);check($repo->listing(1,$scope)['total']===1&&$docs->load($admin,1,$id,'invoice',$scope)['bytes']!==null,'Geri alma kayıt ve belgeyi korur');
 $writer->undo($client,1,$scope,['id'=>$id,'version'=>10,'requestId'=>str_repeat('e',48)]);check($count()===8&&$last()['operation']==='undo','Son eklemeyi geri alma da bildirilir');
 // Event failure must roll back the shipment and generic audit as one transaction.
 $db->pdo->exec("CREATE TRIGGER fail_notice BEFORE INSERT ON audit WHEN NEW.action='shipment.client_change' BEGIN SELECT RAISE(ABORT,'test failure'); END");
 try{$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('f',48)]);throw new RuntimeException('Rollback bekleniyordu');}catch(PDOException){}
 $db->pdo->exec('DROP TRIGGER fail_notice');check($count()===8&&(int)$db->one('SELECT COUNT(*) AS n FROM shipment_entries')['n']===1,'Başarısız bildirim bütün işlemi geri almalı');
 $list=$feed->listing(1,1,0,'all','users');check($list['counts']['unread']===8&&!isset($list['items'][0]['changes']),'Zil sayısı doğru, kişisel eski/yeni değerler sadece detayda');
 $eventId=$list['items'][0]['id'];denied(fn()=>$events->detail(2,$eventId),404);
 $_GET=['company'=>1];denied(fn()=>(new NotificationRoutes($db,new Security($db,[]),$cipher))->handle('/api/notifications/shipment/'.$eventId,'GET',$client),403);
$state=new NotificationState($db);$state->read(1,1,'shipment',$eventId);check($feed->listing(1,1,0,'all','users')['counts']['unread']===7&&$feed->listing(4,1,0,'all','users')['counts']['unread']===8,'Okunma kullanıcıya özel');
denied(fn()=>$feed->listing(3,1,0),403);
 $batch=$state->bulk(1,1,$list['through'],true);check($feed->listing(1,1,0,'all','users')['counts']['all']===0,'Toplu silme sevkiyat bildirimlerini kapsar');$state->undo(1,1,$batch);check($feed->listing(1,1,0,'all','users')['counts']['all']===8,'Bildirim silmeyi geri alma');
 $raw=json_encode($db->all("SELECT after_value FROM audit WHERE action='shipment.client_change'"));check(!str_contains($raw,'05001112233')&&!str_contains($raw,'Ayşe'),'Bildirim içeriği diskte şifreli');
 $archive=BackupArchive::create($db,str_repeat('a',32));$snapshot=BackupArchive::read($archive,str_repeat('a',32));check(count($snapshot['tables']['audit']['rows'])===count($db->all('SELECT id FROM audit')),'Bildirim olayları yedeklenir');
 echo "Sevkiyat değişiklikleri: kişi/alan/önce/sonra, şifreleme, atomiklik, tekrar engeli, silme/geri alma, şirket ayrımı ve zil işlemleri geçti.\n";
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}

