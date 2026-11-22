<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationVault,MarketplaceConnection,MarketplaceReadClient,IdefixOrderMapper,IdefixSyncAdapter,DeliverySchema,DeliveryCipher,DeliveryRepository,OrderDetails,OrderList,ShipmentSchema,ShipmentOrderIngestor,NotificationSchema,OrderNotifications,SyncFailure,SyncQueue,HttpError};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
function shipment(int $package=10,int $line=100,string $number='100_2'):array{
 return ['id'=>$package,'vendorId'=>123,'orderNumber'=>$number,'orderDate'=>gmdate('c',time()-60),'createdAt'=>gmdate('c',time()-60),'status'=>'shipment_in_cargo','statusDescription'=>'Kargoda','customerContactName'=>'Sipariş Sahibi','customerContactMail'=>'customer@test.invalid','discountedTotalPrice'=>'123.45','cargoCompany'=>'Kargo','cargoTrackingNumber'=>'0000999',
 'shippingAddress'=>['fullName'=>'Alıcı Adı','phone'=>'05000000001','fullAddress'=>'Teslimat sokağı','city'=>'Ankara','county'=>'Çankaya'],
 'invoiceAddress'=>['isCommercial'=>'true','company'=>'Örnek Ticaret','fullName'=>'Fatura Adı','fullAddress'=>'Fatura caddesi','taxOffice'=>'Örnek','taxNumber'=>'0012345678','identificationNumber'=>'11111111111','birthDate'=>'1990-01-01'],
 'items'=>[['id'=>$line,'currency'=>'TRY','productName'=>'Test Ürünü','merchantSku'=>'S-1','price'=>'150.00','platformDiscount'=>'20.00','vendorDiscount'=>'6.55','totalDiscount'=>'26.55','discountedTotalPrice'=>'123.45','itemStatus'=>'in_delivery']]];
}
$mapped=IdefixOrderMapper::map(shipment());$d=$mapped['delivery']['details'];
check($d['customerName']==='Sipariş Sahibi'&&$d['recipientName']==='Alıcı Adı'&&$d['invoiceName']==='Örnek Ticaret','İsimler kaynak alanlarına göre ayrılır');
check($d['phone']==='05000000001'&&$d['recipientEmail']==='customer@test.invalid'&&$d['taxNumber']==='0012345678'&&str_ends_with($d['invoiceAddress'],'V.D.: Örnek'),'İletişim ve vergi alanları');
check(!str_contains(json_encode($mapped),'11111111111')&&!str_contains(json_encode($mapped),'1990-01-01'),'Gereksiz kimlik/doğum tarihi saklanmaz');
check($mapped['order']['grossCents']===12345&&$mapped['order']['products'][0]['quantity']===1,'Liste servisi bir fiziksel birim/satır döndürür');
check($mapped['order']['products'][0]['pricing']===['originalCents'=>15000,'platformDiscountCents'=>2000,'sellerDiscountCents'=>655,'discountedCents'=>12345,'reportCents'=>12345,'reportBasis'=>'discounted'],'İndirim dökümü kuruş hassasiyetinde, satış ve fatura tutarı değişmeden aktarılır');
foreach(['missing','inconsistent','invalid'] as $case){$optional=shipment();if($case==='missing')unset($optional['items'][0]['platformDiscount']);elseif($case==='inconsistent')$optional['items'][0]['price']='160.00';else $optional['items'][0]['vendorDiscount']='-1';$result=IdefixOrderMapper::map($optional);check(!isset($result['order']['products'][0]['pricing'])&&$result['order']['grossCents']===12345,'Eksik/çelişkili ek bilgi fiyat uydurmaz ve sipariş aktarımını engellemez');}
$split=shipment(12);$split['status']='shipment_split';$packages=[$mapped['order'],IdefixOrderMapper::map(shipment(11,101))['order'],IdefixOrderMapper::map($split)['order']];
$combined=IdefixOrderMapper::combine($packages);check(count($combined)===1&&$combined[0]['grossCents']===24690&&count($combined[0]['products'])===2,'Bölünmüş sipariş tek sipariş, kaynak paket çift sayılmaz');
$cancel=shipment();$cancel['status']='shipment_cancelled';$cancelled=IdefixOrderMapper::map($cancel);check($cancelled['order']['cancelCents']===12345&&$cancelled['delivery']['shipmentItems'][0]['payload']['marketplaceCancellation']['cancelledQuantity']===1,'İptal tutarı ve sevkiyat koruması');
foreach(['amount','duplicate','unknown'] as $case){$bad=shipment();if($case==='amount')$bad['discountedTotalPrice']='123.46';elseif($case==='duplicate')$bad['items'][]=$bad['items'][0];else $bad['status']='unrecognized';try{IdefixOrderMapper::map($bad);throw new RuntimeException('Geçersiz kaynak kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===422,'Kaynak tutarlılığı');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Yönetici','x','admin',NULL)");
$key=tempnam(sys_get_temp_dir(),'idefix-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new MarketplaceConnection($db,$vault,'idefix');$connection->save(1,['version'=>0,'vendorId'=>'123','apiKey'=>'test','apiSecret'=>'secret']);
 ShipmentOrderIngestor::activate($db,1,time()-600);$cipher=new DeliveryCipher($key);$deliveries=new DeliveryRepository($db,$cipher);$details=new OrderDetails($db,$cipher);$admin=['id'=>1,'role'=>'admin'];
 $phase=1;$calls=[];$reader=new MarketplaceReadClient(function($url)use(&$phase,&$calls){parse_str(parse_url($url,PHP_URL_QUERY),$query);$calls[]=$query;check($query['vendor']==='123'&&$query['limit']==='20','Firma ve sayfalama sorgusu');if($phase===429)return ['status'=>429,'retryAfter'=>180];$page=(int)$query['page'];$items=$page===1?[shipment(10,100)]:[shipment(11,101)];if($phase===422&&$page===2)$items[0]['discountedTotalPrice']='999';return ['status'=>200,'body'=>json_encode(['items'=>$items,'currentPage'=>$page,'pageCount'=>2])];});
 // The fixture contains two pages of the same scan, not a new set for each 14-day window.
 $db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_idefix'",[json_encode(['phase'=>'pending','startDate'=>gmdate('c',time()-3600)])]);
 $returnMode='empty';
 $returns=new \Omnigo\IdefixReturnClient(new \Omnigo\QuestionReadTransport(function()use(&$returnMode){
  if($returnMode==='error')return ['status'=>429,'retryAfter'=>180];
  $items=$returnMode==='approved'?[['orderNumber'=>'100_2','items'=>[['id'=>91,'orderLineId'=>100,'state'=>'approved','discountedTotalPrice'=>'123.45']]]]:[];
  return ['status'=>200,'body'=>json_encode(['items'=>$items,'totalCount'=>count($items),'pageCount'=>count($items),'currentPage'=>1])];
 }));
 $adapter=new IdefixSyncAdapter($db,$vault,$deliveries,$reader,$returns);$adapter->syncReadOnly(1,time()+30);
 check($db->one("SELECT COUNT(*) n FROM records WHERE kind='order_idefix'")['n']===0,'İlk sayfa sipariş listesini erken yayımlamaz');
 $adapter->syncReadOnly(1,time()+30);
 while(isset($connection->status(1)['sync']['scan']))$adapter->syncReadOnly(1,time()+30);
 $record=$db->one("SELECT * FROM records WHERE kind='order_idefix'");$id=(int)$record['id'];
 check($connection->status(1)['sync']['ordersPublished']===true&&$connection->status(1)['sync']['orderCount']===1,'Tam liste yayımlandı');
 $detail=$details->detail($admin,1,$id);check(count($detail['packages'])===2&&$detail['grossCents']===24690&&$detail['refundsVerified']===true,'Paket ayrıntısı ve tamamlanan iade kontrolü');
 $date=(new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Istanbul'));
 $list=(new OrderList($db,$details))->listing($admin,1,['provider'=>'idefix','from'=>$date->modify('-1 hour')->format('Y-m-d'),'to'=>$date->format('Y-m-d')]);
 check($list['total']===1&&$list['items'][0]['phones']===['05000000001'],'Liste ve müşteri bilgileri');
 check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'İki ürün kendi sevkiyat satırına aktarılır');
 $reference=json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_idefix'")['payload'],true)['reconciliationReference'];
 check(count($reference['orders'])===1&&(new \Omnigo\ReconciliationCompare($db,$cipher))->inspect(1,'idefix',$reference)['total']===0,'Bölünmüş paketler mükerrer sayılmaz; idefix kaynak mutabakatı');
 check(!str_contains(json_encode($db->all('SELECT payload FROM records')),'Sipariş Sahibi')&&!str_contains(json_encode($db->all('SELECT payload FROM delivery_orders')),'05000000001'),'PII yalnızca şifreli depoda');
 check($db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='order'")['n']===0,'Geçmiş aktarım yeni sipariş bildirimi yağdırmaz');
 $notifications=new OrderNotifications($db);$notifications->observe(1,[...$mapped['delivery'],'createdAt'=>gmdate('c')]);$notifications->observe(1,[...$mapped['delivery'],'createdAt'=>gmdate('c')]);check($db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='order'")['n']===1,'Yeni idefix siparişi bir bildirim');
 foreach([[['role'=>'client'],1,403],[$admin,2,404]] as [$user,$company,$code]){try{$details->detail($user,$company,$id);throw new RuntimeException('Erişim engellenmedi');}catch(HttpError $e){check($e->getCode()===$code,'Rol/firma sınırı');}}
 $returnMode='error';$adapter->syncReadOnly(1,time()+30);$adapter->syncReadOnly(1,time()+30);
 try{$adapter->syncReadOnly(1,time()+30);throw new RuntimeException('Eksik iade taraması yayımlandı');}catch(SyncFailure $e){check($e->httpStatus===429&&$e->retryAfter===180,'İade kuyruğu hız sınırını korur');}
 check($db->one('SELECT payload FROM records WHERE id=?',[$id])['payload']===$record['payload'],'İade servisi hata verirse son tam mali kayıt korunur');
 $returnMode='approved';$adapter->syncReadOnly(1,time()+30);
 check(json_decode($db->one('SELECT payload FROM records WHERE id=?',[$id])['payload'],true)['returnCents']===12345,'İade tamamlandığında siparişe atomik işlenir');
 $adapter->syncReadOnly(1,time()+30);while(isset($connection->status(1)['sync']['scan']))$adapter->syncReadOnly(1,time()+30);
 $record=$db->one('SELECT * FROM records WHERE id=?',[$id]);check(json_decode($record['payload'],true)['returnCents']===12345,'Tekrar tarama iadeyi iki kez düşmez');
 $phase=422;$adapter->syncReadOnly(1,time()+30);try{$adapter->syncReadOnly(1,time()+30);throw new RuntimeException('Bozuk sayfa kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===422,'Bozuk sayfa durduruldu');}
 check($db->one('SELECT payload FROM records WHERE id=?',[$id])['payload']===$record['payload'],'Eksik/bozuk tarama son tam listeyi bozmaz');
 $phase=1;$adapter->syncReadOnly(1,time()+30);$adapter->syncReadOnly(1,time()+30);check((int)$db->one("SELECT id FROM records WHERE kind='order_idefix'")['id']===$id&&$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'Yeniden aktarım kimliği korur ve satır çoğaltmaz');
 $start=$connection->status(1)['sync']['startDate'];$connection->retry(1);check($connection->status(1)['sync']['startDate']===$start&&$connection->status(1)['sync']['refundsVerified']===true,'Yeniden kontrol başlangıç kapsamını ve son iade kontrolünü korur');
 $phase=429;$db->run("UPDATE jobs SET next_run=0 WHERE provider='idefix'");$now=time();(new SyncQueue($db,['idefix'=>$adapter]))->runOne($now);$job=$connection->status(1)['job'];check($job['state']==='pending'&&(int)$job['next_run']>=$now+180,'429 kuyruk gecikmesi korunur');
 echo "idefix: alan eşleme, paket birleştirme, tutar kontrolü, şifreleme, firma/yetki, tekilleştirme, bildirim ve kuyruk testleri geçti.\n";
}finally{unlink($key);}
