<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,IntegrationVault,DeliveryCipher,DeliveryRepository,MarketplaceReadClient,PttavmSyncAdapter,SyncQueue,ReconciliationMonitor,ReconciliationPresentation};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode(['name'=>'PTT AVM','status'=>'active']),gmdate('c')]);
$key=tempnam(sys_get_temp_dir(),'ptt-batch-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$cipher=new DeliveryCipher($key);$delivery=new DeliveryRepository($db,$cipher);
 $zone=new DateTimeZone('Europe/Istanbul');$now=new DateTimeImmutable('now',$zone);
 $config=['apiKey'=>'test','accessToken'=>'test','revision'=>'r1','enabled'=>true,'storeName'=>'Test Mağaza','startDate'=>$now->modify('-145 days')->format(DATE_ATOM)];$vault->save(1,$config,'pttavm');
 $requests=[];$failAt=null;$pauseCount=0;
 $reader=new MarketplaceReadClient(function($url)use(&$requests,&$failAt){parse_str(parse_url($url,PHP_URL_QUERY),$q);$requests[]=$q;if(count($requests)===$failAt)return ['status'=>429,'retryAfter'=>180];return ['status'=>200,'body'=>'[]'];});
 $adapter=new PttavmSyncAdapter($db,$vault,$delivery,$reader,function()use(&$pauseCount){$pauseCount++;});
 $state=fn()=>json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_pttavm'")['payload'],true);
 $reset=function()use($db,$state){$s=$state();$s['nextRequestAt']=0;$db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_pttavm'",[json_encode($s)]);$db->run("UPDATE jobs SET next_run=0 WHERE company_id=1 AND provider='PTT AVM'");};
 (new SyncQueue($db))->enqueue(1,'PTT AVM');$queue=new SyncQueue($db,['PTT AVM'=>$adapter]);
 $queue->runOne(null,null,time()+40);$s=$state();
 check(count($requests)===6&&$pauseCount===4&&!isset($s['scan'])&&!isset($s['reconciliationDraft']),'Beş aylık geçmiş tek sınırlı bütçede tamamlanır');
 check(strtotime($requests[0]['startDate'])>strtotime($requests[1]['startDate']),'Güncel siparişler önce okunur');
 $monitor=new ReconciliationMonitor($db,$cipher);$check=$monitor->check(1,'pttavm',false);
 check($check!==null&&$check['state']==='healthy','Kuyruk tamamlanınca gerçek mutabakat yapılabilir');
 $s['reconciliationCheck']=$check;check(ReconciliationPresentation::summary($s)['state']==='healthy','Tamamlanan kontrol bekliyor olarak gizlenmez');
 $reset();$requests=[];$failAt=3;$queue->runOne(null,null,time()+40);$s=$state();$cursor=$s['scan']['cursor'];$oldRef=$s['reconciliationReference'];
 check(count($requests)===3&&isset($s['scan'])&&$monitor->check(1,'pttavm',false)===null,'429 sonrasında başka istek ve sahte başarılı mutabakat yok');
 check((int)$db->one("SELECT next_run FROM jobs WHERE provider='PTT AVM'")['next_run']>=time()+179,'Retry-After korunur');
 $reset();$requests=[];$failAt=null;$queue->runOne(null,null,time()+40);
 check(strtotime($requests[1]['startDate'])===strtotime($cursor)&&!isset($state()['scan']),'Kesintiden sonra kaydedilen tarih aralığından devam');
 $config['revision']='r2';$config['startDate']=$now->modify('-300 days')->format(DATE_ATOM);$vault->save(1,$config,'pttavm');$reset();$requests=[];$queue->runOne(null,null,time()+40);
 check(count($requests)===9&&isset($state()['scan'])&&$monitor->check(1,'pttavm',false)===null,'Çok uzun geçmiş sekiz pencere sınırında kalır; eksik kontrol sağlıklı sayılmaz');
 $reset();$requests=[];$queue->runOne(null,null,time()+10);
 check(count($requests)===1&&isset($state()['scan']),'Süre daralınca güncel aktarım korunur ve geçmiş sonraki işe kalır');
 check(!$db->all("SELECT * FROM records WHERE company_id=2"),'Diğer müşteriye yazılmaz');
 echo "PTT AVM: çok aylı tamamlanmış mutabakat, önce güncel aktarım, 429/süre/sayfa sınırı, kaldığı yerden devam ve firma izolasyonu geçti.\n";
}finally{unlink($key);}
