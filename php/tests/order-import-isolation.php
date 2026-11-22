<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,RecentOrderSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,DeliveryMapper,OrderDetailMapper,ShipmentOrderMapper,ShipmentOrderIngestor,N11SalesMapper,RecentOrderStore,IntegrationVault,OrderImportQueue,SyncFailure,OrderSnapshotConflict,IntegrationRecoveryEvidence};
function check(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme'),(2,'Diğer')");
$key=tempnam(sys_get_temp_dir(),'isolation-');file_put_contents($key,random_bytes(32));
try{
 $cipher=new DeliveryCipher($key);$repo=new DeliveryRepository($db,$cipher);$vault=new IntegrationVault($db,$key);$queue=new OrderImportQueue($db);
 ShipmentOrderIngestor::activate($db,1,time()-86400);
 $config=['enabled'=>true,'revision'=>'isolated'];$vault->save(1,$config,'n11');
 $raw=static function(int $id,string $status):array{return ['id'=>$id,'orderNumber'=>(string)(1000+$id),'sellerId'=>123,'shipmentPackageStatus'=>$status,'customerfullName'=>'Test müşteri','shippingAddress'=>['city'=>'Ankara','district'=>'Çankaya','fullName'=>'Test alıcı','address'=>'Gizli adres','gsm'=>'05000000000'],'packageHistories'=>[['status'=>'Created','createdDate'=>(time()-3600)*1000]],'lines'=>[['orderLineId'=>$id,'productId'=>900,'stockCode'=>'SKU','productName'=>'Ürün','quantity'=>1,'sellerInvoiceAmount'=>'100.00','orderItemLineItemStatusName'=>$status]]];};
 $map=static function($r){$d=DeliveryMapper::n11($r);$d['details']=OrderDetailMapper::n11($r,$d);$d['shipmentItems']=ShipmentOrderMapper::n11($r,$d);return ['unit'=>N11SalesMapper::package($r),'delivery'=>$d];};
 $store=new RecentOrderStore($db,$repo);
 $commit=function(array $items)use($db,$store,$config,$vault){$before=$db->one("SELECT id,version,payload FROM records WHERE company_id=1 AND kind='sync_n11'");$store->commit(1,'n11',$config,$before,['recentOrders'=>['watermark'=>time(),'lastSuccessAt'=>gmdate('c')]],$items,$vault);};
 $commit([$map($raw(1,'Created'))]);$commit([$map($raw(1,'Cancelled'))]);
 for($i=0;$i<2;$i++)$commit([$map($raw(2,'Created')),$map($raw(1,'Created')),$map($raw(3,'Created'))]);
 foreach(['recent_orders','delivery_orders','shipment_entries'] as $table)check((int)$db->one('SELECT COUNT(*) n FROM '.$table)['n']===3,'Yeni siparişler eksiksiz/tekrarsız: '.$table);
 check($queue->count(1,'n11')===1&&$queue->count(2,'n11')===0,'Tek, firma kapsamlı yeniden kontrol kaydı');
 check($queue->pending(1,'n11')[0]['attempts']===2,'Kalıcı yeniden kontrol sayacı');
 $order=json_decode($db->one("SELECT payload FROM recent_orders WHERE external_id='1001'")['payload'],true);
 check($order['cancelCents']===10000,'Eski görüntü sipariş tutarını geri alamaz');
 check((int)$db->one("SELECT active FROM delivery_orders WHERE external_id LIKE '1001:%'")['active']===-1,'İptal evrak listesine dönmez');
 check(count($repo->listing(1,'Ankara','n11','all',0)['items'])===2,'Yeni iki evrak görünür');
 check(!(new IntegrationRecoveryEvidence($db,$cipher))->ready(1,'n11',[],time()),'Bekleyen kayıt varken tam toparlanma denmez');
 $markers=json_encode($queue->pending(1,'n11'));check(!str_contains($markers,'Gizli adres')&&!str_contains($markers,'05000000000'),'Kuyruk kişisel veri içermez');
 $commit([$map($raw(1,'Cancelled'))]);check($queue->count(1,'n11')===0,'Doğru kaynak geldiğinde kuyruk temizlenir');
 // Every provider uses the same savepoint semantics; DB/network failures are never swallowed.
 foreach(['n11','pazarama','idefix','hepsiburada','koctas'] as $provider){
  $db->transaction(function()use($queue,$db,$provider){
   check(!$queue->attempt(1,$provider,'test',function()use($db){$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'must_rollback','{}','')");throw new OrderSnapshotConflict();}),'Yalnız sorunlu sipariş ayrılır');
   check(!$db->one("SELECT id FROM records WHERE kind='must_rollback'"),'Yarım yazım yok');
   check($queue->attempt(1,$provider,'valid',fn()=>null),'Diğer sipariş devam eder');
  });
  try{$db->transaction(fn()=>$queue->attempt(1,$provider,'system',fn()=>throw new SyncFailure(429,180)));throw new RuntimeException('429 yutuldu');}catch(SyncFailure $e){check($e->retryAfter===180,'Kaynak hız sınırı korunur');}
  check($queue->count(1,$provider)===1,'Sistem hatası sipariş hatası sayılmaz');
  $db->transaction(fn()=>$queue->attempt(1,$provider,'test',fn()=>null));check($queue->count(1,$provider)===0,'Başarılı kayıt temizlenir');
 }
 // A historical page must import healthy operational records without publishing stale totals.
 $history=new \Omnigo\N11SnapshotStore($db,$vault,$repo);
 $snapshot=$history->state(1);$state=json_decode($snapshot['payload'],true);
 $state['scan']=['from'=>gmdate('c',time()-86400),'through'=>gmdate('c')];
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sales',?,?)",[json_encode(['channel'=>'n11','month'=>date('Y-m'),'grossCents'=>10000,'cancelCents'=>10000,'returnCents'=>0,'orders'=>1,'integration'=>'n11']),gmdate('c')]);
 $salesBefore=$db->all("SELECT * FROM records WHERE kind='sales'");$items=[$map($raw(1,'Created')),$map($raw(6,'Created'))];
 $history->commit(1,$snapshot,$config,$state,['type'=>'orders','items'=>array_column($items,'unit')],true,true,array_column($items,'delivery'));
 check($db->one("SELECT id FROM delivery_orders WHERE external_id LIKE '1006:%'")!==null,'Tarihçe içindeki geçerli evrak beklemez');
 check($db->all("SELECT * FROM records WHERE kind='sales'")===$salesBefore,'Çelişkili finans snapshotu yayımlanmaz');
 $held=json_decode($history->state(1)['payload'],true);check(!empty($held['financialSnapshotHeld'])&&!isset($held['scan']),'Sonraki kaynak taraması ayrı yeniden başlar');
 check(!$db->one("SELECT id FROM records WHERE kind='stage_n11'"),'Hatalı tarihçe bir sonraki taramaya karışmaz');
 $commit([$map($raw(1,'Cancelled'))]);
 $snapshot=$history->state(1);$state=json_decode($snapshot['payload'],true);$state['scan']=['from'=>gmdate('c',time()-86400),'through'=>gmdate('c')];
 $items=[$map($raw(1,'Cancelled')),$map($raw(6,'Created'))];
 $history->commit(1,$snapshot,$config,$state,['type'=>'orders','items'=>array_column($items,'unit')],true,true,array_column($items,'delivery'));
 check(empty(json_decode($history->state(1)['payload'],true)['financialSnapshotHeld']),'Doğru kaynakla finans yayımı yeniden açılır');
 $before=$db->all('SELECT * FROM recent_orders');$bad=$map($raw(5,'Created'));$bad['delivery']['externalId']='';
 try{$commit([$map($raw(4,'Created')),$bad]);throw new RuntimeException('Geçersiz sayfa reddedilmedi');}catch(SyncFailure $e){check($e->httpStatus===422,'Beklenmeyen doğrulama hatası açıkça reddedilir');}
 check($before===$db->all('SELECT * FROM recent_orders'),'Sistem/doğrulama hatası atomik kalır');
 echo "Sipariş yalıtımı: eski iptal + iki yeni sipariş, üç tablo, tekrar, firma sınırı, yeniden kontrol, sistem hatası ve iptal koruması geçti.\n";
}finally{unlink($key);}

