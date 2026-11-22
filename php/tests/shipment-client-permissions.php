<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,NotificationSchema,SupportSchema,ShipmentEditSession,ShipmentRepository,ShipmentDocuments,ShipmentChangeNotifications,DeliveryCipher,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','x','admin',NULL),(2,'c@example.test','Müşteri','x','client',1)");
$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Müşteri','operatorName'=>'Başka Kişi'];
$admin=['id'=>1,'role'=>'admin'];$cipher=DeliveryCipher::withKey(str_repeat('p',32));
$session=new ShipmentEditSession($db,$cipher);$repo=new ShipmentRepository($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek','recipientName'=>'Alıcı','province'=>'Ankara','deliveryAddress'=>'Örnek adres','productName'=>'Ürün','quantity'=>'1','customerType'=>'Bireysel'];
$folder=sys_get_temp_dir().'/omnigo-client-permissions-'.bin2hex(random_bytes(6));
$docs=new ShipmentDocuments($db,$cipher,$folder);
$image=imagecreatetruecolor(12,12);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
try{
 foreach(['completed'] as $status){
  $session->save($client,1,$scope,['requestId'=>bin2hex(random_bytes(24)),'changes'=>[['type'=>'create','requestId'=>bin2hex(random_bytes(24)),'record'=>$draft]]]);
  $id=(int)$db->one('SELECT MAX(id) AS id FROM shipment_entries')['id'];
  $repo->toggle($client,1,$id,$scope,['field'=>'rowStatus','value'=>$status,'version'=>1]);
  $session->save($client,1,$scope,['requestId'=>bin2hex(random_bytes(24)),'changes'=>[['type'=>'update','id'=>$id,'version'=>2,'record'=>[...$draft,'district'=>'Çankaya']]]]);
  $invoice=$docs->save($client,1,$id,'invoice',$scope,3,bin2hex(random_bytes(24)),$bytes);
  $clientFile=$docs->load($client,1,$id,'invoice',$scope);
  check($clientFile['mime']==='image/jpeg'&&$clientFile['bytes']!==''&&$docs->load($admin,1,$id,'invoice',$scope)===$clientFile,'Müşteri ve yönetici aynı yüklenmiş faturayı okuyabilmeli.');
  try{$docs->save($client,1,$id,'delivery',$scope,$invoice['version'],bin2hex(random_bytes(24)),$bytes);throw new RuntimeException('Emekli teslimat belgesi kaydedildi.');}
  catch(HttpError $error){check($error->getCode()===410,'Emekli teslimat evrakı yükleme isteği 410 dönmeli.');}
  $row=$repo->listing(1,$scope)['items'][0];
  check($row['district']==='Çankaya'&&$row['rowStatus']===$status,'Sonlanmış kayıt düzenlenebilmeli ve durumu korunmalı.');
  $repo->toggle($client,1,$id,$scope,['field'=>'rowStatus','value'=>'shipped','version'=>$invoice['version']]);
  $repo->toggle($client,1,$id,$scope,['field'=>'rowStatus','value'=>$status,'version'=>$invoice['version']+1]);
  $session->save($client,1,$scope,['requestId'=>bin2hex(random_bytes(24)),'changes'=>[['type'=>'delete','id'=>$id,'version'=>$invoice['version']+2]]]);
  check($repo->listing(1,$scope)['total']===0,'Sonlanmış kayıt silinince iki panelin ortak listesinden çıkmalı.');
 }
 $events=$db->all("SELECT id FROM audit WHERE action='shipment.client_change'");
 check(count($events)===7,'Yedi başarılı işlem bildirim üretmeli, reddedilen belge yüklemesi bildirim oluşturmamalı.');
 foreach($events as $event){$detail=(new ShipmentChangeNotifications($db,$cipher))->detail(1,(int)$event['id']);check($detail['actorName']===$client['name'],'İşlemi yapan kişi her bildirimde korunmalı.');}
 $session->save($client,1,$scope,['requestId'=>bin2hex(random_bytes(24)),'changes'=>[['type'=>'create','requestId'=>bin2hex(random_bytes(24)),'record'=>$draft]]]);
 $id=(int)$db->one('SELECT MAX(id) AS id FROM shipment_entries')['id'];
 $storedInvoice=$docs->save($admin,1,$id,'invoice',$scope,1,bin2hex(random_bytes(24)),$bytes);
 $change=['field'=>'rowStatus','value'=>'cancelled','version'=>$storedInvoice['version']];
 try{$repo->toggle($client,1,$id,$scope,$change);throw new RuntimeException('Müşteri iptal durumunu değiştirdi.');}
 catch(HttpError $error){check($error->getCode()===403,'İptal durumunu yalnız yönetici değiştirebilir.');}
 $repo->toggle($admin,1,$id,$scope,$change);
 $customerRepo=new ShipmentRepository($db,$cipher,true);
 check($repo->listing(1,$scope)['total']===1&&$customerRepo->listing(1,$scope)['total']===0&&$customerRepo->export(1,$scope)['total']===0,'İptal kaydı yalnız yöneticinin listesinde ve raporunda kalmalı.');
 try{$docs->load($client,1,$id,'invoice',$scope);throw new RuntimeException('Müşteri iptal edilmiş siparişin belgesine erişti.');}
 catch(HttpError $error){check($error->getCode()===404,'İptal belgesi müşteri için bulunmamalı.');}
 check($docs->load($admin,1,$id,'invoice',$scope)['bytes']!=='','Yönetici mevcut iptal faturasını okuyabilmeli.');
 echo "Müşteri sevkiyat düzenleme ve belge akışı ile iptal görünürlüğü doğrulandı.\n";
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}
