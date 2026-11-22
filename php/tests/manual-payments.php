<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,PaymentCalendar,PaymentPolicy,Validation,HttpError,ReadOnlyAdapter,SyncQueue};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,'HTTP kodu');return;}throw new RuntimeException('İşlem reddedilmeliydi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
\Omnigo\NotificationSchema::migrate($db);\Omnigo\PushSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek A');$other=$repo->createCompany('Örnek B');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',?)",[$company]);
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>$company];
$data=['channel'=>'Hepsiburada','expectedDate'=>'2026-11-02','expectedCents'=>900000,'status'=>'pending','source'=>'Panelden yönetici girişi','verification'=>'confirmed','verificationSource'=>'Ödeme detayları','periodStart'=>'2026-10-26','periodEnd'=>'2026-11-02','taxBasis'=>'included','details'=>[
 ['section'=>'commission','label'=>'Komisyon kesintisi','amountCents'=>100000,'rateBasisPoints'=>1000],['section'=>'tax','label'=>'KDV','amountCents'=>0,'rateBasisPoints'=>2000],['section'=>'adjustment','label'=>'Düzeltme','amountCents'=>-300,'rateBasisPoints'=>null],['section'=>'service','label'=>'Boş kalem','amountCents'=>null,'rateBasisPoints'=>null]]];
// Match Http::body(): JSON objects inside the details list remain stdClass instances.
$data=(array)json_decode(json_encode($data,JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR);
rejects(fn()=>$repo->save($client,$company,'payments',$data,null),403);
$saved=$repo->save($admin,$company,'payments',$data,null);check(count($saved['details'])===3&&$saved['details'][1]['amountCents']===0,'Boş alan sıfır değildir; oranlar ve işaretli tutar korunur');
foreach(['n11','idefix','PTT AVM','Pazarama','Koçtaş'] as $channel)$repo->save($admin,$company,'payments',[...$data,'channel'=>$channel],null);
foreach([$admin,$client] as $actor){
 check($repo->dashboard($actor,$company,'2026-10')['payments']===[],'Sonraki aya ait ödenmemiş hakediş, boş ödeme tarihiyle mevcut ayı açılmaz hale getirmez');
 check(count($repo->dashboard($actor,$company,'2026-11')['payments'])===6,'Ödenmemiş hakediş kendi ayında görünür');
 check($repo->dashboard($actor,$company,'2026-10',['from'=>'2026-10-01','to'=>'2026-10-31'])['payments']===[],'Tarih aralığında boş ödeme tarihi güvenli dışarıda kalır');
}
$repo->save($admin,$other,'payments',[...$data,'expectedCents'=>99999999],null);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,json_encode([...Validation::record('payments',$data),'integration'=>'hepsiburada','expectedCents'=>99999999]),gmdate('c')]);$archiveId=$db->insertId();
$r=(new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30');$c=(new PaymentCalendar($db))->report($client,$company,'2026-11-01','2026-11-30');
check(count($r['items'])===6&&$r['summary']['confirmedCents']===5400000,'Altı pazaryerinde manuel tutarlar doğru toplanır; eski API ve diğer firma dışarıda');
check($r['items']===$c['items']&&$r['summary']===$c['summary'],'İki portal aynı manuel kayıtları ve toplamları görür');
check($r['archivedItems']===[]&&$c['archivedItems']===[]&&$r['earningsItems']===[],'Hakediş ekranına yalnız elle girilen kayıtlar gönderilir');
check((int)$db->one("SELECT COUNT(*) n FROM records WHERE id=? AND kind='payments'",[$archiveId])['n']===1,'Eski otomatik kaydın aslı korunur');
check($r['items'][0]['calendarDate']==='2026-11-02'&&$r['items'][0]['status']==='pending','Takvim yönetici tarihini kullanır, ödemeyi kendiliğinden tamamlamaz');
rejects(fn()=>$repo->save($client,$company,'payments',[...$data,'version'=>1],$saved['id']),403);
rejects(fn()=>$repo->save($admin,$other,'payments',[...$data,'version'=>1],$saved['id']),404);
rejects(fn()=>$repo->save($admin,$company,'payments',[...$data,'version'=>1],$archiveId),409);
$updated=$repo->save($admin,$company,'payments',[...$data,'version'=>1,'expectedCents'=>850000],$saved['id']);check($updated['version']===2,'Manuel kayıt düzenlenir');
rejects(fn()=>$repo->save($admin,$company,'payments',[...$data,'version'=>1],$saved['id']),409);
check((int)$db->one('SELECT COUNT(*) n FROM audit WHERE user_id=1 AND record_id=?',[$saved['id']])['n']===2,'Ekleyen ve düzenleyen yönetici denetim kaydında');
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,json_encode([...Validation::record('payments',$data),'archivedAt'=>gmdate('c')]),gmdate('c')]);$manualArchiveId=$db->insertId();
check(!in_array($manualArchiveId,array_column($repo->records($company,'payments',$admin),'id'),true),'Arşivlenmiş manuel hakediş aktif listeye karışmaz');
rejects(fn()=>$repo->save($admin,$company,'payments',[...$data,'version'=>1],$manualArchiveId),409);
check((int)$db->one("SELECT COUNT(*) n FROM records WHERE id=? AND kind='payments'",[$manualArchiveId])['n']===1,'Arşivlenmiş kayıt silinmez');
rejects(fn()=>Validation::record('payments',[...$data,'details'=>[['section'=>'tax','label'=>'KDV','rateBasisPoints'=>10001]]]),400);
foreach([['invalid'],[17],(object)['row'=>$data['details'][0]],array_fill(0,41,$data['details'][0]),[(object)['section'=>'tax','label'=>'KDV','amountCents'=>'100']],[(object)['section'=>'tax','label'=>'KDV','rateBasisPoints'=>10001]]] as $invalidDetails){
    rejects(fn()=>Validation::record('payments',[...$data,'details'=>$invalidDetails]),400);
}
check(Validation::record('payments',[...$data,'details'=>[]])['details']===[],'Yalnız net tutar girilen hakediş kaydedilebilir');
rejects(fn()=>Validation::record('payments',[...$data,'periodStart'=>'2026-11-30']),400);
$calls=0;$adapter=new class($calls) implements ReadOnlyAdapter{public function __construct(private int &$calls){}public function syncReadOnly(int $companyId,int $deadline):void{$this->calls++;}};
$queue=new SyncQueue($db,['n11 Hakediş'=>$adapter,'n11'=>$adapter]);$queue->enqueue($company,'n11 Hakediş');check(!$db->one("SELECT id FROM jobs WHERE provider='n11 Hakediş'"),'Yeni otomatik hakediş işi oluşmaz');
$db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(?,'n11 Hakediş','pending',0)",[$company]);$queue->enqueue($company,'n11');$queue->runOne();check($calls===1,'Sipariş işi çalışır');$queue->runOne();check($calls===1,'Eski hakediş işi çağrılmaz');PaymentPolicy::disableAutomaticJobs($db);check($db->one("SELECT state FROM jobs WHERE provider='n11 Hakediş'")['state']==='blocked','Eski finans işi kalıcı olarak devre dışı');
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)",[$company,'integration_n11','{}',gmdate('c')]);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)",[$company,'sync_n11',json_encode(['lastSuccessAt'=>gmdate('c')]),gmdate('c')]);
$db->run("UPDATE jobs SET state='pending',attempts=0,next_run=? WHERE company_id=? AND provider='n11'",[time()+120,$company]);
$snapshot=(new \Omnigo\IntegrationHealthSnapshot($db))->company($company)['n11'];
check($snapshot['status']==='healthy'&&!isset($snapshot['jobs']['n11 Hakediş']),'Durdurulan hakediş görevi bağlantı hatası oluşturmaz');
$incident=['n11'=>['open'=>true,'eventId'=>999,'waitingFor'=>['n11 Hakediş'=>null],'pendingMails'=>[999]]];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)",[$company,'integration_health',json_encode($incident),gmdate('c')]);
\Omnigo\OrderNotificationSchema::install($db);
$before=(int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n'];
(new \Omnigo\IntegrationAlertMonitor($db,new \Omnigo\MailQueue($db,[])))->check($company);
$closed=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$company])['payload'],true)['n11'];
check(!$closed['open']&&$closed['pendingMails']===[]&&$closed['closureReason']==='manual_payments','Eski hakediş sorunu sessizce kapanır');
check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===$before,'Finans görevinin kapanması sahte iyileşme bildirimi üretmez');
$receiptPrior=array_values(array_filter($repo->records($company,'payments',$admin),fn($p)=>$p['id']===$updated['id']))[0];
$receiptPayload=[...$receiptPrior,'status'=>'paid','actualCents'=>849999,'paidDate'=>'2026-10-31','confirmation'=>'Örnek banka ekstresi'];
$receipt=$repo->save($admin,$company,'payments',$receiptPayload,$receiptPrior['id']);
foreach(['expectedCents','expectedDate','details','periodStart','periodEnd','verificationSource','source'] as $key)check($receipt[$key]===$receiptPrior[$key],'Ödeme işlenirken hakediş bilgisi korunur: '.$key);
$reported=(new PaymentCalendar($db))->report($admin,$company,'2026-10-01','2026-10-31');
check($reported['summary']['receivedCents']===0&&$reported['summary']['sentCents']===849999,'Kayıtlı ödeme onaylanana kadar aktarımı bildirilen toplamında kalır');
(new \Omnigo\PaymentApproval($db))->approve([...$admin,'name'=>'Yönetici'],$company,$receipt['id'],['version'=>$receipt['version']]);
$october=(new PaymentCalendar($db))->report($admin,$company,'2026-10-01','2026-10-31');
$octoberClient=(new PaymentCalendar($db))->report($client,$company,'2026-10-01','2026-10-31');
check($october['summary']['receivedCents']===849999&&$october['summary']===$octoberClient['summary'],'Gerçek banka tutarı hesaba geçiş ayında iki portalda aynı görünür');
check($october['items'][0]['calendarDate']==='2026-10-31','Ödeme takvimde gerçek banka tarihine taşınır');
check((new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary']['receivedCents']===0,'Planlanan ayda aynı ödeme ikinci defa toplanmaz');
rejects(fn()=>$repo->save($admin,$company,'payments',$receiptPayload,$receiptPrior['id']),409);
echo "Manuel hakediş ve ödeme: korunmuş kalemler, gerçek banka ayı, iki portal toplamı, yönetici yetkisi, müşteri ayrımı ve sürüm kontrolü geçti.\n";
