<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentRepository,ShipmentWriter,ShipmentDraft,ShipmentDocuments,DeliveryCipher,HttpError,BackupArchive,NotificationSchema,SupportSchema};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function fails(callable $fn,int $code):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$code,'HTTP '.$code.' bekleniyordu: '.$error->getMessage());return;}throw new RuntimeException('Hata bekleniyordu.');}
function db():Database{$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);return $db;}
$db=db();$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Bir','x','admin',NULL),(2,'b@test.invalid','İki','x','admin',NULL)");
$key=str_repeat('s',32);$cipher=DeliveryCipher::withKey($key);$writer=new ShipmentWriter($db,$cipher);$repo=new ShipmentRepository($db,$cipher);$admin=['id'=>1,'role'=>'admin'];$other=['id'=>2,'role'=>'admin'];$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-10T13:14:15','customerName'=>'Örnek Müşteri','recipientName'=>'Başka Alıcı','phone'=>'05000000000','province'=>'Ankara','district'=>'Çankaya','deliveryAddress'=>'Örnek teslimat adresi','productName'=>'Örnek Ürün','invoiceAmount'=>'1234,56','quantity'=>'2','invoiceName'=>'Örnek Firma','invoiceAddress'=>'Başka fatura adresi\nV.D.: Örnek','recipientEmail'=>'ornek@test.invalid','customerType'=>'Kurumsal','taxNumber'=>'0012345678'];
$data=['record'=>(object)$draft,'requestId'=>str_repeat('a',48)];$first=$writer->create($admin,1,$scope,$data);check($writer->create($admin,1,$scope,$data)['id']===$first['id'],'Tekrarlanan istek tek kayıt');
fails(fn()=>$writer->create($admin,1,$scope,[...$data,'record'=>[...$draft,'customerName'=>'Değişik']]),409);
fails(fn()=>$writer->create(['id'=>1,'role'=>'client'],1,$scope,$data),403);
foreach([['orderedAt'=>'2026-09-10T13:00'],['orderedAt'=>'2026-10-32T13:00'],['invoiceAmount'=>'1.234,56'],['quantity'=>'0'],['recipientEmail'=>'bozuk']] as $bad)fails(fn()=>ShipmentDraft::parse([...$draft,...$bad],'2026-10'),400);
$second=$writer->create($admin,1,$scope,['record'=>[...$draft,'orderedAt'=>'2026-10-11T10:00'],'requestId'=>str_repeat('b',48)]);
$third=$writer->create($other,1,$scope,['record'=>$draft,'requestId'=>str_repeat('c',48)]);
$db->run('UPDATE shipment_entries SET manual_user_id=NULL WHERE id=?',[$third['id']]);
check($repo->listing(1,$scope)['items'][0]['id']===$second['id'],'Tarih azalan sıralama');
check($writer->undoState($admin,1,$scope)['id']===$second['id'],'Yalnızca kendi elle eklediği son kayıt');
fails(fn()=>$writer->update($admin,2,$first['id'],$scope,['record'=>$draft,'version'=>1]),404);
$repo->toggle($admin,1,$first['id'],$scope,['field'=>'rowStatus','value'=>'invoiced','version'=>1]);
check($repo->listing(1,$scope,0,'',['invoiced'=>'yes'])['total']===1,'Kırmızı durum fatura filtresine dahil');
fails(fn()=>$writer->update($admin,1,$first['id'],$scope,['record'=>$draft,'version'=>1]),409);
$writer->update($admin,1,$first['id'],$scope,['record'=>[...$draft,'customerName'=>'Düzenlenen'],'version'=>2]);
check($repo->export(1,$scope)['items'][2]['rowStatus']==='invoiced','Düzenleme rengi korur');
$undo=['requestId'=>str_repeat('d',48),...$writer->undoState($admin,1,$scope)];$writer->undo($admin,1,$scope,$undo);$writer->undo($admin,1,$scope,$undo);
check($repo->listing(1,$scope)['total']===2,'Geri alma tekrarı başka kayıt silmez');
fails(fn()=>$repo->toggle($admin,1,$second['id'],$scope,['field'=>'rowStatus','value'=>'completed','version'=>1]),404);
check($repo->listing(1,$scope,0,'Örnek')['total']===2&&$repo->export(1,$scope)['total']===2,'Silinenler arama/dışa aktarmada yok');
$writer->undo($admin,1,$scope,['requestId'=>str_repeat('e',48),...$writer->undoState($admin,1,$scope)]);
check($writer->undoState($admin,1,$scope)===null&&$repo->listing(1,$scope)['total']===1,'İçe aktarılmış kayıt geri alınmaz');
check(!str_contains(json_encode($db->all('SELECT * FROM audit')),'Örnek Müşteri'),'Denetim izi kişisel veri içermez');
check(!str_contains($db->one('SELECT payload FROM shipment_entries')['payload'],'Örnek'),'Kayıt şifreli');
$target=db();BackupArchive::restore($target,BackupArchive::create($db,$key),$key);check($target->all('SELECT * FROM shipment_entries')===$db->all('SELECT * FROM shipment_entries'),'Yedek geri alma bilgilerini korur');
$folder=sys_get_temp_dir().'/omnigo-shipment-doc-'.bin2hex(random_bytes(6));$docs=new ShipmentDocuments($db,$cipher,$folder);
$image=imagecreatetruecolor(120,80);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
try{
 $id=$third['id'];$request=str_repeat('f',48);$docs->save($admin,1,$id,'invoice',$scope,1,$request,$bytes);
 check($docs->save($admin,1,$id,'invoice',$scope,1,$request,$bytes)['version']===2,'Belge yükleme tekrarı tek sürüm');
 check($repo->listing(1,$scope)['items'][0]['documents']['invoice'],'Yüklenen belge listede görünür');
 check(str_starts_with(base64_decode($docs->load($admin,1,$id,'invoice',$scope)['bytes']),"\xff\xd8\xff"),'JPEG indirilebilir');
 fails(fn()=>$docs->load($admin,2,$id,'invoice',$scope),404);
 fails(fn()=>$docs->save($admin,1,$id,'invoice',$scope,2,str_repeat('g',48),$bytes),400);
 fails(fn()=>$docs->save($admin,1,$id,'invoice',$scope,1,str_repeat('1',48),$bytes),409);
 fails(fn()=>$docs->save($admin,1,$id,'invoice',$scope,2,str_repeat('1',48),'not jpeg'),415);
 fails(fn()=>$docs->save($admin,1,$id,'delivery',$scope,2,str_repeat('2',48),$bytes),410);
 check(!str_contains(file_get_contents(glob($folder.'/*.bin')[0]),base64_encode($bytes)),'Belge şifreli');
}finally{foreach(glob($folder.'/*.bin')?:[] as $path)unlink($path);if(is_dir($folder))rmdir($folder);}
echo "Düzenleme, yeni kayıt, geri alma, yetki, çakışma, şifreli belgeler ve yedek testleri geçti.\n";
