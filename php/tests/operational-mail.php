<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliveryMapper,ShipmentOrderMapper,ShipmentOrderIngestor,OperationalMailEvents,MailQueue,ShipmentWriter,ShipmentDocuments};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Firma')");$db->run("INSERT INTO users VALUES(1,'admin@omnigoglobal.com','Yönetici','x','admin',NULL),(2,'client@example.test','Ayşe Örnek','x','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('m',32));$repo=new DeliveryRepository($db,$cipher);$events=new OperationalMailEvents($db,$cipher);
$config=['mail'=>['enabled'=>true,'from'=>'no-reply@example.test','transport'=>'mail','key'=>base64_encode(str_repeat('q',32))]];$mail=new MailQueue($db,$config);
ShipmentOrderIngestor::activate($db,1,time()-3600);
$db->run('INSERT INTO order_notification_sources VALUES(?,?,?)',[1,'n11',time()-3600]);
$raw=['orderNumber'=>'MAIL-1','id'=>1,'customerfullName'=>'Örnek','shippingAddress'=>['fullName'=>'Alıcı','gsm'=>'05000000001','address'=>'Teslimat','city'=>'Ankara','district'=>'Çankaya'],'packageHistories'=>[['status'=>'Created','createdDate'=>(time()-60)*1000]],'shipmentPackageStatus'=>'Picking','lines'=>[['orderLineId'=>'L1','orderItemLineItemStatusName'=>'Picking','productName'=>'Ürün','quantity'=>2,'sellerInvoiceAmount'=>'100.00']]];
$map=static function(array $raw):array{$value=DeliveryMapper::n11($raw);return [...$value,'shipmentItems'=>ShipmentOrderMapper::n11($raw,$value)];};
$save=fn($value)=>$db->transaction(fn()=>$repo->ingest(1,$value));$count=fn()=>(int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='operations.mail'")['n'];
$save($map($raw));check($count()===1,'Yeni sipariş ve iki aktarım bir bilgilendirmede');$save($map($raw));check($count()===1,'Tekrar okuma e-posta çoğaltmaz');
check($events->enqueue(new MailQueue($db,['mail'=>['enabled'=>false]]))===0&&$count()===1,'Posta kapalıyken olay kaybolmaz');
check($events->enqueue($mail)===1&&$events->enqueue($mail)===0,'Yeniden kuyruğa alma çoğaltmaz');
$sent=[];$transport=function($to,$subject,$body)use(&$sent){$sent[]=[$to,$subject,$body];return true;};
$mail->runOne($transport);check(str_contains($sent[0][2],'Yeni sipariş geldi')&&str_contains($sent[0][2],'Örnek siparişi Şehir Dışı Teslimatlar listesine eklendi')&&str_contains($sent[0][2],'Şehir Dışı Sevkiyat'),'Üç ekranın tamamlanan aktarımı ve alıcı adı açıklanır');
$cancel=$raw;$cancel['shipmentPackageStatus']='Cancelled';$save($map($cancel));$save($map($cancel));check($count()===2,'İptal bir kez bildirilir');check($repo->listing(1,'Ankara','n11','all',0)['items']===[],'İptal teslimattan çıkar');
$events->enqueue($mail);$mail->runOne(fn()=>false);check($db->one("SELECT state FROM mail_outbox WHERE state='pending'")!==null,'Geçici SMTP hatası tekrar denenir');$db->run("UPDATE mail_outbox SET next_run=0 WHERE state='pending'");$mail->runOne($transport);check(str_contains($sent[1][1],'Örnek siparişi iptal edildi'),'İptal e-posta başlığı alıcı adını içerir');
$old=$raw;$old['id']=2;$old['orderNumber']='OLD';$old['packageHistories'][0]['createdDate']=(time()-86400)*1000;$save($map($old));check($count()===2,'Tarihsel ilk aktarım bildirim yağmuru oluşturmaz');
$cancelled=$db->one('SELECT id,version,month FROM shipment_entries WHERE company_id=1 AND cancelled=1');
$invoiceOrder=$raw;$invoiceOrder['id']=3;$invoiceOrder['orderNumber']='MAIL-INVOICE';$invoiceOrder['lines'][0]['orderLineId']='INVOICE-LINE';
$items=$map($invoiceOrder)['shipmentItems'];
$db->transaction(fn()=>(new ShipmentOrderIngestor($db,$cipher))->ingest(1,'n11',$items));
$row=$db->one('SELECT id,version,month FROM shipment_entries WHERE company_id=? AND source_key=? AND cancelled=0',[1,hash('sha256','n11:'.$items[0]['key'])]);
check($row!==null&&$cancelled!==null,'Fatura için etkin sipariş ve reddetme kontrolü için iptal satırı bulunmalı.');
$folder=sys_get_temp_dir().'/operation-mail-'.bin2hex(random_bytes(6));$docs=new ShipmentDocuments($db,$cipher,$folder);$scope=['all','n11',$row['month']];
$image=imagecreatetruecolor(12,12);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
try{
 $client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Ayşe Örnek'];$admin=['id'=>1,'role'=>'admin'];
 try{$docs->save($client,1,(int)$cancelled['id'],'invoice',['all','n11',$cancelled['month']],(int)$cancelled['version'],str_repeat('d',48),$bytes);throw new RuntimeException('İptal satırına fatura yüklenebildi.');}
 catch(\Omnigo\HttpError $error){check($error->getCode()===404,'İptal sıra kaydı erişime kapalı olmalı.');}
 $docs->save($client,1,(int)$row['id'],'invoice',$scope,(int)$row['version'],str_repeat('a',48),$bytes);$docs->save($client,1,(int)$row['id'],'invoice',$scope,(int)$row['version'],str_repeat('a',48),$bytes);
 check($count()===3,'Müşteri faturası bir kez bildirilir');
 foreach([$client,$admin] as $actor){
  try{$docs->save($actor,1,(int)$row['id'],'delivery',$scope,(int)$row['version']+1,bin2hex(random_bytes(24)),$bytes);throw new RuntimeException('Kaldırılan teslimat belgesi yükleme işlemi kabul edildi.');}
  catch(\Omnigo\HttpError $error){check($error->getCode()===410,'Teslimat belgesi yükleme her iki rol için de kapatılmış olmalı.');}
  check($count()===3,'Reddedilen belge yüklemesi e-posta olayı oluşturmamalı.');
 }
 $events->enqueue($mail);while($mail->runOne($transport)){}
 check(count($sent)===3&&str_contains($sent[2][2],'Ayşe Örnek')&&str_contains($sent[2][2],'Fatura'),'Yalnızca kaydedilen faturanın türü ve yükleyen kişi bildirilir.');
 foreach($sent as [$to,$subject,$body])check($to==='admin@omnigoglobal.com'&&str_contains($body,'Türkiye saati'),'Doğru alıcı ve saat');
 check($events->enqueue($mail)===0,'Gönderilmiş olay yeniden gönderilmez');
 $text=json_encode($db->all("SELECT after_value FROM audit WHERE action='operations.mail'"));check(!str_contains($text,'Ayşe')&&!str_contains($text,'MAIL-1'),'Diskte olay bilgileri şifreli');
 try{$db->transaction(function()use($events){$events->record(1,1,['events'=>['new_order']]);throw new RuntimeException('rollback');});}catch(RuntimeException){}check($count()===3,'Geri alınan işlem e-posta oluşturmaz');
 $orderId=(int)$db->one('SELECT id FROM delivery_orders WHERE company_id=1 ORDER BY id LIMIT 1')['id'];
 $db->transaction(fn()=>$events->record(1,$orderId,['events'=>['delivery_added'],'provider'=>'n11']));
 $cancelId=(int)$db->one("SELECT id FROM order_notifications WHERE event_type='operation' ORDER BY id LIMIT 1")['id'];
 $cancelNotice=new \Omnigo\OperationalNotifications($db,$cipher);
 check($cancelNotice->summary(1,$cancelId)['buyerName']==='Örnek','İptal bildirimi alıcı adını içerir');
 $noticeId=(int)$db->one("SELECT MAX(id) id FROM order_notifications WHERE event_type='operation'")['id'];
 $notice=new \Omnigo\OperationalNotifications($db,$cipher);
 check($notice->summary(1,$noticeId)['buyerName']==='Örnek','Zilde alıcı adı görünür');
 check(!str_contains($db->one('SELECT payload FROM order_notifications WHERE id=?',[$noticeId])['payload'],'Örnek'),'Açık bildirim zarfına alıcı adı yazılmaz');
 $auditId=$notice->summary(1,$noticeId)['auditId'];
 $db->run('UPDATE audit SET after_value=? WHERE id=?',[$cipher->seal(1,'operation-mail:'.$auditId,['events'=>['delivery_added'],'provider'=>'n11']),$auditId]);
 check($notice->summary(1,$noticeId)['buyerName']==='Örnek','Eski bildirim adı şirket kapsamındaki siparişten tamamlanır');
 try{$notice->summary(2,$noticeId);throw new RuntimeException('Şirket sınırı');}catch(\Omnigo\HttpError $e){check($e->getCode()===404,'Başka şirkete alıcı adı sızmaz');}
 echo "Operasyon e-postaları: yeni sipariş, iki aktarım, iptal, müşteri belgeleri, tekrar engeli, şifreleme, rollback ve yeniden deneme geçti.\n";
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}
