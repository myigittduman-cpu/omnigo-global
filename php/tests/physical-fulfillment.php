<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentOrderIngestor,DeliverySelection,DeliveryListAdditions,DeliveryPlan,ShipmentBulkStatus,FulfillmentMigration,HttpError,SyncFailure};
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
 return ['provider'=>$provider,'externalId'=>$number,'orderNumber'=>$number,'city'=>$city,'createdAt'=>$date,'buyerName'=>'Test Müşteri','phone'=>'05000000000','address'=>'Örnek teslimat adresi','status'=>'Hazırlanıyor','active'=>true,'products'=>[$product],'issues'=>[],'details'=>['recipientName'=>$payload['recipientName'],'products'=>[$product]],'cancellation'=>['quantity'=>2,'cancelledQuantity'=>0,'partial'=>false,'lines'=>['L1'=>['quantity'=>2,'cancelledQuantity'=>0]]],'shipmentItems'=>[['key'=>hash('sha256',json_encode([$number,'L1'])),'payload'=>$payload]]];
};
$save=fn($order)=>$db->transaction(fn()=>$orders->ingest(1,$order,false));
check(Omnigo\ShipmentSourceStatus::rowStatus(['marketplaceShipping'=>'delivered','marketplaceRowStatus'=>'shipped'])==='completed','Eski türetilmiş kargo etiketi güncel teslim bilgisini ezmez');
foreach(['n11','Pazarama','Koçtaş','idefix','Hepsiburada','PTT AVM'] as $provider){
 $order=$make($provider);$scope=['all',$provider,$month];$save($order);$save($order);
 $ship=$ships->listing(1,$scope)['items'][0];check($ship['rowStatus']===''&&!$ship['fulfillmentNeedsReview'],'Yeni sipariş otomatik bekler');
 foreach(['shipped','delivered','cancelled'] as $status){
  $order['shipmentItems'][0]['payload']['marketplaceShipping']=$status;$order['details']['products'][0]['shippingState']=$status;$order['status']=$status;
  if($status==='delivered'){$order['active']=false;$order['products']=[];}
  if($status==='cancelled'){$order['cancellation']['cancelledQuantity']=2;$order['cancellation']['lines']['L1']['cancelledQuantity']=2;$order['details']['products'][0]['cancelledQuantity']=2;$order['shipmentItems'][0]['payload']['marketplaceCancellation']['cancelledQuantity']=2;}
  $save($order);$ship=$ships->listing(1,$scope)['items'][0];$expected=$status==='delivered'?'completed':$status;
  check($ship['rowStatus']===$expected&&$ship['displayRowStatus']===$expected&&!$ship['fulfillmentNeedsReview'],$provider.' pazaryeri durumu görünür: '.$status);
  $version=$ship['version'];$save($order);check($ships->listing(1,$scope)['items'][0]['version']===$version,'Tekrarlanan aktarım değişiklik/mükerrer üretmez');
  check($orders->listing(1,'all',$provider,'review',0)['queues']['review']===0,'Teyit kuyruğu boş');
  if($status==='shipped'){
   check(!$orders->listing(1,'Ankara',$provider,'all',0,0)['items'],'Sevk edilen sipariş kendiliğinden şehir listesine girmez');
   $candidate=$orders->listing(1,'Ankara',$provider,DeliveryPlan::ADDITIONS,0,10)['items'][0];
   $input=['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>$candidate['selectionRecords']];
   fails(fn()=>(new DeliverySelection($db,$orders))->snapshot(1,$input),409);
   (new DeliveryListAdditions($db,$cipher,$orders))->add(1,1,$input);
   $listed=$orders->listing(1,'Ankara',$provider,'all',0,0)['items'][0];
   check($listed['fulfillmentStatus']==='pending','Elle eklenen sipariş sevk bekliyor olarak görünür');
   $input['orders']=$listed['selectionRecords'];
   check(count((new DeliverySelection($db,$orders))->snapshot(1,$input)['orders'])===1,'Elle eklenen sipariş güncel evrağa alınır');
   fails(fn()=>$ships->toggle($admin,1,$ship['id'],$scope,['field'=>'rowStatus','value'=>'','version'=>$version,'confirmFulfillment'=>true]),409);
   $invoice=$ships->toggle($admin,1,$ship['id'],$scope,['field'=>'invoiced','value'=>true,'version'=>$version]);
   check($invoice['displayRowStatus']==='shipped'&&$invoice['invoiced'],'Fatura işareti kaynak sevkiyatını değiştirmez');
  }else{
   check(!$orders->listing(1,'all',$provider,'all',0)['items'],'Teslim/iptal aktif listeden çıkar');
   check($orders->listing(1,'all',$provider,'delivered',0)['queues']['delivered']===($status==='delivered'?1:0),'Teslim edilenler sayacı kaynakla aynı');
  }
 }
}
foreach(['n11','Pazarama','Koçtaş','idefix','Hepsiburada','PTT AVM'] as $provider){
 $order=$make($provider,'OVERRIDE');$scope=['all',$provider,$month];$save($order);
 $source=hash('sha256',$provider.':'.$order['shipmentItems'][0]['key']);
 $row=$db->one('SELECT * FROM shipment_entries WHERE source_key=?',[$source]);
 $order['shipmentItems'][0]['payload']['marketplaceShipping']='shipped';
 $order['details']['products'][0]['shippingState']='shipped';$save($order);
 $delivery=$orders->listing(1,'Ankara',$provider,DeliveryPlan::ADDITIONS,0,10)['items'][0];
 (new DeliveryListAdditions($db,$cipher,$orders))->add(1,1,['city'=>'Ankara','orders'=>$delivery['selectionRecords']]);
 check($ships->listing(1,$scope)['items'][0]['displayRowStatus']==='shipped','Teslimat listesine ekleme sevkiyat statüsünü değiştirmez');
 $order['shipmentItems'][0]['payload']['marketplaceShipping']='delivered';$order['details']['products'][0]['shippingState']='delivered';
 $save($order);$row=$db->one('SELECT * FROM shipment_entries WHERE source_key=?',[$source]);
 check($row['row_status']==='completed','Canlı teslim doğrulaması son yeşil duruma geçer');
 $payload=$cipher->open(1,'shipment:'.$row['id'],$row['payload']);$view=Omnigo\ShipmentFulfillment::presentation($row,$payload);
 check($view['marketplaceStatus']==='delivered'&&$view['displayRowStatus']==='completed','Canlı teslim doğrulaması görünür duruma da yansır');
 $changed=(new ShipmentBulkStatus($db,$cipher))->save($admin,1,$scope,['items'=>[['id'=>(int)$row['id'],'version'=>(int)$row['version']]],'value'=>'completed']);
 $save($order);$row=$db->one('SELECT * FROM shipment_entries WHERE source_key=?',[$source]);
 check($row['row_status']==='completed','Kullanıcı yeniden değiştirdiğinde yeni seçim korunur');
}
// Repeat buyers are separate orders; cancellation must never cross an order identity.
foreach(['n11','Pazarama','Koçtaş','idefix','Hepsiburada','PTT AVM'] as $provider){
 $first=$make($provider,'REPEAT-BUYER-A');$second=$make($provider,'REPEAT-BUYER-B');
 $first['buyerName']=$second['buyerName']='Aynı Örnek Müşteri';
 $second['createdAt']=gmdate('c',strtotime($first['createdAt'])+120);
 $second['shipmentItems'][0]['payload']['orderedAt']=$second['createdAt'];
 $save($first);$save($second);$save($first);$save($second);
 $source=fn($order)=>hash('sha256',$provider.':'.$order['shipmentItems'][0]['key']);
 $firstRow=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND source_key=?',[$source($first)]);
 $secondRow=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND source_key=?',[$source($second)]);
 check($firstRow['id']!==$secondRow['id'],'Aynı isim, telefon, adres ve ürünle iki dakika arayla verilen siparişler ayrı kalır');
 $secondDelivery=$db->one('SELECT * FROM delivery_orders WHERE company_id=1 AND provider=? AND external_id=?',[$provider,$second['externalId']]);
 $first['active']=false;$first['products']=[];$first['status']='İptal edildi';
 $first['cancellation']['cancelledQuantity']=2;$first['cancellation']['lines']['L1']['cancelledQuantity']=2;
 $first['details']['products'][0]['cancelledQuantity']=2;$first['details']['products'][0]['shippingState']='cancelled';
 $first['shipmentItems'][0]['payload']['marketplaceShipping']='cancelled';
 $first['shipmentItems'][0]['payload']['marketplaceCancellation']['cancelledQuantity']=2;
 $save($first);$save($first);
 check($secondRow===$db->one('SELECT * FROM shipment_entries WHERE id=?',[$secondRow['id']]),'İlk siparişin iptali ikinci sevkiyat kaydını değiştirmez');
 check($secondDelivery===$db->one('SELECT * FROM delivery_orders WHERE id=?',[$secondDelivery['id']]),'İlk siparişin iptali ikinci teslimat kaydını değiştirmez');
 $cancelled=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$firstRow['id']]);
 $cancelledPayload=$cipher->open(1,'shipment:'.$firstRow['id'],$cancelled['payload']);
 check(Omnigo\ShipmentFulfillment::presentation($cancelled,$cancelledPayload)['marketplaceStatus']==='cancelled'&&$cancelledPayload['marketplaceCancellation']['cancelledQuantity']===2,'Yalnız iptal edilen siparişin kırmızı kaynak iptali işareti taşınır');
 $active=$orders->listing(1,'Ankara',$provider,'all',0)['items'];
 check(in_array('REPEAT-BUYER-B',array_column($active,'orderNumber'),true)&&!in_array('REPEAT-BUYER-A',array_column($active,'orderNumber'),true),'Aktif evrak listesinde ikinci sipariş kalır, ilk iptal çıkar');
 $second['active']=false;$second['products']=[];$second['status']='Teslim edildi';
 $second['details']['products'][0]['shippingState']='delivered';$second['shipmentItems'][0]['payload']['marketplaceShipping']='delivered';
 $save($second);$save($second);
 check($db->one('SELECT row_status FROM shipment_entries WHERE id=?',[$secondRow['id']])['row_status']==='completed','İkinci sipariş teslim edilince yeşile döner');
 check(in_array((int)$secondRow['id'],array_column($orders->listing(1,'all',$provider,'delivered',0)['items'],'shipmentId'),true),'Teslim edilen ikinci sipariş teslimat evraklarının Teslim Edilenler listesinde korunur');
}
// Missing recipient data belongs in the control list, never in a dispatch document.
$incomplete=$make('n11','MISSING-RECIPIENT');unset($incomplete['details']['recipientName']);$save($incomplete);
$matches=fn(array $items)=>array_values(array_filter($items,fn($item)=>$item['orderNumber']==='MISSING-RECIPIENT'));
check(!$matches($orders->listing(1,'Ankara','n11','all',0)['items']),'Alıcı adı eksik sipariş Ankara evrak listesinde gösterilmez');
$control=$matches($orders->listing(1,'Diğer / Kontrol','n11','all',0)['items']);
check(count($control)===1&&in_array('Alıcı adı eksik',$control[0]['issues'],true),'Eksik alıcı adı açık gerekçeyle kontrol listesinde kalır');
fails(fn()=>(new DeliverySelection($db,$orders))->snapshot(1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>[['id'=>$control[0]['id'],'version'=>$control[0]['version']]]]),422);
$incomplete['details']['recipientName']='Test Alıcı';$save($incomplete);
check(count($matches($orders->listing(1,'Ankara','n11','all',0)['items']))===1,'Kaynak alıcı adı tamamlanınca sipariş Ankara listesine döner');
check(!$matches($orders->listing(1,'Diğer / Kontrol','n11','all',0)['items']),'Tamamlanan sipariş kontrol listesinde kalmaz');
echo "Altı pazaryeri: otomatik kargo/teslim/iptal, teyitsiz evrak, eski teyit isteği engeli, fatura ayrımı, tekrar, ayrı sipariş kimliği ve eksik alıcı koruması testleri geçti.\n";
