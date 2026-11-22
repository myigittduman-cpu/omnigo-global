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
$db->run("INSERT INTO inbox_jobs(id,company_id,kind,user_id,payload,state,next_run,created) VALUES('koctas-fixture',?,'scan',1,'{}','done',?,?)",[$one,$now,$stamp]);
$koctas=$service->channel($one,'koctas',[],[],true,true)['questions'];
check($koctas['status']==='inbox_open'&&$koctas['coverage']==='koctas_inbox_open'&&$koctas['lastSuccessAt']===$stamp&&$koctas['nextRunAt']===null,'Koçtaş sekmesi açıldığında okuma; son başarılı kontrol');
$other=$service->channel($two,'koctas',[],[],true,true)['questions'];
check($other['status']==='inbox_open'&&$other['lastSuccessAt']===null,'Başka firmanın Koçtaş okuması gösterilmez');
$next=$now+120;
$db->run("INSERT INTO inbox_jobs(id,company_id,kind,user_id,payload,state,next_run,created) VALUES('koctas-active',?,'scan',1,'{}','pending',?,?)",[$one,$next,gmdate('c',$now+1)]);
foreach ([['pending',0,'scan_queued'],['pending',1,'retry'],['running',1,'running'],['failed',2,'error']] as [$state,$attempts,$expected]){
    $db->run("UPDATE inbox_jobs SET state=?,attempts=? WHERE id='koctas-active'",[$state,$attempts]);
    $current=$service->channel($one,'koctas',[],[],true,true)['questions'];
    check($current['status']===$expected&&$current['lastSuccessAt']===$stamp,'Koçtaş taramasının güncel durumu ve son başarılı okuma');
    check($current['nextRunAt']===($state==='pending'?gmdate('c',$next):null),'Koçtaş yalnız gerçek bir sonraki kuyruk zamanını gösterir');
}
check($service->channel($one,'koctas',[],[],false,true)['questions']['status']==='not_configured','Bağlanmamış Koçtaş');
check($service->channel($one,'koctas',[],[],true,false)['questions']['status']==='inactive','Pasif Koçtaş');
$db->run('INSERT OR REPLACE INTO company_profiles(company_id,payload) VALUES(?,?)',[$two,json_encode(['setupOptions'=>['live'=>[],'manual'=>['questionReply']]],JSON_THROW_ON_ERROR)]);
check($service->channel($two,'koctas',[],[],true,true)['questions']['status']==='inactive','Manuel cevap açık olsa bile canlı aktarımı seçilmeyen mağaza pasif');
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