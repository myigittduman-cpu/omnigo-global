<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentRepository,ShipmentWriter,ShipmentDocuments,ShipmentPdf,DeliveryCipher,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function denied(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('İşlem reddedilmedi.');}
function pdf():string{
 $bytes="%PDF-1.4\n";$offsets=[0];
 $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>'];
 foreach($objects as $i=>$body){$offsets[]=strlen($bytes);$bytes.=($i+1)." 0 obj\n$body\nendobj\n";}
 $start=strlen($bytes);$bytes.="xref\n0 5\n0000000000 65535 f \n";
 foreach(array_slice($offsets,1) as $offset)$bytes.=sprintf("%010d 00000 n \n",$offset);
 return $bytes."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
}
$bytes=pdf();ShipmentPdf::validate($bytes);
denied(fn()=>ShipmentPdf::validate(substr($bytes,0,-12)),415);
denied(fn()=>ShipmentPdf::validate('%PDF-1.4'.str_repeat(' ',ShipmentPdf::MAX_BYTES)),413);
denied(fn()=>ShipmentPdf::validate(str_replace('/Catalog','/JavaScript',$bytes)),415);
denied(fn()=>ShipmentPdf::validate(str_replace('/Catalog','/Java#53cript',$bytes)),415);
denied(fn()=>ShipmentPdf::validate("<html>".$bytes),415);
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);Omnigo\NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','x','admin',NULL),(2,'b@example.test','Müşteri','x','client',1),(3,'c@example.test','Diğer','x','client',2)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$other=['id'=>3,'role'=>'client','company_id'=>2];
$cipher=DeliveryCipher::withKey(str_repeat('p',32));$writer=new ShipmentWriter($db,$cipher);$repo=new ShipmentRepository($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek','recipientName'=>'Alıcı','phone'=>'05000000000','province'=>'Ankara','district'=>'Çankaya','deliveryAddress'=>'Örnek adres','productName'=>'Örnek ürün','invoiceAmount'=>'100,25','quantity'=>'1','invoiceName'=>'Örnek','invoiceAddress'=>'Fatura adresi','recipientEmail'=>'ornek@example.test','customerType'=>'Bireysel','taxNumber'=>''];
$id=$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)])['id'];
$storage=sys_get_temp_dir().'/omnigo-pdf-'.bin2hex(random_bytes(6));
mkdir($storage,0700);$folder=$storage.'/shipment-documents';$docs=new ShipmentDocuments($db,$cipher,$folder);
try{
 $result=$docs->save($client,1,$id,'invoice',$scope,1,str_repeat('b',48),$bytes);check($result['version']===2,'Sürüm artmalı');
 $loaded=$docs->load($admin,1,$id,'invoice',$scope);check($loaded['mime']==='application/pdf'&&base64_decode($loaded['bytes'])===$bytes,'Çok sayfalı PDF yöneticiye değişmeden ulaşmalı');
 $rows=$repo->listing(1,$scope)['items'];check($rows[0]['documents']['invoice']&&$rows[0]['documentTypes']['invoice']==='application/pdf','Belge biçimi doğru');
 check($rows[0]['displayRowStatus']==='invoiced','Gerçek fatura yüklemesi satırı sarıya geçirir');
 $docs->save($client,1,$id,'invoice',$scope,1,str_repeat('b',48),$bytes);check(count(glob($folder.'/*.bin'))===1,'Yeniden deneme mükerrer dosya yaratmamalı');
 denied(fn()=>$docs->load($other,1,$id,'invoice',$scope),403);
 denied(fn()=>$docs->save($admin,1,$id,'invoice',$scope,1,str_repeat('c',48),$bytes),409);
 $stored=file_get_contents(glob($folder.'/*.bin')[0]);check(!str_contains($stored,'%PDF-')&&!str_contains($stored,base64_encode($bytes)),'Dosya şifreli olmalı');
 // Seed an attachment produced by the retired workflow, encrypted exactly as stored in production.
 $key=bin2hex(random_bytes(24));
 $row=$db->one('SELECT payload FROM shipment_entries WHERE id=?',[$id]);
 $payload=$cipher->open(1,'shipment:'.$id,$row['payload']);
 $payload['documents']['delivery']=['key'=>$key,'mime'=>'application/pdf','width'=>0,'height'=>0];
 file_put_contents($folder.'/'.$key.'.bin',$cipher->seal(1,'shipment-document:'.$id.':delivery:'.$key,['bytes'=>base64_encode($bytes)]));
 $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$payload),$id]);
 $revision=Omnigo\ShipmentRevision::read($db,1);
 $legacy=['workspacePolicy'=>['users'=>[2=>['shipmentDeliveryDocuments'=>['view'=>true,'documents'=>true]]]]];
 $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode($legacy,JSON_THROW_ON_ERROR)]);
 check($docs->load($admin,1,$id,'delivery',$scope)['mime']==='application/pdf','Yönetici arşiv belgesini okuyabilmeli.');
 check(base64_decode($docs->load($client,1,$id,'delivery',$scope)['bytes'])===$bytes,'Mevcut belge okuma yetkisi korunmalı.');
 denied(fn()=>$docs->load($other,1,$id,'delivery',$scope),403);
 denied(fn()=>$docs->save($admin,1,$id,'delivery',$scope,2,str_repeat('d',48),$bytes),410);
 denied(fn()=>$docs->removeDelivery($admin,1,$id,$scope,2),410);
 check(is_file($folder.'/'.$key.'.bin')&&Omnigo\ShipmentRevision::read($db,1)===$revision,'Kaldırılan iş akışı eski dosya ve kaydını değiştirmemeli.');
 Omnigo\ArchivedShipmentDelivery::snapshot($db,1,$legacy);
 $legacy['workspacePolicy']=['users'=>[2=>['cityProducts'=>['view'=>false]]]];
 $db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($legacy,JSON_THROW_ON_ERROR)]);
 check(base64_decode($docs->load($client,1,$id,'delivery',$scope)['bytes'])===$bytes,'Ayarlar düzenlenince eski tekil belge okunabilmeli.');
 denied(fn()=>$docs->load(['id'=>5,'role'=>'client','company_id'=>1],1,$id,'delivery',$scope),403);
 // The original objects keep their offsets. Place a comment before xref to reach the exact limit.
 $xref=strpos($bytes,"xref\n");
 $trailer=substr($bytes,$xref);
 $limit=ShipmentPdf::INVOICE_MAX_BYTES;
 $commentLength=$limit-strlen($bytes)-(strlen((string)$limit)-strlen((string)$xref));
 $offset=$xref+$commentLength;
 $large=substr($bytes,0,$xref).'%'.str_repeat('A',$commentLength-2)."\n".
    str_replace("startxref\n$xref\n","startxref\n$offset\n",$trailer);
 check(strlen($large)===$limit,'20 MB örnek dosya sınırda olmalı');
 denied(fn()=>ShipmentPdf::validate($large),413);
 denied(fn()=>$docs->save($admin,1,$id,'invoice',$scope,2,str_repeat('e',48),$large.' '),413);
 $result=$docs->save($admin,1,$id,'invoice',$scope,2,str_repeat('e',48),$large);
 check($result['version']===3,'20 MB fatura kaydedilebilmeli');
 $loaded=$docs->load($admin,1,$id,'invoice',$scope);
 check($loaded['mime']==='application/pdf'&&base64_decode($loaded['bytes'])===$large,'20 MB PDF tüm sayfalarıyla değişmeden indirilebilmeli');
 check(count(glob($folder.'/*.bin'))===2,'Fatura yenilenince yalnız yeni fatura ve tarihsel teslimat belgesi kalmalı.');
 $documentRow=$db->one('SELECT payload FROM shipment_entries WHERE id=?',[$id]);
 $documentPayload=$cipher->open(1,'shipment:'.$id,$documentRow['payload']);
 $currentKey=$documentPayload['documents']['invoice']['key'];
 $db->pdo->exec("CREATE TRIGGER reject_document_audit BEFORE INSERT ON audit WHEN NEW.action='shipment.document' BEGIN SELECT RAISE(ABORT,'test-document-rollback'); END");
 try{$docs->save($admin,1,$id,'invoice',$scope,3,str_repeat('f',48),$bytes);throw new RuntimeException('İşlem hatası bekleniyordu.');}catch(PDOException){}
 $db->pdo->exec('DROP TRIGGER reject_document_audit');
 check(is_file($folder.'/'.$currentKey.'.bin')&&count(glob($folder.'/*.bin'))===2,'Kayıt başarısızsa eski belge korunmalı, yarım dosya kaldırılmalı.');
 check(base64_decode($docs->load($admin,1,$id,'invoice',$scope)['bytes'])===$large,'Geri alınan işlem eski faturanın indirilmesini bozmamalı.');
 $docs->save($admin,1,$id,'invoice',$scope,2,str_repeat('e',48),$large);
 check(count(glob($folder.'/*.bin'))===2,'Aynı isteğin tekrarı güncel faturayı silmemeli veya yeni dosya üretmemeli.');
 $otherId=$writer->create($admin,2,$scope,['record'=>$draft,'requestId'=>str_repeat('1',48)])['id'];
 $docs->save($admin,2,$otherId,'invoice',$scope,1,str_repeat('2',48),$bytes);
 $docs->save($admin,1,$id,'invoice',$scope,3,str_repeat('f',48),$bytes);
 check(!is_file($folder.'/'.$currentKey.'.bin')&&count(glob($folder.'/*.bin'))===3,'Başarılı yenileme yalnız kendi önceki faturasını kaldırmalı.');
 check(base64_decode($docs->load($admin,2,$otherId,'invoice',$scope)['bytes'])===$bytes&&is_file($folder.'/'.$key.'.bin'),'Diğer mağazanın faturası ve arşiv teslimat belgesi korunmalı.');

}finally{
 foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);
 if(is_dir($folder))rmdir($folder);
 if(is_file($storage.'/backup.lock'))unlink($storage.'/backup.lock');
 rmdir($storage);
}
echo "PDF yükleme: çok sayfa, aynı dosya, iki yönlü erişim, firma ayrımı, şifreleme, tekrar/çakışma, etkin içerik, bozuk dosya ve tarihsel belge erişimi kontrolleri geçti.\n";
