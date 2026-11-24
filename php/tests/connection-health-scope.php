<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,ConnectionHealth,HttpError,MarketplaceServiceHealth};
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repository=new Repository($db);$one=$repository->createCompany('Birinci müşteri');$two=$repository->createCompany('İkinci müşteri');
$health=new ConnectionHealth($db);$admin=['id'=>1,'role'=>'admin'];
foreach([$one,$two] as $company){
 $report=$health->report($admin,0,$company);check($report['company']['id']===$company,'Seçili müşteri');
 check(count($report['items'])===6,'Altı mevcut pazaryeri');check(count(array_unique(array_column($report['items'],'companyId')))===1,'Tek müşteri');
 foreach($report['items'] as $row)check($row['companyId']===$company,'Diğer müşteri görünmez');
 check($report['hasMore']===false,'Şirketler arası sayfalama yok');
}
try{$health->report(['id'=>2,'role'=>'client'],0,$one);throw new RuntimeException('Yetki denetimi eksik');}catch(HttpError $e){check($e->getCode()===403,'Müşteri hesabı erişemez');}
try{$health->report($admin,0,999);throw new RuntimeException('Firma denetimi eksik');}catch(HttpError $e){check($e->getCode()===404,'Bilinmeyen firma');}
$service=new MarketplaceServiceHealth($db);
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL)");
$now=time();$stamp=gmdate('c',$now);
// Koçtaş artık koctasQuestions() yerine standart status() yolunu kullanıyor:
// records tablosundaki sync_koctas_questions kaydından lastSuccessAt okunuyor,
// job durumu jobs['Koçtaş Sorular'] parametresiyle geçiliyor.
$db->run("INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,1,?)",
    [$one,'sync_koctas_questions',json_encode(['lastSuccessAt'=>$stamp],JSON_THROW_ON_ERROR),gmdate('c',$now)]);
// Job yok → missing_queue; son başarılı kontrol records'tan okunur
$koctas=$service->channel($one,'koctas',[],[],true,true)['questions'];
check($koctas['status']==='missing_queue'&&$koctas['lastSuccessAt']===$stamp&&$koctas['nextRunAt']===null,'Koçtaş kuyruğu yokken missing_queue; son başarılı kontrol');
$other=$service->channel($two,'koctas',[],[],true,true)['questions'];
check($other['status']==='missing_queue'&&$other['lastSuccessAt']===null,'Başka firmanın Koçtaş kaydı görünmez');
$next=$now+120;
foreach ([['pending',0,'ready'],['pending',1,'retry'],['running',1,'running'],['failed',2,'error']] as [$jstate,$attempts,$expected]){
    $job=['state'=>$jstate,'next_run'=>$next,'attempts'=>$attempts];
    $current=$service->channel($one,'koctas',[],['Koçtaş Sorular'=>$job],true,true)['questions'];
    check($current['status']===$expected&&$current['lastSuccessAt']===$stamp,'Koçtaş taramasının güncel durumu ve son başarılı okuma');
    check($current['nextRunAt']===($jstate==='pending'?gmdate('c',$next):null),'Koçtaş yalnız gerçek bir sonraki kuyruk zamanını gösterir');
}
check($service->channel($one,'koctas',[],[],false,true)['questions']['status']==='not_configured','Bağlanmamış Koçtaş');
check($service->channel($one,'koctas',[],[],true,false)['questions']['status']==='inactive','Pasif Koçtaş');
$jobs=['PTT AVM'=>['state'=>'pending','next_run'=>$now+60,'attempts'=>0]];
$ptt=$service->channel($one,'pttavm',['lastSuccessAt'=>$stamp],$jobs,true,true)['requests'];
check($ptt['status']==='probe'&&$ptt['coverage']==='order_status_returns'&&$ptt['lastSuccessAt']===$stamp,'PTT siparişten gelen iadeler ve son okuma');
$jobs['PTT AVM']['attempts']=1;
check($service->channel($one,'pttavm',['lastSuccessAt'=>$stamp],$jobs,true,true)['requests']['status']==='retry','PTT kaynak hatası tekrar denemede görünür');
echo "Bağlantı kontrolü müşteri ayrımı ve yetki testleri geçti.\n";

$db->run('CREATE TABLE marketplace_price_jobs(company_id INTEGER,provider TEXT,created INTEGER,state TEXT)');
$db->run('CREATE TABLE marketplace_stock_requests(company_id INTEGER,request_id TEXT,created TEXT,state TEXT)');
$read=new ReflectionMethod(ConnectionHealth::class,'latestUpdates');
foreach(['pending'=>null,'succeeded'=>'ready','running'=>'ready','failed'=>'error','queued'=>'error'] as $state=>$expected){
    $db->run('INSERT INTO marketplace_price_jobs VALUES(?,?,?,?)',[$one,'n11',++$now,$state]);
    check(($read->invoke($health,$one)['n11']??null)===$expected,'Kuyrukta son kesin fiyat sonucu: '.$state);
}
$db->run('INSERT INTO marketplace_stock_requests VALUES(?,?,?,?)',[$one,'pilot:n11:fixture',gmdate('c',++$now),'succeeded']);
check($read->invoke($health,$one)['n11']==='ready','Yeni doğrulanmış stok işlemi hatayı kapatır');
check($read->invoke($health,$two)===[],'Kuyruk sonucu müşteri bazında ayrılır');

// An order stream can recover while the same store's question queue is still failing.
$observed=time();
$saveState=static fn(string $kind,array $state)=>$db->run(
    'INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
    [$one,$kind,json_encode($state,JSON_THROW_ON_ERROR),gmdate('c',$observed)]
);
$saveState('integration_pazarama',[]);
$saveState('sync_pazarama',['salesPublished'=>true,'watermark'=>gmdate('c',$observed)]);
$saveState('integration_health',['pazarama'=>[
    'open'=>true,'detectedAt'=>gmdate('c',$observed-45),
    'waitingFor'=>['Pazarama'=>gmdate('c',$observed-60),'Pazarama Sorular'=>null],
]]);
$db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(?,'Pazarama','pending',?,0),(?,'Pazarama Sorular','failed',?,5)",[$one,$observed+60,$one,$observed+60]);
$readPazarama=static function()use($health,$admin,$one):array {
    $report=$health->report($admin,0,$one);
    return array_column($report['items'],null,'channel')['Pazarama'];
};
$item=$readPazarama();
check($item['status']==='ready'&&$item['services']['questions']['status']==='error','Sipariş yeniden başarılı olsa da açık soru arızası kendi durumunda görünür');
$db->run("UPDATE jobs SET state='running' WHERE company_id=? AND provider='Pazarama'",[$one]);
check($readPazarama()['status']==='ready','Bir sonraki arka plan çalışması, son sağlıklı sipariş durumunu değiştirmez');
$db->run("UPDATE jobs SET state='pending' WHERE company_id=? AND provider='Pazarama'",[$one]);
$db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='sync_pazarama'",[json_encode(['salesPublished'=>true,'watermark'=>gmdate('c',$observed-60)],JSON_THROW_ON_ERROR),$one]);
check($readPazarama()['status']==='error','Arızadan önceki başarılı kontrol arızayı kapatmaz');
$db->run("UPDATE jobs SET state='pending',attempts=0 WHERE company_id=? AND provider='Pazarama Sorular'",[$one]);
$saveState('sync_pazarama_questions',['lastSuccessAt'=>gmdate('c',$observed)]);
check($readPazarama()['services']['questions']['status']==='ready','Diğer kapsam açık olsa da doğrulanmış soru aktarımı kendi durumunda iyileşir');
