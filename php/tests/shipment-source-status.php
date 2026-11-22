<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentOrderIngestor,ShipmentSourceStatus,ShipmentStatus,OrderShipping,SyncFailure};
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'test@example.invalid','Test','x','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('t',32));$repo=new DeliveryRepository($db,$cipher);$ship=new ShipmentRepository($db,$cipher);ShipmentOrderIngestor::activate($db,1,time()-86400*30);
$created=gmdate('c',time()-86400*20);$month=(new DateTimeImmutable($created))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m');$scope=fn($provider)=>['all',$provider,$month];
foreach(['n11','Pazarama','Koçtaş','idefix','Hepsiburada'] as $provider){
 $payload=['orderedAt'=>$created,'customerName'=>'Müşteri','recipientName'=>'Alıcı','phone'=>'05000000000','deliveryAddress'=>'Teslimat adresi','province'=>'Ankara','district'=>'Çankaya','productName'=>'Test ürün','invoiceCents'=>12000,'quantity'=>1,'invoiceName'=>'Fatura adı','invoiceAddress'=>'Fatura adresi','recipientEmail'=>'sample@example.invalid','customerType'=>'Bireysel','packageStatus'=>'Hazırlanıyor','marketplaceShipping'=>'pending','marketplaceCancellation'=>['quantity'=>1,'cancelledQuantity'=>0,'partial'=>false]];
 $order=['provider'=>$provider,'externalId'=>'STATUS-1','orderNumber'=>'STATUS-1','createdAt'=>$created,'buyerName'=>'Müşteri','phone'=>'05000000000','address'=>'Teslimat adresi','city'=>'Ankara','district'=>'Çankaya','status'=>'Hazırlanıyor','active'=>true,'products'=>[['title'=>'Test ürün','sku'=>'T','quantity'=>1]],'details'=>['products'=>[['lineId'=>'L1','title'=>'Test ürün','quantity'=>1]]],'cancellation'=>['quantity'=>1,'cancelledQuantity'=>0,'partial'=>false,'lines'=>['L1'=>['quantity'=>1,'cancelledQuantity'=>0,'partial'=>false]]],'shipmentItems'=>[['key'=>'L1','payload'=>$payload]]];
 $save=fn($value)=>$db->transaction(fn()=>$repo->ingest(1,$value,false));
 $save($order);$save($order);check(count($repo->listing(1,'all',$provider,'all',0)['items'])===1&&$ship->listing(1,$scope($provider))['total']===1,$provider.' kesintiden kalan sipariş tekil eklendi');
 foreach(['shipped','delivered','cancelled'] as $status){
  $order['shipmentItems'][0]['payload']['marketplaceShipping']=$status;
  if($status==='delivered'){$order['active']=false;$order['products']=[];}
  if($status==='cancelled'){$order['status']='İptal edildi';$order['cancellation']['cancelledQuantity']=1;$order['cancellation']['lines']['L1']['cancelledQuantity']=1;$order['shipmentItems'][0]['payload']['marketplaceCancellation']['cancelledQuantity']=1;}
  $save($order);$row=$ship->listing(1,$scope($provider))['items'][0];check($row['rowStatus']===($status==='delivered'?'completed':$status)&&$row['marketplaceStatus']===$status,$provider.' kaynak statüsü otomatik uygulanır: '.$status);$version=$row['version'];$save($order);check($ship->listing(1,$scope($provider))['items'][0]['version']===$version,$provider.' tekrar sürüm/renk değiştirmez');
 }
 check($repo->listing(1,'all',$provider,'all',0)['items']===[],$provider.' iptal evrak listesine girmez');
 $stale=$order;$stale['active']=true;$stale['cancellation']['cancelledQuantity']=0;$stale['cancellation']['lines']['L1']['cancelledQuantity']=0;$stale['shipmentItems'][0]['payload']['marketplaceShipping']='pending';
 $before=hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')]));
 try{$save($stale);throw new RuntimeException('Eski yanıt kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===409,$provider.' eski iptal yanıtı reddedilir');}
 check($before===hash('sha256',json_encode([$db->all('SELECT * FROM delivery_orders'),$db->all('SELECT * FROM shipment_entries')])),$provider.' eski yanıtta iki tablo değişmez');
 check($repo->listing(2,'all',$provider,'all',0)['items']===[],$provider.' diğer müşteri verisi görünmez');
}
check(OrderShipping::state('Pazarama',14)==='undelivered'&&OrderShipping::state('Pazarama',11)==='delivered','Pazarama teslim edilemedi ile teslim edildi farklı');
check(OrderShipping::state('Pazarama',18)==='pending','İptal talebi kesinleşmiş iptal değildir');
check(ShipmentSourceStatus::next(['row_status'=>'completed'],['marketplaceShipping'=>'pending'],['marketplaceShipping'=>'shipped'],true)==='shipped','Eski elle teyit kaynak statüsünü engellemez');
echo "Beş pazaryeri: 20 günlük kesinti sonrası tekrarsız kayıt, kargo/teslim/iptal renkleri, iptal evrak dışlama, eski yanıt engeli, müşteri izolasyonu ve Pazarama kodları geçti.\n";
