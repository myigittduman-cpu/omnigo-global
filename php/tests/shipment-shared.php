<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,Security,ShipmentSchema,ShipmentRepository,ShipmentWriter,ShipmentDocuments,ShipmentBulkStatus,ShipmentRevision,ShipmentRoutes,DeliveryCipher,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $status=403):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata: '.$error->getMessage());return;}throw new RuntimeException('İşlem engellenmeliydi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);\Omnigo\NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','x','admin',NULL),(2,'b@example.test','Müşteri','x','client',1),(3,'c@example.test','Diğer','x','client',2)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$other=['id'=>3,'role'=>'client','company_id'=>2];
$cipher=DeliveryCipher::withKey(str_repeat('s',32));$repo=new ShipmentRepository($db,$cipher);$writer=new ShipmentWriter($db,$cipher);$bulk=new ShipmentBulkStatus($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek Müşteri','recipientName'=>'Örnek Alıcı','phone'=>'05000000000','province'=>'Ankara','district'=>'Çankaya','deliveryAddress'=>'Örnek adres','productName'=>'Örnek ürün','invoiceAmount'=>'100,25','quantity'=>'1','invoiceName'=>'Örnek Firma','invoiceAddress'=>'Fatura adresi','recipientEmail'=>'ornek@example.test','customerType'=>'Bireysel','taxNumber'=>''];
$initial=ShipmentRevision::read($db,1);
$created=$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)]);$id=$created['id'];
check(ShipmentRevision::read($db,1)!==$initial&&ShipmentRevision::read($db,2)==='0','Değişiklik işareti firma bazında olmalı.');
check($repo->listing(1,$scope)['items'][0]['customerName']===$draft['customerName'],'Müşterinin eklediği ortak kayıtta görünmeli.');
check($writer->undoState($admin,1,$scope)===null,'Yönetici müşterinin eklemesini geri alamamalı.');
$writer->update($admin,1,$id,$scope,['version'=>1,'record'=>[...$draft,'customerName'=>'Yönetici düzenledi']]);
check($repo->listing(1,$scope)['items'][0]['customerName']==='Yönetici düzenledi','Yönetici düzenlemesi ortak kayıtta görünmeli.');
denied(fn()=>$writer->update($client,1,$id,$scope,['version'=>1,'record'=>$draft]),409);
$repo->toggle($client,1,$id,$scope,['field'=>'rowStatus','value'=>'shipped','version'=>2]);
$bulk->save($client,1,$scope,['items'=>[['id'=>$id,'version'=>3]],'value'=>'completed']);
check($repo->export(1,$scope)['items'][0]['rowStatus']==='completed','Müşterinin durum değişikliği dışa aktarmada da ortak olmalı.');
$folder=sys_get_temp_dir().'/omnigo-shared-'.bin2hex(random_bytes(6));$docs=new ShipmentDocuments($db,$cipher,$folder);
foreach([
 fn()=>$writer->create($other,1,$scope,['record'=>$draft,'requestId'=>str_repeat('b',48)]),
 fn()=>$writer->update($other,1,$id,$scope,['record'=>$draft,'version'=>4]),
 fn()=>$writer->undoState($other,1,$scope),
 fn()=>$writer->undo($other,1,$scope,['id'=>$id,'version'=>4,'requestId'=>str_repeat('c',48)]),
 fn()=>$repo->toggle($other,1,$id,$scope,['field'=>'rowStatus','value'=>'','version'=>4]),
 fn()=>$bulk->save($other,1,$scope,['items'=>[['id'=>$id,'version'=>4]],'value'=>'']),
 fn()=>$docs->load($other,1,$id,'invoice',$scope),
 fn()=>$docs->save($other,1,$id,'invoice',$scope,4,str_repeat('d',48),'invalid')
] as $action)denied($action);
$routes=new ShipmentRoutes($db,new Security($db,[]));$_GET=['company'=>'1','city'=>'all','provider'=>'n11','month'=>'2026-10'];
foreach(['','/search','/export','/revision','/'.$id.'/documents/invoice'] as $suffix)denied(fn()=>$routes->handle('/api/shipments'.$suffix,'GET',$other));
denied(fn()=>$routes->handle('/api/admin/shipments','GET',$client));
$image=imagecreatetruecolor(12,12);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
try{
 $docs->save($client,1,$id,'invoice',$scope,4,str_repeat('d',48),$bytes);
 check($docs->load($admin,1,$id,'invoice',$scope)['bytes']!==null,'Müşterinin yüklediği belge yöneticide görünmeli.');
 denied(fn()=>$docs->save($admin,1,$id,'delivery',$scope,5,str_repeat('e',48),$bytes),410);
 denied(fn()=>$docs->load($admin,1,$id,'delivery',$scope),404);
 denied(fn()=>$docs->load($client,1,$id,'delivery',$scope),403);
 $adminImage=imagecreatetruecolor(24,16);ob_start();imagejpeg($adminImage);$replacement=ob_get_clean();imagedestroy($adminImage);
 $stored=$docs->load($client,1,$id,'invoice',$scope);
 $docs->save($admin,1,$id,'invoice',$scope,5,str_repeat('e',48),$replacement);
 $current=$docs->load($client,1,$id,'invoice',$scope);
 check($current['bytes']!==$stored['bytes']&&$current['bytes']===$docs->load($admin,1,$id,'invoice',$scope)['bytes'],'Yöneticinin değiştirdiği fatura müşteride ve yöneticide aynı görünmeli.');
 $revision=ShipmentRevision::read($db,1);
 $writer->undo($client,1,$scope,['id'=>$id,'version'=>6,'requestId'=>str_repeat('f',48)]);
 check($repo->listing(1,$scope)['total']===0&&ShipmentRevision::read($db,1)!==$revision,'Geri alma iki panelde de eşitlenmeli.');
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}
echo "Ortak sevkiyat: iki yönlü kayıt/belge, firma ayrımı, eski yönetici yolu, sürüm çakışması, geri alma ve değişiklik takibi geçti.\n";
