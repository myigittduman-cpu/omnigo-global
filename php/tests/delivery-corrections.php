<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryMapper,DeliveryRepository,DeliveryCorrectionService,DeliveryCorrectionFields,ShipmentOrderIngestor,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $code):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$code,$error->getMessage());return;}throw new RuntimeException('İşlem engellenmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$db->run("INSERT INTO users(id,email,name,password,role) VALUES(1,'admin@test.invalid','Yönetici','unused','admin')");
$cipher=DeliveryCipher::withKey(str_repeat('c',32));$repo=new DeliveryRepository($db,$cipher);$service=new DeliveryCorrectionService($db,$cipher);$admin=['id'=>1,'role'=>'admin'];
ShipmentOrderIngestor::activate($db,1,time()-86400);
$date=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->modify('-20 days')->format('c');
function sample(string $provider,string $number,string $date,string $city='Ankara',string $phone=''):array{
 $identity=$provider==='Pazarama'?$number.':package':$number;$state=match($provider){'n11'=>'Shipped','Pazarama'=>'5','Koçtaş'=>'SHIPPED',default=>'shipped'};
 $d=['provider'=>$provider,'externalId'=>$number.':package','orderNumber'=>$number,'buyerName'=>'Örnek Müşteri','phone'=>$phone,'address'=>'Örnek teslimat adresi','city'=>DeliveryMapper::city($city),'district'=>'Merkez','createdAt'=>$date,'status'=>'Kargolandı','shippingState'=>'shipped','active'=>true,'products'=>[['title'=>'Örnek ürün','sku'=>'sku','quantity'=>1]],'cancellation'=>['quantity'=>1,'cancelledQuantity'=>0,'partial'=>false]];
 $d['details']=['orderedAt'=>$date,'province'=>$city,'customerName'=>$d['buyerName'],'recipientName'=>'Örnek Alıcı','phone'=>$phone,'deliveryAddress'=>$d['address'],'products'=>[['lineId'=>'42','title'=>'Örnek ürün','sku'=>'sku','quantity'=>1,'amountCents'=>12345,'cancelledQuantity'=>0,'status'=>$state,'shippingState'=>'shipped']]];
 if($provider==='Pazarama')$d['details']['products'][0]['invoiceCents']=12345;
 $d['shipmentItems']=[['key'=>hash('sha256',json_encode([$identity,'42'],JSON_THROW_ON_ERROR)),'payload'=>['orderedAt'=>$date,'customerName'=>$d['buyerName'],'recipientName'=>'Örnek Alıcı','phone'=>$phone,'deliveryAddress'=>$d['address'],'province'=>$d['city'],'district'=>'Merkez','productName'=>'Örnek ürün','quantity'=>1,'invoiceCents'=>12345,'marketplaceShipping'=>'shipped','marketplaceCancellation'=>$d['cancellation']]]];return DeliveryMapper::finish($d);
}
$save=fn($d)=>$db->transaction(fn()=>$repo->ingest(1,$d,false));
foreach(['n11','Pazarama','Koçtaş','idefix','Hepsiburada'] as $index=>$provider){
 $d=sample($provider,'ORD-'.$index,$date);$save($d);
 $row=$db->one('SELECT * FROM delivery_orders WHERE company_id=1 AND provider=?',[$provider]);$id=(int)$row['id'];
 check($row['city']==='Diğer / Kontrol',$provider.' eksik telefon kontrol listesinde');check(!$db->one('SELECT id FROM shipment_entries WHERE provider=?',[$provider]),'Eksik yeni satır oluşturulmaz');
 $form=$service->read($admin,1,$id);check(array_column($form['fields'],null,'key')['phone']['error'],'Eksik alan açıkça belirtilir');
 denied(fn()=>$service->save(['id'=>2,'role'=>'client','company_id'=>1],1,$id,['version'=>1,'fields'=>['phone'=>'05001112233']]),403);
 denied(fn()=>$service->save($admin,2,$id,['version'=>1,'fields'=>['phone'=>'05001112233']]),404);
 denied(fn()=>$service->save($admin,1,$id,['version'=>99,'fields'=>['phone'=>'05001112233']]),409);
 denied(fn()=>$service->save($admin,1,$id,['version'=>1,'fields'=>['active'=>'true']]),400);
 denied(fn()=>$service->save($admin,1,$id,['version'=>1,'fields'=>['phone'=>'xxx']]),422);
 $result=$service->save($admin,1,$id,['version'=>$form['version'],'fields'=>['phone'=>'05001112233']]);check($result['ready'],$provider.' tamamlandı');
 $row=$db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]);$order=$repo->decode($row);check($row['city']==='Ankara'&&!$order['issues'],'Doğru teslimat tablosuna taşındı');
 $ship=$db->one('SELECT * FROM shipment_entries WHERE provider=?',[$provider]);check($ship!==null&&Omnigo\ShipmentStatus::resolve($ship)==='shipped',$provider.' geçmiş sipariş ve statü sevkiyata geçti');
 $payload=$cipher->open(1,'shipment:'.$ship['id'],$ship['payload']);check($payload['phone']==='05001112233'&&$payload['invoiceCents']===12345,'Sevkiyat alanları ve fiyat korundu');
 $save($d);$stable=$db->one('SELECT version FROM delivery_orders WHERE id=?',[$id]);$save($d);check($stable===$db->one('SELECT version FROM delivery_orders WHERE id=?',[$id]),'Tekrar kaynak okuması sürüm şişirmez');
 check($repo->decode($db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]))['phone']==='05001112233','Kaynak boş telefon düzeltmeyi silmez');
 check((int)$db->one('SELECT COUNT(*) n FROM shipment_entries WHERE provider=?',[$provider])['n']===1,'Tekrarda mükerrer yok');
 denied(fn()=>$service->save($admin,1,$id,['version'=>1,'fields'=>['phone'=>'05001112233']]),409);
 $cancel=$d;$cancel['active']=false;$cancel['products']=[];$cancel['cancellation']=['quantity'=>1,'cancelledQuantity'=>1,'partial'=>false];$cancel['details']['products'][0]['cancelledQuantity']=1;$cancel['details']['products'][0]['shippingState']='cancelled';$cancel['shipmentItems'][0]['payload']['marketplaceCancellation']=$cancel['cancellation'];$cancel['shipmentItems'][0]['payload']['marketplaceShipping']='cancelled';
 $save($cancel);$afterCancel=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$ship['id']]);$afterPayload=$cipher->open(1,'shipment:'.$ship['id'],$afterCancel['payload']);check(Omnigo\ShipmentStatus::resolve($afterCancel)==='cancelled'&&$afterPayload['marketplaceCancellation']['cancelledQuantity']===1&&!$repo->operational($db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]))['active'],'Elle telefon doldurma gerçek durumu korur; kaynak iptali evraktan çıkarır');
 $latest=$repo->decode($db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]));denied(fn()=>$service->save($admin,1,$id,['version'=>$latest['version'],'fields'=>['phone'=>'05002223344']]),409);
}
$outside=sample('n11','OUTSIDE',$date,'Yurt dışı','05001112233');$save($outside);$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='OUTSIDE:package'");$id=(int)$row['id'];
$form=$service->read($admin,1,$id);check(str_contains(array_column($form['fields'],null,'key')['city']['message'],'Kapsam dışı'),'Gerçek kapsam dışı il bilgisi eksik denmez');
$result=$service->save($admin,1,$id,['version'=>1,'fields'=>['district'=>'Yeni ilçe']]);check(!$result['ready'],'Kapsam dışındaki doğru il otomatik yanlış şehre taşınmaz');
$row=$db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]);$result=$service->save($admin,1,$id,['version'=>(int)$row['version'],'fields'=>['city'=>'Kayseri','address'=>'Doğrulanmış Kayseri adresi']]);check($result['ready']&&$result['city']==='Kayseri','Doğrulanmış il düzeltmesi doğru tabloya aktarır');
$save($outside);check($db->one('SELECT city FROM delivery_orders WHERE id=?',[$id])['city']==='Kayseri','İl düzeltmesi tekrarda korunur');
$before=$db->all('SELECT * FROM shipment_entries ORDER BY id');$row=$db->one('SELECT * FROM delivery_orders WHERE id=?',[$id]);denied(fn()=>$service->save($admin,1,$id,['version'=>(int)$row['version'],'fields'=>['orderNumber'=>'CHANGE-ID']]),400);check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Kimlik değiştirme ve veri sızıntısı engellendi');
$before=$db->all('SELECT * FROM shipment_entries ORDER BY id');
denied(fn()=>$service->save($admin,1,$id,['version'=>(int)$row['version'],'fields'=>['city'=>'Yurt dışı']]),409);
check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Bağlı sevkiyat kapsam dışına sessizce taşınmaz');

$multi=sample('n11','MULTI',$date,'Ankara','05009998877');
$multi['products']=[['title'=>'','sku'=>'','quantity'=>1],['title'=>'','sku'=>'','quantity'=>1]];
$multi['details']['products']=array_map(fn($id)=>[...$multi['details']['products'][0],'lineId'=>$id,'title'=>'','sku'=>''],['41','42','43']);
$multi['details']['products'][0]['status']='Cancelled';$multi['details']['products'][0]['cancelledQuantity']=1;
$multi['shipmentItems']=[];$multi=DeliveryMapper::finish($multi);$save($multi);
$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='MULTI:package'");$multiId=(int)$row['id'];
$partial=$service->save($admin,1,$multiId,['version'=>1,'fields'=>['productTitle:42'=>'Buzdolabı']]);check(!$partial['ready'],'Kısmi ürün düzeltmesi kontrolde kalır');
$saved=$repo->decode($db->one('SELECT * FROM delivery_orders WHERE id=?',[$multiId]));
check($saved['products'][0]['title']==='Buzdolabı'&&$saved['products'][1]['title']==='','Boş ve aynı SKU olan ürünler karışmaz');
$done=$service->save($admin,1,$multiId,['version'=>$saved['version'],'fields'=>['productTitle:43'=>'Çamaşır makinesi']]);check($done['ready'],'İkinci ürün tamamlanınca doğru tabloya geçti');
$save($multi);$saved=$repo->decode($db->one('SELECT * FROM delivery_orders WHERE id=?',[$multiId]));check(array_column($saved['products'],'title')===['Buzdolabı','Çamaşır makinesi'],'Sonraki aktarımda ürün düzeltmeleri doğru satırda');
check($saved['details']['products'][0]['cancelledQuantity']===1,'İptal ürün miktarı korundu');

$future=sample('Pazarama','FUTURE',(new DateTimeImmutable('+2 days'))->format('c'));$save($future);$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='FUTURE:package'");
$futureId=(int)$row['id'];denied(fn()=>$service->save($admin,1,$futureId,['version'=>1,'fields'=>['phone'=>'05006665544']]),422);
check($row===$db->one('SELECT * FROM delivery_orders WHERE id=?',[$futureId]),'Tarih hatasında eksik aktarım başarılı sayılmaz; işlem geri alınır');

$invoice=sample('idefix','INVOICE',$date);$invoice['shipmentItems'][0]['payload']['marketplaceRowStatus']='invoiced';$save($invoice);$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='INVOICE:package'");
$service->save($admin,1,(int)$row['id'],['version'=>1,'fields'=>['phone'=>'05005554433']]);
$key=hash('sha256','idefix:'.$invoice['shipmentItems'][0]['key']);$invoiceRow=$db->one('SELECT * FROM shipment_entries WHERE source_key=?',[$key]);check(Omnigo\ShipmentStatus::resolve($invoiceRow)==='invoiced','Kaynak fatura bilgisi gerçek sevkiyat durumunu değiştirmez');
$invoicePayload=$cipher->open(1,'shipment:'.$invoiceRow['id'],$invoiceRow['payload']);$invoicePayload['source']['locked']=['phone'];$invoicePayload['documents']['invoice']=['id'=>123,'name'=>'fixture.pdf'];$invoicePayload['phone']='05000000000';$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$invoiceRow['id'],$invoicePayload),$invoiceRow['id']]);
$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='INVOICE:package'");$service->save($admin,1,(int)$row['id'],['version'=>(int)$row['version'],'fields'=>['phone'=>'05004443322']]);
$invoiceRow=$db->one('SELECT * FROM shipment_entries WHERE source_key=?',[$key]);check($cipher->open(1,'shipment:'.$invoiceRow['id'],$invoiceRow['payload'])['phone']==='05004443322'&&Omnigo\ShipmentStatus::resolve($invoiceRow)==='invoiced','Bilgi düzeltmesi kilitli telefon alanını eşitler; fatura statüsünü bozmaz');

$duplicate=sample('n11','MANUAL-DUP',$date);$save($duplicate);$row=$db->one("SELECT * FROM delivery_orders WHERE external_id='MANUAL-DUP:package'");$duplicateId=(int)$row['id'];
$db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated) VALUES(1,'Ankara','n11',?,'',?,?)",[substr($date,0,7),$date,$date]);$manualId=$db->insertId();
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$manualId,['phone'=>'05001230000','orderedAt'=>$date]),$manualId]);
denied(fn()=>$service->save($admin,1,$duplicateId,['version'=>1,'fields'=>['phone'=>'05001230000']]),409);
check($row===$db->one('SELECT * FROM delivery_orders WHERE id=?',[$duplicateId]),'Olası manuel mükerrer varsa tüm düzeltme güvenle geri alınır');
check(!str_contains(json_encode($db->all('SELECT payload FROM delivery_orders')),'05001112233'),'Düzeltilmiş bilgiler şifreli');
check(!str_contains(json_encode($db->all("SELECT after_value FROM audit WHERE action='delivery.corrected'")),'05001112233'),'Denetim günlüğünde kişisel alan değeri yok');
echo "Beş pazaryeri: eksik alan, yetki, firma sınırı, sürüm çakışması, geçmiş sipariş, doğru tablo, idempotent tekrar, sonraki iptal ve kapsam dışı il testleri geçti.\n";

