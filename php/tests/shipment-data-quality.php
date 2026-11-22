<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,DeliverySchema,NotificationSchema,DeliveryCipher,ShipmentRepository,ShipmentOrderIngestor,ShipmentDataQuality,DeliveryRepository,DeliveryMapper,DeliverySelection,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);DeliverySchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$cipher=DeliveryCipher::withKey(str_repeat('q',32));$shipments=new ShipmentOrderIngestor($db,$cipher);$delivery=new DeliveryRepository($db,$cipher);$quality=new ShipmentDataQuality($db,$cipher);
ShipmentOrderIngestor::activate($db,1,time()-86400*2);$date=gmdate('c',time()-600);$month=substr($date,0,7);
function order(string $id,string $city,string $recipient,string $date):array{
 return DeliveryMapper::finish(['provider'=>'n11','externalId'=>$id,'orderNumber'=>explode(':',$id)[0],'buyerName'=>'Aynı Müşteri','recipientName'=>$recipient,'phone'=>'05000000000','address'=>'Kaynak adresi','city'=>$city,'district'=>'Merkez','createdAt'=>$date,'status'=>'Picking','shippingState'=>'pending','active'=>true,'products'=>[['title'=>'Ürün','quantity'=>2]],'details'=>['recipientName'=>$recipient],'cancellation'=>[]]);
}
$firstOrder=order('ONE:p1','Ankara','Birinci Alıcı',$date);
$firstOrder['district']='ANKARA';
$firstOrder['details']['deliveryAddress']='Örnek Sokak, 06100, YENİMAHALLE';
$firstOrder['details']['invoiceAddress']='Fatura Caddesi, Sincan / Ankara';
$delivery->ingest(1,$firstOrder);$delivery->ingest(1,order('TWO:p1','Kayseri','İkinci Alıcı',$date));$delivery->ingest(1,order('ONE:p2','Ankara','Birinci Alıcı',$date));$delivery->ingest(2,[...order('ONE:p1','Ankara','Diğer Firma',$date),'district'=>'Çankaya']);
$item=['key'=>'row-one','payload'=>['orderedAt'=>$date,'province'=>'Ankara','recipientName'=>'','phone'=>'ELLE','sourceDeliveryId'=>'ONE:p1','productName'=>'Ürün','quantity'=>2,'orderNumber'=>'']];
$save=fn($p)=>$db->transaction(fn()=>$shipments->ingest(1,'n11',[$p]));$save($item);
$row=$db->one('SELECT * FROM shipment_entries');$id=(int)$row['id'];$stored=$cipher->open(1,'shipment:'.$id,$row['payload']);$stored['source']['locked']=['recipientName','phone','orderNumber'];
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$stored),$id]);
$before=$db->all('SELECT * FROM shipment_entries');$public=[['id'=>$id,'provider'=>'n11','recipientName'=>'']];$filled=$quality->enrich(1,$public)[0];
check($filled['recipientName']==='Birinci Alıcı'&&$filled['orderNumber']==='ONE'&&!$filled['dataIssues'],'Yalnız kesin kaynak bağlantısından doğru alıcı ve numara');check($before===$db->all('SELECT * FROM shipment_entries'),'Liste ve dışa aktarım veri değiştirmez');
check($quality->enrich(2,$public)[0]['recipientName']==='','Firma dışı kaynağa erişilmez');
foreach([new ShipmentRepository($db,$cipher),new ShipmentRepository($db,$cipher,true)] as $portal){
 foreach([false,true] as $invoice){
  $listing=$portal->listing(1,['all','n11',$month],invoice:$invoice)['items'];
  check(count($listing)===1&&$listing[0]['province']==='Ankara'&&$listing[0]['district']==='Yenimahalle',
    'Yönetici ve müşteri listeleri aynı mağazanın teslimat ilçesini göstermeli.');
 }
 $export=$portal->export(1,['all','n11',$month])['items'];
 check(count($export)===1&&$export[0]['province']==='Ankara'&&$export[0]['district']==='Yenimahalle',
    'Çıktıda fatura veya başka mağaza adresinin ilçesi kullanılmamalı.');
}
$item['payload']['recipientName']='Birinci Alıcı';$item['payload']['orderNumber']='ONE';$item['payload']['phone']='KAYNAK';$save($item);
$row=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$id]);$p=$cipher->open(1,'shipment:'.$id,$row['payload']);
check($p['recipientName']==='Birinci Alıcı'&&$p['orderNumber']==='ONE'&&$p['phone']==='ELLE','Boş kimlik kilidi doldurulur; elle girilen telefon korunur');
$item['payload']['recipientName']='Değişen kaynak';$save($item);$p=$cipher->open(1,'shipment:'.$id,$db->one('SELECT payload FROM shipment_entries WHERE id=?',[$id])['payload']);check($p['recipientName']==='Birinci Alıcı','Dolu manuel alıcı kilidi korunur');
$stable=$db->all('SELECT * FROM shipment_entries');$save($item);check($stable===$db->all('SELECT * FROM shipment_entries'),'Tekrar aktarım yeni satır veya sürüm üretmez');
$db->run('UPDATE shipment_entries SET source_key=NULL WHERE id=?',[$id]);check($quality->enrich(1,$public)[0]['recipientName']==='','Kaynak bağı olmayan manuel satır başka kayıt üzerinden eşleştirilmez');
$payload=$cipher->open(1,'shipment:'.$id,$db->one('SELECT payload FROM shipment_entries WHERE id=?',[$id])['payload']);
$payload['sellerStockCode']='GENERIC';$payload['verifiedLegacyProduct']=['provider'=>'n11','sourceKey'=>str_repeat('a',64),
    'identity'=>Omnigo\ShipmentProductCode::legacyIdentity($payload),'code'=>'00120'];
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$payload),$id]);
check($quality->enrich(1,$public)[0]['productCode']==='00120','Eski satırın doğrulanmış kodu ve baştaki sıfırlar gösterilir');
check($quality->enrich(2,$public)[0]['productCode']===''&&$quality->enrich(1,[[...$public[0],'provider'=>'idefix']])[0]['productCode']==='','Doğrulama firma ve pazaryerine bağlıdır');
$payload['productName']='Değiştirilen ürün';
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$payload),$id]);
check($quality->enrich(1,$public)[0]['productCode']==='','Ürün bilgisi değiştiğinde eski doğrulama kullanılmaz');
$selection=new DeliverySelection($db,$delivery);$rows=$db->all('SELECT id,version FROM delivery_orders WHERE company_id=1');$request=['city'=>'all','deliveryDate'=>date('Y-m-d'),'orders'=>array_map(fn($r)=>['id'=>(int)$r['id'],'version'=>(int)$r['version']],$rows)];
$snapshot=$selection->snapshot(1,$request);$cities=array_values(array_unique(array_column($snapshot['orders'],'city')));sort($cities);check($snapshot['city']==='Tüm Şehirler'&&$cities===['Ankara','Kayseri'],'Tüm şehirler dökümü gerçek il bilgilerini korur');
$listing=$delivery->listing(1,'all','all','all',0);$counts=array_column($listing['cities'],null,'name');check($counts['all']['count']===2&&$counts['all']['productCount']===6,'Aynı siparişin iki paketi tek sipariş sayılır; parçalar toplanır');
$delivery->ingest(1,order('MISSING:p1','Ankara','',$date));$control=$delivery->listing(1,'Diğer / Kontrol','all','all',0);check(count($control['items'])===1&&in_array('Alıcı adı eksik',$control['items'][0]['issues'],true),'Alıcısı eksik aktif sipariş kontrol listesinde açıklanır');
$missing=$control['items'][0];try{$selection->snapshot(1,[...$request,'orders'=>[['id'=>$missing['id'],'version'=>$missing['version']]]]);throw new RuntimeException('Eksik evrak hazır sayıldı');}catch(HttpError $e){check(in_array($e->getCode(),[409,422],true),'Eksik bilgi açık hata verir');}
echo "Kesin kaynak bağı, boş kilit onarımı, dolu alan koruması, firma izolasyonu, çoklu şehir/paket ve eksik alıcı kontrolü geçti.\n";
