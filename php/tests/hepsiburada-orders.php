<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,DeliveryCipher,DeliveryRepository,IntegrationVault,HepsiburadaReadClient,HepsiburadaOrderMapper,HepsiburadaOrderDiscovery,HepsiburadaOrderAdapter,HepsiburadaOrderStore,ShipmentOrderIngestor,OrderList,OrderDetails,SyncFailure,SyncQueue};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();}catch(SyncFailure $e){check($e->httpStatus===$status,'Beklenen hata kodu');return;}throw new RuntimeException('Hata bekleniyordu.');}
$merchant='12345678-1234-1234-1234-123456789012';$number='9912345678';$date=gmdate('c',time()-120);
$address=['name'=>'Örnek Alıcı','phoneNumber'=>'05000000000','email'=>'sample@example.invalid','address'=>'Sentetik teslimat adresi','city'=>'Ankara','town'=>'Çankaya','countryCode'=>'TR'];
$line=['id'=>'test-line-1','merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Örnek ürün','merchantSKU'=>'SKU-1','quantity'=>2,'totalPrice'=>['currency'=>'TRY','amount'=>'300.25'],'status'=>'Packaged','lastStatusUpdateDate'=>$date,'shippingAddress'=>$address,'customerName'=>'Örnek Müşteri','invoice'=>['taxNumber'=>'','address'=>['name'=>'Örnek Fatura','address'=>'Fatura adresi']]];
$raw=['orderNumber'=>$number,'orderDate'=>$date,'items'=>[$line]];
$mapped=HepsiburadaOrderMapper::map($raw,$merchant,$number);
foreach([
 ['fields'=>['merchantSKU'=>'','merchantSku'=>'00120','sku'=>'HB-CODE'],'code'=>'00120'],
 ['fields'=>['merchantSKU'=>'FIRST','merchantSku'=>'SECOND','sku'=>'HB-CODE'],'code'=>''],
 ['fields'=>['merchantSKU'=>null,'sku'=>'HB-CODE'],'code'=>''],
] as $case){
 $source=HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,...$case['fields']]]],$merchant,$number)['deliveries'][0];
 check($source['details']['products'][0]['sellerStockCode']===$case['code']&&$source['shipmentItems'][0]['payload']['sellerStockCode']===$case['code'],
    'Yalnız doğrulanabilen satıcı alanı kullanılır ve baştaki sıfırlar korunur');
}
check($mapped['deliveries'][0]['details']['province']==='Ankara','Kaynak teslimat ili korunur');
$outside=HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'shippingAddress'=>[...$address,'city'=>'İstanbul']]]],$merchant,$number)['deliveries'][0];
check($outside['city']==='İstanbul'&&$outside['details']['province']==='İstanbul','Hepsiburada bilinen ili korur; müşteri teslimat kapsamı ayrı uygulanır');
check(\Omnigo\HepsiburadaMoney::cents('499.995')===50000&&\Omnigo\HepsiburadaMoney::cents('12.344999')===1234,'Kuruş altı toplam bir kez yuvarlanır');
fails(fn()=>\Omnigo\HepsiburadaMoney::cents(-1),422);fails(fn()=>\Omnigo\HepsiburadaMoney::cents(INF),422);
check($mapped['order']['grossCents']===30025,'Kalem tutarı adetle tekrar çarpılmaz');
check($mapped['deliveries'][0]['details']['recipientName']==='Örnek Alıcı'&&$mapped['deliveries'][0]['buyerName']==='Örnek Müşteri','Alıcı ve sipariş sahibi ayrıdır');
check(!str_contains(json_encode($mapped['order']),'05000000000'),'Sipariş indeksi kişisel bilgi taşımaz');
fails(fn()=>HepsiburadaOrderMapper::map([...$raw,'items'=>[...$raw['items'],$line]],$merchant,$number),422);
fails(fn()=>HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'merchantId'=>'other']]],$merchant,$number),422);
fails(fn()=>HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'status'=>'UNRECOGNIZED']]],$merchant,$number),422);
fails(fn()=>HepsiburadaOrderMapper::map([...$raw,'orderDate'=>'2026-02-31T12:00:00'],$merchant,$number),422);
check(HepsiburadaOrderDiscovery::numbers([['MerchantId'=>$merchant,'OrderNumbers'=>[$number,'9912345679']]],$merchant)===[$number,'9912345679'],'Büyük harfli ve çoklu siparişli kargo bildirimi');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$path=tempnam(sys_get_temp_dir(),'hb-orders-');file_put_contents($path,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$path);$cipher=new DeliveryCipher($path);$deliveries=new DeliveryRepository($db,$cipher);ShipmentOrderIngestor::activate($db,1,time()-86400);
 $config=['enabled'=>true,'ordersEnabled'=>true,'notifyFrom'=>time(),'revision'=>'synthetic','merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'synthetic','userAgent'=>'test_dev'];$vault->save(1,$config,'hepsiburada');
 $feed=[['merchantId'=>$merchant,'orderNumber'=>$number,'id'=>'event','status'=>'Packaged']];$calls=[];$rate=false;
 // This package was prepared 48 hours ago: the default API window returns no record.
 $reader=new HepsiburadaReadClient(function($url)use(&$raw,&$feed,&$calls,&$rate,$merchant){$calls[]=$url;if($rate)return ['status'=>429,'retryAfter'=>180];parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);$ready=str_ends_with(parse_url($url,PHP_URL_PATH),$merchant)&&str_contains($url,'/packages/')&&(int)($query['timespan']??24)>=48;$body=str_contains($url,'/ordernumber/')?$raw:($ready?$feed:[]);return ['status'=>200,'body'=>json_encode($body)];});
 $adapter=new HepsiburadaOrderAdapter($db,$vault,$deliveries,$reader);$adapter->syncReadOnly(1,time()+40);
 check($db->one("SELECT COUNT(*) n FROM audit WHERE action='operations.mail'")['n']===0,'İlk geçmiş aktarımı bildirim yağmuru oluşturmaz');
 $scope=['from'=>substr($date,0,7).'-01','to'=>date('Y-m-d')];$orders=new OrderList($db,new OrderDetails($db,$cipher));
 $list=$orders->listing(['role'=>'admin'],1,$scope);check($list['total']===1&&$list['items'][0]['provider']==='Hepsiburada','Siparişler ekranında tek kayıt');
 check(count($deliveries->listing(1,'Ankara','Hepsiburada','all',0)['items'])===1,'Teslimat listesinde tek kayıt');check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===1,'Sevkiyatta tek satır');
 check($orders->listing(['role'=>'admin'],2,$scope)['total']===0,'Firma izolasyonu');check($list['items'][0]['customerNames']===['Örnek Müşteri'],'Şifreli ayrıntı eşleşmesi');
 $reset=function()use($db){$r=$db->one("SELECT id,payload FROM records WHERE kind='sync_hepsiburada'");$s=json_decode($r['payload'],true);$s['nextRequestAt']=0;$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($s),$r['id']]);};
 fails(fn()=>$adapter->syncReadOnly(1,time()+40),429);$reset();$adapter->syncReadOnly(1,time()+40);check(count(array_filter($calls,fn($url)=>str_contains($url,'/ordernumber/')))===1,'Değişmeyen listeler gereksiz detay isteği üretmez');
 $raw['items'][0]['status']='InTransit';$feed[0]['status']='InTransit';$reset();$adapter->syncReadOnly(1,time()+40);
 check($db->one('SELECT row_status FROM shipment_entries')['row_status']==='shipped','Kargoya verilen satır mavi işaretlenir');
 $raw['items'][0]['status']='CancelledByCustomer';$feed[0]['status']='CancelledByCustomer';$reset();$adapter->syncReadOnly(1,time()+40);
 check(!$deliveries->listing(1,'Ankara','Hepsiburada','all',0)['items'],'İptal edilen evrak listesinden çıkar');$list=$orders->listing(['role'=>'admin'],1,$scope);check($list['items'][0]['cancelCents']===30025&&$list['items'][0]['cancellation']['cancelledQuantity']===2,'İptal tutarı ve kırmızı durum');
 $shipment=$db->one('SELECT id,payload FROM shipment_entries');$payload=$cipher->open(1,'shipment:'.$shipment['id'],$shipment['payload']);check($payload['marketplaceCancellation']['cancelledQuantity']===2,'Sevkiyatta iptal bilgisi');
 $count=$db->one("SELECT COUNT(*) n FROM audit WHERE action='operations.mail'")['n'];$reset();$adapter->syncReadOnly(1,time()+40);check($db->one("SELECT COUNT(*) n FROM audit WHERE action='operations.mail'")['n']===$count,'Tekrar iptal bildirimi oluşmaz');
 $raw['items'][0]['status']='Open';$feed[0]['status']='Open';$reset();$adapter->syncReadOnly(1,time()+40);check((new \Omnigo\OrderImportQueue($db))->count(1,'hepsiburada')===1,'Eski sipariş ayrı kuyrukta');check($orders->listing(['role'=>'admin'],1,$scope)['items'][0]['cancelCents']===30025,'Eski veri iptali geri alamaz');
 $retry=$db->one("SELECT id,payload FROM records WHERE kind='order_retry_hepsiburada'");$marker=json_decode($retry['payload'],true);$marker['nextAttemptAt']=0;$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($marker),$retry['id']]);
 $raw['items'][0]['status']='CancelledByCustomer';$reset();$adapter->syncReadOnly(1,time()+40);
 check((new \Omnigo\OrderImportQueue($db))->count(1,'hepsiburada')===0,'Liste özeti değişmese de taze detay yeniden okunup çelişki çözülür');
 $rate=true;$reset();$before=$db->one("SELECT payload FROM records WHERE kind='sync_hepsiburada'")['payload'];fails(fn()=>$adapter->syncReadOnly(1,time()+40),429);check($db->one("SELECT payload FROM records WHERE kind='sync_hepsiburada'")['payload']===$before,'Hatalı sayfa imleci ilerletmez');
 $queue=new SyncQueue($db,['Hepsiburada'=>$adapter]);$queue->enqueue(1,'Hepsiburada');$queue->runOne(null,['Hepsiburada'],time()+40);check($db->one("SELECT next_run FROM jobs WHERE provider='Hepsiburada'")['next_run']>=time()+179,'429 yeniden deneme süresi korunur');
 $newNumber='9912345679';$new=HepsiburadaOrderMapper::map([...$raw,'orderNumber'=>$newNumber,'items'=>[[...$line,'orderNumber'=>$newNumber]]],$merchant,$newNumber);
 $bad=$new;$bad['order']['externalId']='9912345680';$bad['deliveries'][0]['externalId']='';
 $snapshot=$db->one("SELECT id,version,payload FROM records WHERE kind='sync_hepsiburada'");$state=json_decode($snapshot['payload'],true);
 $rowsBefore=$db->one('SELECT COUNT(*) n FROM delivery_orders')['n'];
 fails(fn()=>(new HepsiburadaOrderStore($db,$vault,$deliveries))->commit(1,$config,$snapshot,$state,[$new,$bad]),422);
 check(!$db->one('SELECT id FROM recent_orders WHERE external_id=?',[$newNumber])&&$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===$rowsBefore,'Sayfa hatası sipariş, evrak ve sevkiyat eklemelerini birlikte geri alır');
 $vault->save(1,[...$config,'revision'=>'newer'],'hepsiburada');fails(fn()=>(new HepsiburadaOrderStore($db,$vault,$deliveries))->commit(1,$config,$snapshot,$state,[$new]),409);
 check(!$db->one('SELECT id FROM recent_orders WHERE external_id=?',[$newNumber]),'Bağlantı değişirken eski görev yazamaz');
 $delivered=HepsiburadaOrderMapper::map([...$raw,'items'=>[[...$line,'status'=>'Delivered']]],$merchant,$number);
 check(!$delivered['deliveries'][0]['active']&&$delivered['order']['cancelCents']===0,'Teslim edildi durumu iptal sayılmaz ve evrak üretmez');
 foreach(['Delivered','ClaimCreated','Undelivered'] as $i=>$sourceStatus){
  $historicalNumber=(string)(9912345800+$i);$historical=HepsiburadaOrderMapper::map([...$raw,'orderNumber'=>$historicalNumber,'items'=>[[...$line,'orderNumber'=>$historicalNumber,'status'=>$sourceStatus]]],$merchant,$historicalNumber);
  check(count($historical['deliveries'][0]['shipmentItems'])===1,'Kesinti sırasında ilerleyen durum sevkiyat satırını dışlamaz: '.$sourceStatus);
  $db->transaction(fn()=>$deliveries->ingest(1,$historical['deliveries'][0],false));$rows=$db->one('SELECT COUNT(*) n FROM shipment_entries')['n'];
  $db->transaction(fn()=>$deliveries->ingest(1,$historical['deliveries'][0],false));check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===$rows,'Tekrarlı tarihçe aktarımı mükerrer üretmez');
  check(!$historical['deliveries'][0]['active']&&$historical['order']['cancelCents']===0,'Kontrol/teslim durumu iptal sayılmaz veya teslimat evrağı üretmez');
 }
 echo "Hepsiburada aktarımı: üç bölüm, şifreleme, şirket izolasyonu, tekilleştirme, kargo rengi, iptal evrakı, bildirim tekrarı, eski veri ve kuyruk kontrolleri geçti.\n";
}finally{unlink($path);}

