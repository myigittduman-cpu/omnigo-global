<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,ShipmentOrderIngestor,DeliveryCipher,DeliveryRepository,DeliveryMapper,OrderDetailMapper,ShipmentOrderMapper,IdefixOrderMapper,ShipmentRepository,OrderDetails,OrderList,DeliverySelection,HttpError,OrderCancellation};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'test@example.invalid','Yönetici','x','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('c',32));$repo=new DeliveryRepository($db,$cipher);$ship=new ShipmentRepository($db,$cipher);$details=new OrderDetails($db,$cipher);
ShipmentOrderIngestor::activate($db,1,time()-3600);ShipmentOrderIngestor::activate($db,2,time()-3600);
$created=gmdate('c',time()-60);$date=(new DateTimeImmutable($created))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');$month=substr($date,0,7);
$sources=[
 'n11'=>['orderNumber'=>'N1','id'=>1,'customerfullName'=>'Müşteri','shippingAddress'=>['fullName'=>'Alıcı','gsm'=>'05000000001','address'=>'Teslimat','city'=>'Ankara','district'=>'Çankaya'],'packageHistories'=>[['status'=>'Created','createdDate'=>(time()-60)*1000]],'shipmentPackageStatus'=>'Picking','lines'=>[['orderLineId'=>'L1','orderItemLineItemStatusName'=>'Picking','productName'=>'Ürün','quantity'=>2,'sellerInvoiceAmount'=>'100.00']]],
 'pazarama'=>['orderId'=>'P1','orderNumber'=>'P1','orderDate'=>$created,'customerName'=>'Müşteri','shipmentAddress'=>['nameSurname'=>'Alıcı','phoneNumber'=>'05000000001','displayAddressText'=>'Teslimat','cityName'=>'Ankara','districtName'=>'Çankaya'],'items'=>[['orderItemId'=>'L1','orderItemStatus'=>12,'product'=>['name'=>'Ürün'],'quantity'=>2,'totalPrice'=>['currency'=>'TRY','value'=>'100.00']]]],
 'koctas'=>['order_id'=>'K1','order_state'=>'SHIPPING','created_date'=>$created,'customer'=>['firstname'=>'Müşteri','shipping_address'=>['firstname'=>'Alıcı','phone'=>'05000000001','street_1'=>'Teslimat','state'=>'Ankara','city'=>'Çankaya']],'order_lines'=>[['order_line_id'=>'L1','order_line_state'=>'SHIPPING','product_title'=>'Ürün','quantity'=>2,'price'=>'100.00']]],
 'idefix'=>['id'=>1,'orderNumber'=>'I1','orderDate'=>$created,'status'=>'shipment_ready','customerContactName'=>'Müşteri','discountedTotalPrice'=>'100.00','shippingAddress'=>['fullName'=>'Alıcı','phone'=>'05000000001','fullAddress'=>'Teslimat','city'=>'Ankara','county'=>'Çankaya'],'invoiceAddress'=>[],'items'=>[['id'=>1,'itemStatus'=>'created','currency'=>'TRY','productName'=>'Ürün','discountedTotalPrice'=>'100.00']]],
];
$map=static function(string $provider,array $raw):array{if($provider==='idefix')return IdefixOrderMapper::map($raw)['delivery'];$value=DeliveryMapper::$provider($raw);return [...$value,'details'=>OrderDetailMapper::$provider($raw,$value),'shipmentItems'=>ShipmentOrderMapper::$provider($raw,$value)];};
foreach($sources as $provider=>$raw){
 $initial=$map($provider,$raw);$save=fn($value)=>$db->transaction(fn()=>$repo->ingest(1,$value));$save($initial);$db->transaction(fn()=>$repo->ingest(2,$initial));
 $scope=['all',$initial['provider'],$month];$entry=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND provider=?',[$initial['provider']]);
 $payload=$cipher->open(1,'shipment:'.$entry['id'],$entry['payload']);$payload['phone']='05559999999';$payload['source']['locked']=['phone'];$payload['documents']=['invoice'=>['file'=>'saved-invoice']];
 $db->run('UPDATE shipment_entries SET payload=?,row_status=? WHERE id=?',[$cipher->seal(1,'shipment:'.$entry['id'],$payload),'invoiced',$entry['id']]);
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_'.$provider,json_encode(['externalId'=>$initial['orderNumber'],'createdAt'=>$created,'orders'=>1,'grossCents'=>10000,'cancelCents'=>0,'returnCents'=>0,'state'=>'Aktarıldı','products'=>[['title'=>'Ürün','quantity'=>2]]]),gmdate('c')]);
 if($provider==='koctas'){
  $partial=$raw;$partial['order_lines'][0]['cancelations']=[['quantity'=>1,'amount'=>'50.00']];$save($map($provider,$partial));
  $row=$repo->listing(1,'Ankara',$initial['provider'],'all',0)['items'][0];check($row['active']&&$row['products'][0]['quantity']===1&&$row['cancellation']['partial'],'Koçtaş kısmi iptal kalan adedi korur');
  $doc=(new DeliverySelection($db,$repo))->snapshot(1,['city'=>'Ankara','deliveryDate'=>$date,'orders'=>[['id'=>$row['id'],'version'=>$row['version']]]]);check($doc['orders'][0]['products'][0]['quantity']===1,'Belge yalnızca kalan adedi içerir');
 }
 $cancel=$raw;
 if($provider==='n11'){$cancel['shipmentPackageStatus']='Cancelled';$cancel['lines'][0]['orderItemLineItemStatusName']='Cancelled';}
 elseif($provider==='pazarama')$cancel['items'][0]['orderItemStatus']=6;
 elseif($provider==='koctas'){$cancel['order_state']='CANCELED';$cancel['order_lines'][0]['order_line_state']='CANCELED';}
 else $cancel['status']='shipment_cancelled';
 $save($map($provider,$cancel));check($repo->listing(1,'Ankara',$initial['provider'],'all',0)['items']===[],'İptal teslimat listesinden çıkar: '.$provider);$row=$repo->decode($db->one('SELECT * FROM delivery_orders WHERE company_id=1 AND provider=?',[$initial['provider']]));
 check(!$row['active']&&$row['status']==='İptal edildi'&&count($row['cancelledProducts'])===1,'İptal kaydı geçmiş için korunur: '.$provider);
 check($row['products']===[],'İptal edilen ürün belgeye girmez: '.$provider);
 try{(new DeliverySelection($db,$repo))->snapshot(1,['city'=>'Ankara','deliveryDate'=>$date,'orders'=>[['id'=>$row['id'],'version'=>$row['version']]]]);throw new RuntimeException('İptal belgeye eklendi');}catch(HttpError $error){check($error->getCode()===409,'İptal seçimi reddedilir');}
 $shipment=$ship->listing(1,$scope)['items'][0];check($shipment['marketplaceCancellation']['cancelledQuantity']>0&&$shipment['phone']==='05559999999'&&$shipment['documents']['invoice'],'İptal, elle düzenleme ve dosyalar birlikte korunur: '.$provider);
 $list=(new OrderList($db,$details))->listing(['role'=>'admin'],1,['provider'=>$provider,'from'=>$date,'to'=>$date]);check($list['items'][0]['cancellation']['cancelledQuantity']>0&&!$list['items'][0]['cancellation']['partial'],'Finans özeti beklenmeden sipariş listesi iptal gösterir: '.$provider);
 check($ship->listing(2,$scope)['items'][0]['marketplaceCancellation']['cancelledQuantity']===0,'Diğer firmanın siparişi etkilenmez');
 $save($map($provider,$cancel));check((int)$db->one('SELECT version FROM delivery_orders WHERE id=?',[$row['id']])['version']===$row['version'],'Tekrarlanan iptal sürüm artırmaz');
 if($provider==='koctas'){
  $zero=$cancel;$zero['order_lines'][0]['quantity']=0;$zero['order_lines'][0]['price']=0;$zero['order_lines'][0]['cancelations']=[['quantity'=>2,'amount'=>'100.00']];
  $save($map($provider,$zero));
  check((int)$db->one('SELECT version FROM delivery_orders WHERE id=?',[$row['id']])['version']===$row['version'],'İptal satırının sıfırlanması yeni iptal veya evrak değişikliği oluşturmaz');
  check($ship->listing(1,$scope)['items'][0]['marketplaceCancellation']['cancelledQuantity']===2,'Sıfır adetli iptal mevcut sevkiyat satırında korunur');
 }
 check($ship->listing(1,$scope)['total']===1,'İptal ek satır çoğaltmaz');
}
check(OrderCancellation::line('Pazarama',['quantity'=>1,'orderItemStatus'=>8])['cancelledQuantity']===0,'İade iptal sayılmaz');
check(OrderCancellation::line('idefix',[],['status'=>'shipment_split'])['cancelledQuantity']===0,'Paket bölünmesi iptal sayılmaz');
$partial=$sources['koctas'];$partial['order_id']='K-PARTIAL';$partial['order_lines'][0]['cancelations']=[['quantity'=>1,'amount'=>'50.00']];
$db->transaction(fn()=>$repo->ingest(1,$map('koctas',$partial)));check($ship->listing(1,['all','Koçtaş',$month])['total']===2,'İlk aktarımı kısmen iptal edilmiş siparişin kalan adedi atlanmaz');
foreach(['n11','pazarama','idefix'] as $provider){
 $raw=$sources[$provider];$field=$provider==='n11'?'lines':'items';$line=$raw[$field][0];
 if($provider==='n11'){$line['orderLineId']='L2';$raw[$field][0]['orderItemLineItemStatusName']='Cancelled';}
 elseif($provider==='pazarama'){$line['orderItemId']='L2';$raw[$field][0]['orderItemStatus']=6;}
 else{$line['id']=2;$raw[$field][0]['itemStatus']='cancelled';$raw['discountedTotalPrice']='200.00';}
 $raw[$field][]=$line;$value=$map($provider,$raw);
 check($value['active']&&$value['cancellation']['partial']&&count($value['products'])===1,'Farklı ürünlerden yalnızca iptal edileni çıkar: '.$provider);
 check(count($value['shipmentItems'])===2&&$value['shipmentItems'][1]['payload']['marketplaceCancellation']['cancelledQuantity']===0,'Diğer ürünün satırına iptal uygulanmaz: '.$provider);
}
echo "Dört pazaryeri: üç ekranda iptal, kısmi adet, belge koruması, müşteri ayrımı, tekrar koruması ve elle girilen bilgiler doğrulandı.\n";
