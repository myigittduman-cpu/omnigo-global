<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentOrderIngestor,DeliverySelection,DeliveryDocuments,ApprovedReturnIndex,ApprovedReturnSync,N11ReturnIdentity,HttpError,ReturnRequestMapper};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function conflict(callable $action):void{try{$action();throw new RuntimeException('Eski evrak reddedilmedi');}catch(HttpError $e){check($e->getCode()===409,'409 bekleniyordu');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@example.invalid','Yönetici','hash','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));$orders=new DeliveryRepository($db,$cipher);$shipments=new ShipmentRepository($db,$cipher);$sync=new ApprovedReturnSync($db,$cipher);
$date=gmdate('c',time()-3600);$month=(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m');ShipmentOrderIngestor::activate($db,1,time()-86400);
$make=static function(string $provider,string $number)use($date){
 $products=[];$items=[];
 foreach(['A'=>3,'B'=>2] as $line=>$quantity){
  $products[]=['lineId'=>$line,'title'=>'Aynı ürün adı','sku'=>'AYNI-SKU','quantity'=>$quantity,'cancelledQuantity'=>0,'refundedQuantity'=>0,'shippingState'=>'pending'];
  $identity=$provider==='Pazarama'?'internal-'.$number:$number;
  $items[]=['key'=>hash('sha256',json_encode([$identity,$line])),'payload'=>['orderedAt'=>$date,'customerName'=>'Aynı müşteri','recipientName'=>'Alıcı','phone'=>'05000000000','deliveryAddress'=>'Örnek adres','province'=>'Ankara','district'=>'Merkez','productName'=>'Aynı ürün adı','quantity'=>$quantity,'invoiceCents'=>$quantity*10000,'marketplaceShipping'=>'pending','marketplaceCancellation'=>['quantity'=>$quantity,'cancelledQuantity'=>0,'partial'=>false]]];
 }
 return ['provider'=>$provider,'externalId'=>$provider==='Pazarama'?'internal-'.$number:$number,'orderNumber'=>$number,'city'=>'Ankara','createdAt'=>$date,'buyerName'=>'Aynı müşteri','phone'=>'05000000000','address'=>'Örnek adres','status'=>'Hazırlanıyor','active'=>true,'products'=>$products,'issues'=>[],'details'=>['recipientName'=>'Alıcı','products'=>$products],'shipmentItems'=>$items];
};
$save=fn($order)=>$db->transaction(fn()=>$orders->ingest(1,$order,false));
$claim=static function(string $key,string $provider,string $number,int $quantity,string $status):array{
 $item=['id'=>'claim-'.$number,'orderNumber'=>$provider==='Pazarama'?'internal-'.$number:$number,'lineId'=>'A','quantity'=>$quantity,'status'=>$status];
 if($key==='n11')$item['orderLineId']='A';if($key==='koctas')$item['compliant']=true;
 return $item;
};
$store=static function(string $key,array $claims)use($db){
 $source=['returnRequests'=>['items'=>$claims,'checkedAt'=>gmdate('c')]];$state=$key==='hepsiburada'?['financial'=>$source]:$source;
 $row=$db->one('SELECT id FROM records WHERE company_id=1 AND kind=?',['sync_'.$key]);
 if($row)$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($state),$row['id']]);
 else $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(1,?,?,?)',['sync_'.$key,json_encode($state),gmdate('c')]);
};
$statuses=['hepsiburada'=>'Accepted','n11'=>'APPROVED','idefix'=>'approved','pazarama'=>'8','koctas'=>'IN_PROGRESS','pttavm'=>'iade'];
foreach(Omnigo\ReturnRequestList::PROVIDERS as $key=>$provider){
 if($key==='trendyol')continue; // Item-status ingestion is exercised end to end in trendyol-orders.php.
 $order=$make($provider,'TEST-'.$key);$other=$make($provider,'OTHER-'.$key);$save($order);$save($other);$scope=['all',$provider,$month];
 $row=$db->one('SELECT * FROM delivery_orders WHERE company_id=1 AND provider=? AND external_id=?',[$provider,$order['externalId']]);
 $doc=(new DeliveryDocuments($db,$cipher,$orders))->create(1,1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'requestId'=>str_pad($key,32,'0'),'orders'=>[['id'=>(int)$row['id'],'version'=>(int)$row['version']]]]);
 $before=array_column($shipments->listing(1,$scope)['items'],null,'id');
 $item=$claim($key,$provider,$order['orderNumber'],1,$statuses[$key]);$store($key,[$item,$item]);
 $result=$sync->batch(1,$provider);check($result['changed']===1,$provider.' yalnız ilgili satır değişir');
 check($sync->batch(1,$provider)['changed']===0,$provider.' tekrar çift düşmez');
 $after=array_column($shipments->listing(1,$scope)['items'],null,'id');$affected=array_values(array_filter($after,fn($p)=>!empty($p['marketplaceReturn'])));
 check(count($affected)===1&&$affected[0]['marketplaceReturn']===['quantity'=>3,'returnedQuantity'=>1,'remainingQuantity'=>2,'partial'=>true],$provider.' tam kimlikle 1/3 iade');
 foreach($after as $id=>$p){check($p['invoiceCents']===$before[$id]['invoiceCents']&&$p['quantity']===$before[$id]['quantity']&&$p['cancelled']===$before[$id]['cancelled'],$provider.' mali/sipariş/iptal verisi aynı');}
 $current=$db->one('SELECT * FROM delivery_orders WHERE id=?',[$row['id']]);$operational=$orders->operational($current);
 check(array_column($operational['products'],'quantity')===[2,2],$provider.' kalan ürün ve adet evrakta kalır');
 check($current['document_id']===null&&(int)$current['version']>(int)$row['version'],$provider.' eski seçim ve evrak geçersiz');
 conflict(fn()=>(new DeliveryDocuments($db,$cipher,$orders))->get(1,$doc['id']));
 conflict(fn()=>(new DeliverySelection($db,$orders))->snapshot(1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>[['id'=>(int)$row['id'],'version'=>(int)$row['version']]]]));
 $snapshot=(new DeliverySelection($db,$orders))->snapshot(1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>[['id'=>(int)$current['id'],'version'=>(int)$current['version']]]]);
 check(array_column($snapshot['orders'][0]['products'],'quantity')===[2,2],$provider.' PDF/Excel seçimi aynı kalan adet');
 $save($order);$sync->batch(1,$provider);check(array_column($orders->operational($db->one('SELECT * FROM delivery_orders WHERE id=?',[$row['id']]))['products'],'quantity')===[2,2],$provider.' eski sipariş sayfası iadeyi geri getirmez');
 $store($key,[[...$item,'quantity'=>3]]);$sync->batch(1,$provider);
 check(array_column($orders->operational($db->one('SELECT * FROM delivery_orders WHERE id=?',[$row['id']]))['products'],'quantity')===[2],$provider.' tam iade yalnız A ürününü çıkarır');
 $full=$claim($key,$provider,$order['orderNumber'],2,$statuses[$key]);$full['id']='B-claim';$full['lineId']='B';if($key==='n11')$full['orderLineId']='B';
 $store($key,[[...$item,'quantity'=>3],$full]);$sync->batch(1,$provider);
 check(!$orders->operational($db->one('SELECT * FROM delivery_orders WHERE id=?',[$row['id']]))['active'],$provider.' tamamı iade aktif evraklardan çıkar');
 check($db->one('SELECT COUNT(*) n FROM audit WHERE company_id=2')['n']===0,'Firma yalıtımı');
}
foreach(['Hepsiburada'=>['NewRequest','Rejected','Cancelled'],'n11'=>['REQUESTED','DENIED','CANCELLED'],'idefix'=>['ready','decline','cancel'],'Pazarama'=>['7','9'],'Koçtaş'=>['WAITING_ACCEPTANCE','REFUSED','CANCELED','CLOSED'],'PTT AVM'=>['kargo_yapilmasi_bekleniyor']] as $provider=>$states){
 foreach($states as $status)check(!(new ApprovedReturnIndex($provider,[['id'=>'x','orderNumber'=>'X','lineId'=>'A','quantity'=>1,'status'=>$status]]))->lines($make($provider,'X'))['lines'],$provider.' '.$status.' onaylı iade değil');
}
$base=$make('Hepsiburada','EDGE');$approved=['id'=>'r','orderNumber'=>'EDGE','lineId'=>'A','status'=>'Accepted','quantity'=>1];
check(!(new ApprovedReturnIndex('Hepsiburada',[$approved,[...$approved,'quantity'=>2]]))->lines($base)['lines'],'Çelişkili mükerrer işlenmez');
check((new ApprovedReturnIndex('Hepsiburada',[[...$approved,'quantity'=>4]]))->lines($base)['issues']!==[],'Fazla adet reddedilir');
check(!(new ApprovedReturnIndex('Hepsiburada',[[...$approved,'status'=>'Refunded','claimType'=>'UndeliveredProduct']]))->lines($base)['lines'],'Teslim edilemeyen iptal ayrıca iade düşülmez');
$base['details']['products'][0]['refundedQuantity']=1;check((new ApprovedReturnIndex('Hepsiburada',[$approved]))->lines($base)['lines']['A']['returnedQuantity']===1,'Onay ve geri ödeme aynı adet için iki kez sayılmaz');
$n11=['claimReturnId'=>'C','orderNumber'=>'N','productId'=>'P','quantity'=>1,'status'=>'APPROVED','amountCents'=>100];
$packages=[['orderNumber'=>'N','status'=>'Delivered','products'=>[['productId'=>'P','lineId'=>'A']]]];
check(N11ReturnIdentity::requests([$n11],$packages)[0]['orderLineId']==='A','n11 ürün-satır kimliği tam taramada çözülür');
$packages[0]['products'][]=['productId'=>'P','lineId'=>'B'];check(!isset(N11ReturnIdentity::requests([$n11],$packages)[0]['orderLineId']),'n11 belirsiz aynı ürün iki satıra uygulanmaz');
check(!(new ApprovedReturnIndex('Pazarama',[['id'=>'p','orderNumber'=>'internal-P','lineId'=>'A','status'=>'8','quantity'=>null]]))->lines($make('Pazarama','P'))['lines'],'Pazarama bilinmeyen kısmi adet tahmin edilmez');
foreach(['PTT AVM','Koçtaş'] as $provider){
 $order=$make($provider,'SOURCE');$order['details']['products'][0]['refundedQuantity']=1;
 $result=(new ApprovedReturnIndex($provider,[]))->lines($order);check($result['lines']['A']['remainingQuantity']===2,$provider.' sipariş satırının kesin iade adedi');
}
$single=$make('Pazarama','SINGLE');$single['details']['products'][0]['quantity']=1;$single['details']['products'][0]['status']='8';
check((new ApprovedReturnIndex('Pazarama',[]))->lines($single)['lines']['A']['returnedQuantity']===1,'Pazarama tek ürün satırında onay kesin');
$single['provider']='idefix';$single['orderNumber']='I';
check((new ApprovedReturnIndex('idefix',[['id'=>'i','orderNumber'=>'I','lineId'=>'A','quantity'=>null,'status'=>'approved']]))->lines($single)['lines']['A']['returnedQuantity']===1,'idefix fiziksel birim satırında onay kesin');
$rt=['id'=>'r','order_id'=>'O','state'=>'CLOSED','date_created'=>gmdate('c'),'last_updated'=>gmdate('c'),'return_lines'=>[['order_line_id'=>'A','quantity'=>1,'compliance'=>[['compliant'=>true]]]]];
$mapped=Omnigo\KoctasReturnMapper::page(['data'=>[$rt]])['items'][0];check(ApprovedReturnIndex::approved('Koçtaş',$mapped),'Koçtaş RT11 compliance dizisi olumlu');
$rt['return_lines'][0]['compliance']=[['compliant'=>false]];$mapped=Omnigo\KoctasReturnMapper::page(['data'=>[$rt]])['items'][0];check(!ApprovedReturnIndex::approved('Koçtaş',$mapped),'Koçtaş uygunsuz iade kapanışı onay sayılmaz');
$db->run("UPDATE shipment_entries SET row_status='completed'");
$delivered=new Omnigo\DeliveredShipmentQueue($db,$cipher);
foreach(Omnigo\ReturnRequestList::PROVIDERS as $provider){
 if($provider==='Trendyol')continue;
 check($delivered->count(1,$provider,false)===2,$provider.' tamamen iade satırları teslim edilenler sayacından da çıkar');
 check(count($delivered->listing(1,'all',$provider,0,20,false)['items'])===2,$provider.' teslim edilenler sayfası ve sayacı eşit');
}
// Regression: an old invoice approval may already have marked two shipments as returned.
$originalHb=json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_hepsiburada'")['payload'],true);
$hbClaims=$originalHb['financial']['returnRequests']['items'];$repairRows=[];
foreach(['INVOICE-PENDING','INVOICE-DELIVERED'] as $number){
 $order=$make('Hepsiburada',$number);
 if($number==='INVOICE-DELIVERED'){
  foreach($order['details']['products'] as &$product)$product['shippingState']='delivered';unset($product);
  foreach($order['shipmentItems'] as &$entry)$entry['payload']['marketplaceShipping']='delivered';unset($entry);
 }
 $save($order);$row=$db->one("SELECT * FROM delivery_orders WHERE company_id=1 AND provider='Hepsiburada' AND external_id=?",[$number]);
 $key=Omnigo\DeliveryFulfillment::key($order,$order['details']['products'][0]);
 $entry=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND source_key=?',[$key]);
 $payload=$cipher->open(1,'shipment:'.$entry['id'],$entry['payload']);
 $payload['marketplaceReturn']=['quantity'=>3,'returnedQuantity'=>3,'remainingQuantity'=>0,'partial'=>false];
 $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$entry['id'],$payload),$entry['id']]);
 $hbClaims[]=[...$claim('hepsiburada','Hepsiburada',$number,3,'Accepted'),'claimType'=>'MissingInvoice'];
 $repairRows[]=['order'=>$row,'entry'=>$entry,'payload'=>$payload,'number'=>$number];
}
$store('hepsiburada',$hbClaims);
$untouched=$db->all('SELECT id,payload,version FROM shipment_entries WHERE id NOT IN (?,?) ORDER BY id',array_column(array_column($repairRows,'entry'),'id'));
$otherCompany=$db->all('SELECT * FROM audit WHERE company_id=2');
check($sync->batch(1,'Hepsiburada')['changed']===2,'Yalnız fatura talebinden yanlış etkilenen iki sevkiyat düzelir');
foreach($repairRows as $repair){
 $row=$db->one('SELECT * FROM delivery_orders WHERE id=?',[$repair['order']['id']]);
 $entry=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$repair['entry']['id']]);
 $payload=$cipher->open(1,'shipment:'.$entry['id'],$entry['payload']);
 $expected=$repair['payload'];unset($expected['marketplaceReturn']);
 check($payload===$expected,'Yalnız yanlış iade işareti kaldırılır; tutar/adres/iptal/sevkiyat korunur');
 check($row['payload']===$repair['order']['payload'],'Kaynak sipariş şifreli içeriği değişmez');
 $operational=$orders->operational($row);
 if($repair['number']==='INVOICE-PENDING')check($operational['active']&&array_column($operational['products'],'quantity')===[3,2],'Bekleyen siparişin ürünleri teslimat listesine geri gelir');
 else check(!$operational['active']&&$operational['fulfillmentStatus']==='delivered','Teslim edilmiş sipariş tekrar sevke açılmaz');
 check(!$operational['approvedReturnLines'],'Fatura talebi operasyonel iade değildir');
}
check($untouched===$db->all('SELECT id,payload,version FROM shipment_entries WHERE id NOT IN (?,?) ORDER BY id',array_column(array_column($repairRows,'entry'),'id')),'Gerçek iadeler ve diğer sevkiyatlar değişmez');
check($otherCompany===$db->all('SELECT * FROM audit WHERE company_id=2'),'Başka müşteriye işlem yazılmaz');
check($sync->batch(1,'Hepsiburada')['changed']===0,'İkinci çalıştırma yeni değişiklik yapmaz');
foreach(['Accepted','Refunded','NewRequest'] as $status)check(!ApprovedReturnIndex::approved('Hepsiburada',['status'=>$status,'claimType'=>'MissingInvoice']),'Fatura talebi '.$status.' durumunda iade olmaz');
echo "Onaylanan iadeler: altı kaynak, ürün/adet ve firma yalıtımı, yanlış fatura iadesi onarımı, teslimat koruması ve tekrar testleri geçti.\n";
