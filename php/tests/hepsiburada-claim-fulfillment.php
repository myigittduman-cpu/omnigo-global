<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,DeliveryCipher,DeliveryRepository,IntegrationVault,HepsiburadaReadClient,HepsiburadaOrderMapper,HepsiburadaOrderAdapter,HepsiburadaSalesSync,HepsiburadaClaims,HepsiburadaClaimProjection,ShipmentOrderIngestor,DeliverySelection,SyncFailure,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function invalid(callable $fn,int $code):void{try{$fn();}catch(SyncFailure $e){check($e->httpStatus===$code,'Beklenen kaynak hata kodu');return;}throw new RuntimeException('Geçersiz kanıt kabul edilmemeli.');}
$merchant='12345678-1234-1234-1234-123456789012';$number='9912345678';$date=gmdate('c',time()-300);$refundDate=gmdate('c',time()-120);
$line=['id'=>'line-1','merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Örnek ürün','merchantSKU'=>'SKU-1','quantity'=>1,'totalPrice'=>['currency'=>'TRY','amount'=>'6134.10'],'status'=>'Packaged','lastStatusUpdateDate'=>$date,
 'shippingAddress'=>['name'=>'Test Alıcı','phoneNumber'=>'05000000000','address'=>'Sentetik teslimat adresi','city'=>'Ankara','town'=>'Çankaya','countryCode'=>'TR'],'customerName'=>'Test Müşteri','invoice'=>['address'=>['name'=>'Test Fatura']]];
$raw=['orderNumber'=>$number,'orderDate'=>$date,'items'=>[$line]];
$refund=['number'=>'123456789','orderNumber'=>$number,'lineItemId'=>'line-1','quantity'=>1,'orderDate'=>$date,'status'=>'Refunded','claimType'=>'UndeliveredProduct','refundAmount'=>'6134.10','refundCurrency'=>'TRY','refundDate'=>$refundDate,'finalizedWith'=>''];
$claim=HepsiburadaClaims::map($refund,'Refunded',$merchant);$base=HepsiburadaOrderMapper::map($raw,$merchant,$number);
foreach(['Accepted','AwaitingAction','Rejected','Cancelled'] as $status){
 $pending=HepsiburadaClaims::map([...$refund,'status'=>$status],$status,$merchant);$state=[];HepsiburadaClaimProjection::remember($state,[$pending]);
 check(!$state&&HepsiburadaClaimProjection::apply($base,[$pending])===$base,'Sonuçlanmamış/reddedilmiş talep iptal değildir: '.$status);
}
$ordinary=[...$claim,'claimType'=>'Return'];check(HepsiburadaClaimProjection::apply($base,[$ordinary])===$base,'Normal ürün iadesi satıcı iptaline dönüştürülmez');
$projected=HepsiburadaClaimProjection::apply($base,[$claim,$claim]);
check($projected['order']===$base['order'],'Finans kaydı değiştirilmez');
check($projected['deliveries'][0]['cancellation']['cancelledQuantity']===1,'Tekrarlanan talep çift sayılmaz');
invalid(fn()=>HepsiburadaClaimProjection::apply($base,[[...$claim,'quantity'=>2]]),422);
invalid(fn()=>HepsiburadaClaimProjection::apply($base,[[...$claim,'amountCents'=>613411]]),422);
invalid(fn()=>HepsiburadaClaimProjection::apply($base,[[...$claim,'lineId'=>'other-line']]),422);
invalid(fn()=>HepsiburadaClaimProjection::apply($base,[[...$claim,'orderedAt'=>gmdate('c',time()-600)]]),422);
check(HepsiburadaClaimProjection::apply($base,[[...$claim,'orderNumber'=>'9999999999']])===$base,'Başka siparişin talebi uygulanmaz');
$mixed=HepsiburadaOrderMapper::map([...$raw,'items'=>[$line,[...$line,'id'=>'line-2']]],$merchant,$number);
$mixed=HepsiburadaClaimProjection::apply($mixed,[$claim]);
check(!$mixed['deliveries'][0]['active']&&$mixed['deliveries'][1]['active'],'Aynı siparişte yalnız iptal edilen ürün çıkarılır');
$partial=HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'quantity'=>2]]],$merchant,$number);
$partial=HepsiburadaClaimProjection::apply($partial,[[...$claim,'amountCents'=>300000]]);
check($partial['deliveries'][0]['active']&&$partial['deliveries'][0]['products'][0]['quantity']===1&&$partial['deliveries'][0]['cancellation']['partial'],'Kısmi iptalde kalan adet korunur');
foreach(['CancelledByMerchant','CancelledByCustomer','CancelledBySap'] as $status){
 $cancelled=HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'status'=>$status]]],$merchant,$number);
 $cancelled=HepsiburadaClaimProjection::apply($cancelled,[$claim]);
 check($cancelled['deliveries'][0]['cancellation']['cancelledQuantity']===1,'İptali yapan aktör sonucu değiştirmez: '.$status);
 check(HepsiburadaClaims::apply($cancelled['order'],[$claim])['returnCents']===0,'Doğrudan iptal ile geri ödeme iki kez düşülmez');
}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$key=tempnam(sys_get_temp_dir(),'hb-claim-');file_put_contents($key,random_bytes(32));
try{
 $cipher=new DeliveryCipher($key);$repo=new DeliveryRepository($db,$cipher);$vault=new IntegrationVault($db,$key);ShipmentOrderIngestor::activate($db,1,time()-86400);
 $from=(new DateTimeImmutable('first day of this month',new DateTimeZone('Europe/Istanbul')))->setTime(0,0)->format(DATE_ATOM);
 $config=['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'synthetic','userAgent'=>'test_dev','enabled'=>true,'ordersEnabled'=>true,'salesEnabled'=>true,'salesStartDate'=>$from,'notifyFrom'=>time(),'revision'=>'one'];$vault->save(1,$config,'hepsiburada');
 $rate=false;$reader=new HepsiburadaReadClient(function($url)use(&$raw,&$rate,$refund,$merchant){
  if(str_contains($url,'/claims/'))return ['status'=>200,'body'=>json_encode(str_contains($url,'/status/Refunded?')?[$refund]:[])];
  if($rate&&str_contains($url,'/ordernumber/'))return ['status'=>429,'retryAfter'=>180];
  $body=str_contains($url,'/ordernumber/')?$raw:(str_contains($url,'/packages/')?[['merchantId'=>$merchant,'orderNumber'=>$raw['orderNumber']]]:[]);
  return ['status'=>200,'body'=>json_encode($body)];
 });
 $orders=new HepsiburadaOrderAdapter($db,$vault,$repo,$reader);$sales=new HepsiburadaSalesSync($db,$vault,$reader);
 $read=fn()=>json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_hepsiburada'")['payload'],true);
 $reset=function()use($db,$read){$s=$read();$s['nextRequestAt']=0;$s['financial']['nextRequestAt']=0;$db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_hepsiburada'",[json_encode($s)]);};
 $orders->syncReadOnly(1,time()+40);$before=$db->one('SELECT * FROM delivery_orders');check(count($repo->listing(1,'all','Hepsiburada','all',0)['items'])===1,'Başlangıçta evrak listesinde');
 $raw['items'][0]['status']='ClaimCreated';$raw['items'][0]['lastStatusUpdateDate']=$refundDate;
 $sales->syncReadOnly(1,time()+40);$queued=$read();check(isset($queued['pendingOrders'][$number],$queued['fulfillmentClaims'][$claim['id']]),'Tamamlanmış talep, liste özeti aynı kalsa da siparişi kalıcı kuyruğa alır');
 $reset();$rate=true;$unchanged=$read();invalid(fn()=>$orders->syncReadOnly(1,time()+40),429);check($read()===$unchanged,'Kesintide kuyruk ve son doğru veri korunur');
 $rate=false;$orders->syncReadOnly(1,time()+40);$row=$db->one('SELECT * FROM shipment_entries');$after=$db->one('SELECT * FROM delivery_orders');
 check($row['row_status']==='cancelled'&&(int)$row['cancelled']===1,'Talep üzerinden satıcı iptali sevkiyatı kırmızı yapar');
 check((int)$after['active']===-1&&!$repo->listing(1,'all','Hepsiburada','all',0)['items'],'İptal edilen ürün aktif evrak listesinden çıkar');
 try{(new DeliverySelection($db,$repo))->snapshot(1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>[['id'=>(int)$after['id'],'version'=>(int)$after['version']]]]);throw new RuntimeException('İptal evrakı üretildi.');}catch(HttpError $e){check($e->getCode()===409,'Güncel sürümle bile iptal edilen ürün için evrak üretilemez');}
 $reset();$sales->syncReadOnly(1,time()+40);$state=$read();check(empty($state['pendingOrders'])&&!isset($state['financial']['scan']),'Aynı talep sonsuz döngü veya tekrar kuyruk oluşturmaz');
 $sale=json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sales'")['payload'],true);check($sale['grossCents']===613410&&$sale['cancelCents']===0&&$sale['returnCents']===613410,'Geri ödeme yalnız bir kez mali düşüm oluşturur');
 $notificationCount=$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n'];check($notificationCount===1,'Gerçek iptal için tek operasyon bildirimi');
 $reset();$orders->syncReadOnly(1,time()+40);$reset();$sales->syncReadOnly(1,time()+40);
 check($db->one('SELECT version FROM shipment_entries')['version']===$row['version'],'Tekrar aktarımlar satırı gereksiz değiştirmez');
 check($db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===$notificationCount,'Mükerrer iptal bildirimi üretilmez');
 check(!$db->one('SELECT id FROM shipment_entries WHERE company_id=2')&&!$db->one("SELECT id FROM records WHERE company_id=2 AND kind='sync_hepsiburada'"),'Diğer firma etkilenmez');
 echo "Hepsiburada talep iptali: kesin sonuç, üç iptal aktörü, ürün/adet izolasyonu, evrak engeli, tek mali düşüm, kalıcı kuyruk, tekrar ve kesinti testleri geçti.\n";
}finally{unlink($key);}
