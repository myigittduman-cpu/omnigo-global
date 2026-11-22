<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,DeliveryCipher,DeliveryRepository,IntegrationVault,RecentOrderReader,RecentOrderWindow,FrequentOrderAdapter,N11ReadClient,ShipmentOrderIngestor,SyncFailure,SyncQueue,FixedCadenceReadAdapter};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$now=time();$old=$now-60*86400;
foreach(['n11','idefix','pazarama'] as $provider){
 $watermark=$old;$count=0;
 do{$window=RecentOrderWindow::next($provider,['watermark'=>$watermark],[],$now);
  check($window['from']<=$watermark&&$window['through']>$watermark,'Kesintide atlanan aralık: '.$provider);
  check($window['through']-$window['from']<14*86400,'Kaynak tarih sınırı: '.$provider);
  $watermark=min($now,$window['through']);$count++;
 }while($watermark<$now&&$count<20);
 check($watermark===$now&&$count>1&&$count<10,'Altmış günlük kesinti eksiksiz tamamlanmalı: '.$provider);
}
$bounded=RecentOrderWindow::next('n11',['watermark'=>$old],['startDate'=>gmdate('c',$old+86400)],$now);
check($bounded['from']===$old+86400,'İzinli aktarım başlangıcından önce okunmamalı');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek firma')");$path=tempnam(sys_get_temp_dir(),'recovery-');file_put_contents($path,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$path);$deliveries=new DeliveryRepository($db,new DeliveryCipher($path));
 $config=['enabled'=>true,'revision'=>'recovery','apiKey'=>'test','apiSecret'=>'test','startDate'=>gmdate('c',$old-86400)];$vault->save(1,$config,'n11');ShipmentOrderIngestor::activate($db,1,$old-86400);
 $state=['recentOrders'=>['watermark'=>$old,'revision'=>'recovery','historyNextAt'=>$now+86400]];
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_n11',?,?)",[json_encode($state),gmdate('c')]);
 $package=static function(int $id)use($old){return ['id'=>$id,'orderNumber'=>(string)(1000+$id),'sellerId'=>123,'shipmentPackageStatus'=>'Created','customerfullName'=>'Örnek Müşteri','shippingAddress'=>['fullName'=>'Örnek Alıcı','city'=>'Ankara','district'=>'Çankaya','address'=>'Örnek adres','gsm'=>'05000000000'],'packageHistories'=>[['status'=>'Created','createdDate'=>($old+60)*1000]],'lines'=>[['orderLineId'=>$id,'productId'=>900,'productName'=>'Örnek ürün','stockCode'=>'S-'.$id,'quantity'=>1,'sellerInvoiceAmount'=>'123.45','orderItemLineItemStatusName'=>'Created']]];};
 $requests=[];$fail=true;
 $reader=new RecentOrderReader(n11:new N11ReadClient(function($url)use(&$requests,&$fail,$package){parse_str(parse_url($url,PHP_URL_QUERY),$q);$requests[]=$q;$page=(int)$q['page'];if($page===1&&$fail)return ['status'=>503];return ['status'=>200,'body'=>json_encode(['page'=>$page,'totalPages'=>2,'content'=>$page===0?[$package(1)]:[$package(1),$package(2)]])];}));
 $history=new class implements \Omnigo\ScheduledReadAdapter{public function nextDelay():int{return 900;}public function syncReadOnly(int $company,int $deadline):void{throw new RuntimeException('Geçmiş testi çalışmamalı');}};
 $adapter=new FrequentOrderAdapter($db,$vault,'n11',$deliveries,$history,$reader);$adapter->syncReadOnly(1,time()+40);
 $read=fn()=>json_decode($db->one("SELECT payload FROM records WHERE kind='sync_n11'")['payload'],true);
 check($requests[0]['startDate']<=$old*1000,'Eski başarılı aktarım kesilmemeli');
 $s=$read();check($s['recentOrders']['scan']['page']===1&&$s['recentOrders']['watermark']===$old,'Yarım pencere başarı diye işaretlenmemeli');
 $s['recentOrders']['nextRequestAt']=0;$db->run("UPDATE records SET payload=? WHERE kind='sync_n11'",[json_encode($s)]);
 $before=$read();try{$adapter->syncReadOnly(1,time()+40);throw new RuntimeException('Kesinti bekleniyor');}catch(SyncFailure $error){check($error->httpStatus===503,'Kaynak kesintisi');}
 check($read()===$before,'Kesintide sayfa ve filigran değişmemeli');
 $fail=false;$adapter->syncReadOnly(1,time()+40);
 check($requests[1]===$requests[2],'Bağlantı dönünce tam aynı sayfa okunmalı');
 foreach(['recent_orders','delivery_orders','shipment_entries'] as $table)check((int)$db->one('SELECT COUNT(*) n FROM '.$table)['n']===2,'Tekrarlanan sayfa çoğalmadan üç tabloya tamamlanmalı: '.$table);
 $s=$read();check(!isset($s['recentOrders']['scan'])&&$s['recentOrders']['watermark']>$old&&$s['recentOrders']['watermark']<time()-30*86400,'Kısmi geçmiş bitince bugüne atlanmamalı');
 foreach([408,409,425,500,503,522] as $status){
  $outage=new class($status) implements FixedCadenceReadAdapter{public bool $available=false;public function __construct(private int $status){}public function nextDelay():int{return 60;}public function syncReadOnly(int $company,int $deadline):void{if(!$this->available)throw new SyncFailure($this->status);}};
  $db->run('DELETE FROM jobs');$db->run("INSERT INTO jobs(company_id,provider,next_run,attempts) VALUES(1,'n11',?,99)",[$now]);$queue=new SyncQueue($db,['n11'=>$outage]);$retryStarted=time();$queue->runOne($now);$retryFinished=time();$job=$db->one('SELECT * FROM jobs');
  check($job['state']==='pending'&&(int)$job['next_run']>=max($now,$retryStarted)+60&&(int)$job['next_run']<=max($now,$retryFinished)+60,'Dakikalık cron için bir sonraki yeniden deneme: '.$status);
  $outage->available=true;$queue->runOne((int)$job['next_run']);check((int)$db->one('SELECT attempts FROM jobs')['attempts']===0,'Otomatik toparlanma');
 }
 $limited=new class implements FixedCadenceReadAdapter{public function nextDelay():int{return 60;}public function syncReadOnly(int $company,int $deadline):void{throw new SyncFailure(503,7200);}};
 $db->run("UPDATE jobs SET state='pending',next_run=?,attempts=99",[$now]);(new SyncQueue($db,['n11'=>$limited]))->runOne($now);check((int)$db->one('SELECT next_run FROM jobs')['next_run']>=$now+7200,'Kaynağın zorunlu bekleme süresi hiçbir zaman kısaltılmamalı');
 echo "Kesinti kurtarma: 60 günlük boşluksuz tarama, yarım sayfa, aynı sayfadan devam, üç tabloda tekrarsızlık, 1–2 dakikalık yeniden deneme ve Retry-After geçti.\n";
}finally{unlink($path);}
