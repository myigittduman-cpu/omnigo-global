<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,DeliveryMapper,ShipmentOrderMapper,ShipmentOrderIngestor,OrderDetailMapper,IdefixOrderMapper};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
date_default_timezone_set('UTC');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Test firma'),(2,'Diğer firma')");
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode(['deliveryCities'=>Omnigo\TurkeyCities::ALL,'activeDeliveryCities'=>Omnigo\TurkeyCities::ALL])]);
$cipher=DeliveryCipher::withKey(str_repeat('f',32));$repo=new DeliveryRepository($db,$cipher);ShipmentOrderIngestor::activate($db,1,time()-86400);
$at=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->modify('-2 minutes');$sequence=0;
foreach(DeliveryMapper::CITIES as $city)foreach(['n11','Pazarama','Koçtaş','idefix'] as $provider){
 $id=(string)++$sequence;$phone='05001234567';$address='Teslimat sokağı 1/2';$bill='Fatura caddesi 3/4';$title='Test ürün';
 if($provider==='n11'){
  $raw=['id'=>$id,'orderNumber'=>$id,'customerfullName'=>'Sipariş Sahibi','customerEmail'=>'test@example.invalid','shippingAddress'=>['fullName'=>'Teslim Alan','gsm'=>$phone,'city'=>$city,'district'=>'Test İlçe','address'=>$address],'billingAddress'=>['fullName'=>'Fatura Şirketi','address'=>$bill,'invoiceType'=>2,'taxHouse'=>'Test VD','taxId'=>'0012345678'],'packageHistories'=>[['status'=>'Created','createdDate'=>$at->getTimestamp()*1000]],'shipmentPackageStatus'=>'Created','lines'=>[['orderLineId'=>$id,'orderItemLineItemStatusName'=>'Created','productName'=>$title,'quantity'=>1,'sellerInvoiceAmount'=>'123.45']]];
  $d=DeliveryMapper::n11($raw);$d['details']=OrderDetailMapper::n11($raw,$d);$d['shipmentItems']=ShipmentOrderMapper::n11($raw,$d);
 }elseif($provider==='Pazarama'){
  $raw=['orderId'=>$id,'orderNumber'=>$id,'orderDate'=>$at->format('Y-m-d H:i'),'customerName'=>'Sipariş Sahibi','shipmentAddress'=>['nameSurname'=>'Teslim Alan','cityName'=>$city,'districtName'=>'Test İlçe','displayAddressText'=>$address,'phoneNumber'=>$phone,'customerEmail'=>'test@example.invalid'],'billingAddress'=>['companyName'=>'Fatura Şirketi','displayAddressText'=>$bill,'invoiceType'=>2,'taxNumber'=>'0012345678','taxOffice'=>'Test VD'],'items'=>[['orderItemId'=>$id,'quantity'=>1,'orderItemStatus'=>3,'product'=>['name'=>$title],'totalPrice'=>['currency'=>'TL','value'=>'123.45','valueInt'=>12345]]]];
  $raw['items'][0]+=['salePrice'=>['currency'=>'TL','value'=>'123.45'],'discountAmount'=>['currency'=>'TL','value'=>0],'discountDescription'=>'(Pazarama İndirimi - 0 TL)'];
  $d=DeliveryMapper::pazarama($raw);$d['details']=OrderDetailMapper::pazarama($raw,$d);$d['shipmentItems']=ShipmentOrderMapper::pazarama($raw,$d);
 }elseif($provider==='Koçtaş'){
  $raw=['order_id'=>$id,'order_state'=>'WAITING_ACCEPTANCE','created_date'=>$at->format(DATE_ATOM),'customer_notification_email'=>'test@example.invalid','customer'=>['firstname'=>'Sipariş','lastname'=>'Sahibi','shipping_address'=>['firstname'=>'Teslim','lastname'=>'Alan','phone'=>$phone,'state'=>$city,'city'=>'Test İlçe','street_1'=>$address],'billing_address'=>['company'=>'Fatura Şirketi','street_1'=>$bill]],'order_additional_fields'=>[['code'=>'tax-number','value'=>'0012345678'],['code'=>'tax-office','value'=>'Test VD']],'order_lines'=>[['order_line_id'=>$id,'order_line_state'=>'WAITING_ACCEPTANCE','price'=>'123.45','quantity'=>1,'product_title'=>$title]]];
  $d=DeliveryMapper::koctas($raw);$d['details']=OrderDetailMapper::koctas($raw,$d);$d['shipmentItems']=ShipmentOrderMapper::koctas($raw,$d);
 }else{
  $raw=['id'=>$id,'orderNumber'=>$id,'orderDate'=>$at->format(DATE_ATOM),'status'=>'created','customerContactName'=>'Sipariş Sahibi','customerContactMail'=>'test@example.invalid','discountedTotalPrice'=>'123.45','shippingAddress'=>['fullName'=>'Teslim Alan','phone'=>$phone,'fullAddress'=>$address,'city'=>$city,'county'=>'Test İlçe'],'invoiceAddress'=>['isCommercial'=>true,'company'=>'Fatura Şirketi','fullAddress'=>$bill,'taxOffice'=>'Test VD','taxNumber'=>'0012345678'],'items'=>[['id'=>$id,'currency'=>'TRY','productName'=>$title,'discountedTotalPrice'=>'123.45','itemStatus'=>'created']]];
  $d=IdefixOrderMapper::map($raw)['delivery'];
 }
 check($d['active']&&$d['city']===$city,$provider.' yeni sipariş teslimat şehrinde aktif');
 $save=fn($order)=>$db->transaction(fn()=>$repo->ingest(1,$order));$save($d);$save($d);
 $delivery=$db->one('SELECT * FROM delivery_orders WHERE company_id=1 AND provider=? AND external_id=?',[$provider,$d['externalId']]);
 $savedDelivery=$repo->decode($delivery);$source=hash('sha256',$provider.':'.$d['shipmentItems'][0]['key']);
 $shipment=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND source_key=?',[$source]);check($shipment!==null,$provider.' sevkiyat tablosuna eklendi');
 $saved=$cipher->open(1,'shipment:'.$shipment['id'],$shipment['payload']);
 check($shipment['city']===$city&&$shipment['month']===$at->format('Y-m'),$provider.' şehir ve ay eşleşmesi');
 foreach(['customerName'=>'Sipariş Sahibi','recipientName'=>'Teslim Alan','phone'=>$phone,'productName'=>$title,'invoiceName'=>'Fatura Şirketi','recipientEmail'=>'test@example.invalid','customerType'=>'Kurumsal','taxNumber'=>'0012345678','quantity'=>1,'invoiceCents'=>12345] as $field=>$expected)check($saved[$field]===$expected,$provider.' doğru alan: '.$field);
 check(str_contains($saved['deliveryAddress'],$address)&&str_contains($saved['invoiceAddress'],$bill)&&str_contains($saved['invoiceAddress'],'V.D.: Test VD'),$provider.' teslimat ve fatura adresi ayrı');
 check($savedDelivery['buyerName']===$saved['customerName']&&$savedDelivery['phone']===$saved['phone']&&\Omnigo\ShipmentAddress::clean($savedDelivery['address'],$saved)===$saved['deliveryAddress']&&$savedDelivery['details']['recipientName']===$saved['recipientName'],$provider.' iki menü tutarlı; sevkiyat adresinde tekrarlanan şehir ve ilçe çıkarılır');
 check(count($repo->listing(1,$city,$provider,'all',0)['items'])===1,$provider.' tekrar aktarım çift evrak oluşturmaz');
}
check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===324,'324 şehir/pazaryeri satırı tekil');
check($db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===324,'324 teslimat kaydı tekil');
check($repo->listing(2,'all','all','all',0)['items']===[],'Firma ayrımı korunur');
$broken=$d;$broken['externalId']='';$broken['shipmentItems'][0]['key']='rollback-example';
try{$db->transaction(fn()=>$repo->ingest(1,$broken));throw new RuntimeException('Bozuk sipariş kabul edildi');}catch(\Omnigo\SyncFailure){}
check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===324&&$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===324,'Teslimat hatasında sevkiyat kaydı da geri alınır');
$db->run('UPDATE shipment_entries SET removed_at=? WHERE id=?',[gmdate('c'),$shipment['id']]);$db->transaction(fn()=>$repo->ingest(1,$d));
check($db->one('SELECT removed_at FROM shipment_entries WHERE id=?',[$shipment['id']])['removed_at']!==null,'Elle silinen sevkiyat geri getirilmez');
echo "Dört pazaryeri × 81 şehir: yeni siparişin iki menüye aktarımı, alanlar, ay, tekilleştirme, firma sınırı ve işlem geri alma geçti.\n";
