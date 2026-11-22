<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentOrderIngestor,DeliverySelection,ShipmentBulkStatus,FulfillmentMigration,HttpError,SyncFailure};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $action,int $code):void{try{$action();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $e){check($e->getCode()===$code,'HTTP '.$code);}}
function unverified(callable $action):void{try{$action();throw new RuntimeException('Doğrulanmamış sipariş engellenmeliydi.');}catch(HttpError $e){check($e->getCode()===409&&$e->getMessage()===Omnigo\DeliveryDocumentGuard::MESSAGE,'Belirsiz sevkiyatın açık gerekçesi');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@example.invalid','Yönetici Test','hash','admin',NULL),(2,'c@example.invalid','Müşteri Test','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('f',32));$orders=new DeliveryRepository($db,$cipher);$ships=new ShipmentRepository($db,$cipher);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1,'operatorName'=>'Test Kullanıcı'];
$date=gmdate('c',time()-8*86400);$month=(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m');ShipmentOrderIngestor::activate($db,1,time()-30*86400);
$make=function(string $provider,string $number='F1',string $city='Ankara')use($date){
 $product=['lineId'=>'L1','title'=>'Örnek ürün','sku'=>'SKU','quantity'=>2,'cancelledQuantity'=>0,'shippingState'=>'pending'];
 $payload=['orderedAt'=>$date,'customerName'=>'Test Müşteri','recipientName'=>'Test Alıcı','phone'=>'05000000000','deliveryAddress'=>'Örnek teslimat adresi','province'=>$city,'district'=>'Merkez','productName'=>'Örnek ürün','quantity'=>2,'invoiceCents'=>20000,'marketplaceShipping'=>'pending','marketplaceCancellation'=>['quantity'=>2,'cancelledQuantity'=>0,'partial'=>false]];
 return ['provider'=>$provider,'externalId'=>$number,'orderNumber'=>$number,'city'=>$city,'createdAt'=>$date,'buyerName'=>'Test Müşteri','phone'=>'05000000000','address'=>'Örnek teslimat adresi','status'=>'Hazırlanıyor','active'=>true,'products'=>[$product],'issues'=>[],'details'=>['products'=>[$product]],'cancellation'=>['quantity'=>2,'cancelledQuantity'=>0,'partial'=>false,'lines'=>['L1'=>['quantity'=>2,'cancelledQuantity'=>0]]],'shipmentItems'=>[['key'=>hash('sha256',json_encode([$number,'L1'])),'payload'=>$payload]]];
};
$save=fn($order)=>$db->transaction(fn()=>$orders->ingest(1,$order,false));
check(Omnigo\ShipmentSourceStatus::rowStatus(['marketplaceShipping'=>'delivered','marketplaceRowStatus'=>'shipped'])==='completed','Eski türetilmiş kargo etiketi güncel teslim bilgisini ezmez');

$provider='n11';$scope=['all',$provider,$month];$order=$make($provider,'CONFIRM-1');$save($order);$ship=$ships->listing(1,$scope)['items'][0];
$confirmation=new \Omnigo\ShipmentStatusConfirmation($db,$cipher);
$preview=$confirmation->preview($admin,1,$scope,['ids'=>[$ship['id']],'value'=>'shipped']);
check($preview['items'][0]['marketplaceStatusText']==='Sevk bekliyor','Onay kaynaktaki güncel durumu göstermeli');
$before=$db->all('SELECT * FROM shipment_entries');
fails(fn()=>\Omnigo\ShipmentStatusConfirmation::required(['items'=>[['id'=>$ship['id']]]],true),428);
check($db->all('SELECT * FROM shipment_entries')===$before,'Onaysız önizleme kaydı değiştirmemeli');
$entry=$preview['items'][0];$ships->toggle($admin,1,$entry['id'],$scope,['field'=>'rowStatus','value'=>'shipped','version'=>$entry['version'],'confirmation'=>$entry['confirmation']]);
$fresh=$confirmation->preview($admin,1,$scope,['ids'=>[$ship['id']],'value'=>'completed'])['items'][0];
$order['shipmentItems'][0]['payload']['marketplaceShipping']='shipped';$order['details']['products'][0]['shippingState']='shipped';$save($order);
fails(fn()=>$ships->toggle($admin,1,$ship['id'],$scope,['field'=>'rowStatus','value'=>'completed','version'=>$fresh['version'],'confirmation'=>$fresh['confirmation']]),409);
$current=$ships->listing(1,$scope)['items'][0];check($current['rowStatus']==='shipped','Eski onay uygulanmamalı');
$stored=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$ship['id']]);$payload=$cipher->open(1,'shipment:'.$ship['id'],$stored['payload']);$payload['phone']='05001112233';$payload['source']['locked']=['phone'];$payload['manualStatusOverride']['status']='';
$db->run("UPDATE shipment_entries SET payload=?,row_status='' WHERE id=?",[$cipher->seal(1,'shipment:'.$ship['id'],$payload),$ship['id']]);
$backups=[];$alignment=new \Omnigo\ShipmentStatusAlignment($db,$cipher);$result=$alignment->run(1,function($row)use(&$backups){$backups[]=$row;});
check(count($backups)===1&&count($result['updated'])===1,'Kaynak eşitleme yalnız gereken satırı değiştirmeli');
$updated=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$ship['id']]);$value=$cipher->open(1,'shipment:'.$ship['id'],$updated['payload']);check($updated['row_status']==='shipped'&&!isset($value['manualStatusOverride'])&&$value['phone']==='05001112233'&&$value['source']['locked']===['phone'],'Adres/telefon düzeltmeleri ve alan kilidi korunmalı');
check(count($alignment->run(1,fn($row)=>null)['updated'])===0,'Eşitleme tekrar çalışınca değişiklik üretmemeli');
$second=$make($provider,'CONFIRM-2');$save($second);
$filtered=$orders->listing(1,'all',$provider,'all',0,20,false,['query'=>'CONFIRM-2']);check(count($filtered['items'])===1&&$filtered['items'][0]['orderNumber']==='CONFIRM-2','Filtre tüm kayıtlara sayfalama öncesi uygulanmalı');
check($filtered['cities'][0]['productCount']===2,'Şehir kartında parça adedi');
$export=$ships->export(1,$scope);check($export['summary']['orderCount']===2&&$export['summary']['productCount']===4,'Sipariş özeti kimlik ve adet bazlı olmalı');
echo "Durum onayı, kaynak değişimi çakışması, yedekli eşitleme, alan koruması, filtre ve adet testleri geçti.\n";
