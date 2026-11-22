<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,ShipmentOrderIngestor,IdefixOrderMapper,IntegrationAlertMonitor,IntegrationRecoveryEvidence,IntegrationHealthSnapshot,MailQueue,MobilePushDevices,PushDevices,PushWorker,SyncQueue,SyncFailure,FixedCadenceReadAdapter,ReconciliationReference};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$now=time();$db->run("INSERT INTO companies VALUES(1,'Gizli Firma')");
$db->run("INSERT INTO users VALUES(1,'admin@test.invalid','Yönetici','hash','admin',NULL),(2,'client@test.invalid','Müşteri','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
foreach([1=>'admin',2=>'client'] as $id=>$role){$token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',$now+3600]);$user=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];$mobile->save($user,['kind'=>'android','appId'=>'com.omnigoglobal.'.$role,'installationId'=>str_repeat((string)$id,64),'token'=>str_repeat('x',140),'environment'=>'production','sound'=>true]);}
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?),(1,'integration_idefix','{}',?)",[json_encode(['name'=>'idefix','status'=>'active']),gmdate('c'),gmdate('c')]);
$db->run("INSERT INTO jobs(company_id,provider,next_run) VALUES(1,'idefix',0)");
$monitor=new IntegrationAlertMonitor($db,new MailQueue($db,[]),fn($event)=>$devices->enqueue($event),new IntegrationRecoveryEvidence($db,$cipher));
$broken=new class implements FixedCadenceReadAdapter{public function nextDelay():int{return 60;}public function syncReadOnly(int $company,int $deadline):void{throw new SyncFailure(503);}};
$queue=new SyncQueue($db,['idefix'=>$broken],fn(int $company)=>$monitor->check($company,$now));$queue->runOne($now);
$count=fn()=>(int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='integration'")['n'];
$incident=fn()=>json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='integration_health'")['payload'],true)['idefix'];
check($count()===0&&!empty($incident()['open']),'Kesinti yalnız bağlantı durumunda izlenir');$monitor->run($now);check($count()===0,'Tekrarlanan kontrol bildirim oluşturmaz');
$db->run("UPDATE jobs SET state='pending',attempts=0,next_run=?",[$now+60]);
$state=['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now+1)];
$save=function(array $s)use($db){$row=$db->one("SELECT id FROM records WHERE kind='sync_idefix'");if($row)$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($s),$row['id']]);else $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_idefix',?,?)",[json_encode($s),gmdate('c')]);};
$save($state);$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'API başarısı tek başına tamamlandı bildirimi üretmemeli');
$mapped=IdefixOrderMapper::map(['id'=>10,'orderNumber'=>'PRIVATE-ORDER','orderDate'=>gmdate('c',$now-3600),'status'=>'created','customerContactName'=>'Gizli Alıcı','discountedTotalPrice'=>'123.45','shippingAddress'=>['fullName'=>'Gizli Alıcı','phone'=>'05000000000','city'=>'Ankara','county'=>'Çankaya','fullAddress'=>'Gizli adres'],'items'=>[['id'=>100,'currency'=>'TRY','productName'=>'Gizli ürün','discountedTotalPrice'=>'123.45']]]);
ShipmentOrderIngestor::activate($db,1,$now-7200);ReconciliationReference::page($state,[$mapped['delivery']],true);ReconciliationReference::complete($state,[$mapped['order']],gmdate('c',$now-7200),gmdate('c',$now+1));$state['reconciliationReference']['capturedAt']=gmdate('c',$now+1);
$state['recentOrders']=['scan'=>['page'=>2],'watermark'=>$now-500];$save($state);$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'Yarım kalan hızlı sayfa varken düzelme bildirimi gitmemeli');
unset($state['recentOrders']['scan']);$state['recentOrders']['watermark']=$now+1;$save($state);$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'Eksik sipariş tamamlanmadan düzelme bildirimi gitmemeli');
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_idefix',?,?)",[json_encode($mapped['order']),gmdate('c')]);$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'Eksik evrak satırı varken düzelme bildirimi gitmemeli');
$deliveries=new DeliveryRepository($db,$cipher);$delivery=$mapped['delivery'];unset($delivery['shipmentItems']);$db->transaction(fn()=>$deliveries->ingest(1,$delivery,false));$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'Eksik sevkiyat satırı varken düzelme bildirimi gitmemeli');
$db->transaction(fn()=>$deliveries->ingest(1,$mapped['delivery'],false));
$row=$db->one('SELECT * FROM shipment_entries');$payload=$cipher->open(1,'shipment:'.$row['id'],$row['payload']);$wrong=$payload;$wrong['invoiceCents']++;
$db->run('UPDATE shipment_entries SET payload=?,updated=? WHERE id=?',[$cipher->seal(1,'shipment:'.$row['id'],$wrong),gmdate('c',$now-1),$row['id']]);$monitor->run($now+2);check($count()===0&&!empty($incident()['open']),'Tutar farkı varken düzelme bildirimi gitmemeli');
check((new IntegrationHealthSnapshot($db))->badges(1)['idefix']['status']==='warning','Tablo doğrulaması bitmeden sağlıklı rozeti verilmemeli');
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$row['id'],$payload),$row['id']]);$monitor->run($now+2);$monitor->run($now+3);check($count()===0&&empty($incident()['open'])&&!empty($incident()['recoveredAt']),'Doğrulanmış tamamlama bağlantı durumunu bildirimsiz kapatır');

$sent=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$sent){$sent[]=['app'=>$subscription['appId'],'message'=>$message];return ['success'=>true,'expired'=>false,'status'=>200];});while($worker->runOne()){}
$alerts=array_values(array_filter($sent,fn($s)=>str_contains($s['message']['title'],'Bağlantı')));
check($alerts===[]&&$sent===[],'Bağlantı sorunu ve düzelmesi mobil cihaza gönderilmemeli');
check($count()===0,'Kesinti ve toparlanma bildirim penceresine eklenmez');
$feed=(new \Omnigo\OrderNotifications($db))->arrivals(0,true,true,true);
check($feed['items']===[],'Uygulama içi akışta bağlantı veya düzelme bildirimi olmaz');
// Notification infrastructure failures cannot stop or rewrite a source retry.
$db->run("UPDATE jobs SET next_run=0,attempts=0");(new SyncQueue($db,['idefix'=>$broken],fn()=>throw new RuntimeException('Bildirim altyapısı kapalı')))->runOne($now);check($db->one('SELECT state FROM jobs')['state']==='pending','Bildirim hatası sipariş tekrarını durdurmamalı');
// The sales stream must not inherit the order stream's success timestamp.
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?),(1,'integration_hepsiburada','{}',?),(1,'sync_hepsiburada',?,?)",[json_encode(['name'=>'Hepsiburada','status'=>'active']),gmdate('c'),gmdate('c'),json_encode(['phase'=>'live','lastSuccessAt'=>gmdate('c',$now),'financial'=>['lastSuccessAt'=>gmdate('c',$now-600),'scan'=>['offset'=>10],'error'=>['code'=>503]]]),gmdate('c')]);
$db->run("INSERT INTO jobs(company_id,provider,next_run) VALUES(1,'Hepsiburada',?),(1,'Hepsiburada Satış',?)",[$now+60,$now+60]);$snapshot=(new IntegrationHealthSnapshot($db))->company(1,$now)['hepsiburada'];
check($snapshot['status']==='error'&&$snapshot['jobs']['Hepsiburada Satış']['successAt']===gmdate('c',$now-600)&&!$snapshot['jobs']['Hepsiburada Satış']['complete'],'Hepsiburada mali tarama hatası kendi kanıtıyla izlenmeli');
echo "Bağlantı durumu: bildirimsiz kesinti ve toparlanma, yarım sayfa/eksik sipariş/evrak/sevkiyat/tutar engeli, hata izolasyonu ve Hepsiburada bağımsız başarı kontrolü geçti.\n";
