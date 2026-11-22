<?php
declare(strict_types=1);
// Synthetic tenant fixtures only. Expired tick deadlines prevent every provider HTTP request.
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationImportRuns,IntegrationImportSchema,IntegrationVault,InventoryCatalog,InventoryCatalogEnrollment,InventoryCatalogPage,InventoryCatalogQueue,InventoryProgress,InventorySchema,OrderNotificationSchema,RecentOrderSchema};

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);RecentOrderSchema::install($db);IntegrationImportSchema::install($db);
$checks=0;
function checkCatalogEnrollment(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);++$checks;}
function catalogEnrollmentTenant(Database $db,int $company,string $mode='horoz',bool $tracking=true):void{
    $db->run('INSERT INTO companies(id,name) VALUES(?,?)',[$company,'Sentetik müşteri '.$company]);
    $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>$mode,'marketplaceManagement'=>true],'pages'=>['inventory'=>'shown']]],JSON_THROW_ON_ERROR)]);
    if($tracking)$db->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(?,?)',[$company,gmdate('c',time()-600)]);
}
function catalogEnrollmentChannel(Database $db,int $company,string $provider,?bool $selected=true):void{
    $value=['name'=>InventoryCatalogPage::LABELS[$provider],'hidden'=>true];
    if($selected!==null)$value['selected']=$selected;
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'channels',?,?)",[$company,json_encode($value,JSON_THROW_ON_ERROR),gmdate('c',time()-600)]);
}
function catalogEnrollmentOpaqueCredential(Database $db,int $company,string $provider):void{
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'integration_'.$provider,'SYNTHETIC-OPAQUE-ENVELOPE',gmdate('c',time()-600)]);
}
function catalogEnrollmentPrivateState(Database $db):array{
    return [$db->all('SELECT * FROM records ORDER BY id'),$db->all('SELECT * FROM company_profiles ORDER BY company_id'),$db->all('SELECT * FROM inventory_tracking ORDER BY company_id')];
}

$enrollment=new InventoryCatalogEnrollment($db);
foreach(InventoryCatalogPage::SUPPORTED as $index=>$provider){
    $company=100+$index;catalogEnrollmentTenant($db,$company);
    catalogEnrollmentChannel($db,$company,$provider,$provider==='trendyol'?true:null);
    catalogEnrollmentOpaqueCredential($db,$company,$provider);$privateBefore=catalogEnrollmentPrivateState($db);
    checkCatalogEnrollment(!$enrollment->ensure($company,$provider,false),$provider.': devre dışı bağlantı otomatik açılmaz.');
    checkCatalogEnrollment($enrollment->ensure($company,$provider,true),$provider.': etkin müşterinin eksik katalog işi hazırlanır.');
    checkCatalogEnrollment(!$enrollment->ensure($company,$provider,true),$provider.': tekrarlanan hazırlık idempotenttir.');
    checkCatalogEnrollment(catalogEnrollmentPrivateState($db)===$privateBefore,$provider.': API zarfı, profil ve aktivasyon değişmez.');
    checkCatalogEnrollment((int)$db->one('SELECT COUNT(*) n FROM inventory_catalog_jobs WHERE company_id=?',[$company])['n']===1,$provider.': yalnız kendi sağlayıcı görevi oluşturulur.');
}
checkCatalogEnrollment(!$enrollment->ensure(100,'unsupported',true),'Desteklenmeyen sağlayıcı görev oluşturmaz.');
checkCatalogEnrollment(!$enrollment->ensure(987654,'n11',true),'Olmayan müşteriye görev oluşturulmaz.');

// Incomplete prerequisites cannot change another customer's activation or connection state.
foreach(InventoryCatalogPage::SUPPORTED as $index=>$provider){
    foreach(['manual','inactive','unselected','credentials','channel'] as $offset=>$reason){
        $company=1000+$index*10+$offset;
        catalogEnrollmentTenant($db,$company,$reason==='manual'?'store':'horoz',$reason!=='inactive');
        if($reason!=='channel')catalogEnrollmentChannel($db,$company,$provider,$reason==='unselected'?false:true);
        if($reason!=='credentials')catalogEnrollmentOpaqueCredential($db,$company,$provider);
        checkCatalogEnrollment(!$enrollment->ensure($company,$provider,true),$provider.': '.$reason.' koşulunda görev oluşturulmaz.');
        checkCatalogEnrollment(!$db->one('SELECT company_id FROM inventory_catalog_jobs WHERE company_id=?',[$company]),$provider.': '.$reason.' koşulunda başka türetilmiş iş yok.');
    }
}
catalogEnrollmentTenant($db,2000);catalogEnrollmentChannel($db,2000,'trendyol',null);catalogEnrollmentOpaqueCredential($db,2000,'trendyol');
checkCatalogEnrollment(!$enrollment->ensure(2000,'trendyol',true),'Trendyol geçmiş kanal kaydı açık seçim olmadan etkinleştirilmez.');

// Missing companion rows are repaired while successful and in-flight progress stay byte-for-byte intact.
$db->run("UPDATE inventory_catalog_jobs SET page_no=7,partition_no=2,next_run=?,attempts=4,error='Sentetik 429',last_success=? WHERE company_id=101 AND provider='n11'",[time()+900,gmdate('c',time()-120)]);
$db->run("UPDATE inventory_catalog_progress SET state='retry',started_at=123,completed_at=456,pages_done=9,items_done=132,lease_token='n11-existing-lease',lease_until=? WHERE company_id=101 AND provider='n11'",[time()+90]);
$job=$db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=101 AND provider='n11'");
$progress=$db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=101 AND provider='n11'");
$otherJobs=$db->all('SELECT * FROM inventory_catalog_jobs WHERE company_id<>101 ORDER BY company_id,provider');
checkCatalogEnrollment(!$enrollment->ensure(101,'n11',true),'Mevcut n11 aktarımı yeniden başlatılmaz.');
checkCatalogEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=101 AND provider='n11'")===$job,'n11 sayfa, partition, bekleme, deneme, hata ve başarı zamanı değişmez.');
checkCatalogEnrollment($db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=101 AND provider='n11'")===$progress,'n11 ilerleme ve aktif lease değişmez.');
$db->run("DELETE FROM inventory_catalog_progress WHERE company_id=101 AND provider='n11'");
checkCatalogEnrollment($enrollment->ensure(101,'n11',true),'Eksik n11 ilerlemesi tamamlanır.');
checkCatalogEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=101 AND provider='n11'")===$job,'Eksik ilerleme onarılırken mevcut n11 işi korunur.');
$db->run("UPDATE inventory_catalog_progress SET state='complete',pages_done=4,items_done=132 WHERE company_id=101 AND provider='n11'");
$progress=$db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=101 AND provider='n11'");
$db->run("DELETE FROM inventory_catalog_jobs WHERE company_id=101 AND provider='n11'");
checkCatalogEnrollment($enrollment->ensure(101,'n11',true),'Eksik n11 işi tamamlanır.');
checkCatalogEnrollment($db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=101 AND provider='n11'")===$progress,'Eksik iş onarılırken tamamlanan n11 ilerlemesi korunur.');
checkCatalogEnrollment($db->all('SELECT * FROM inventory_catalog_jobs WHERE company_id<>101 ORDER BY company_id,provider')===$otherJobs,'Diğer müşterilerin ve sağlayıcıların tüm işleri korunur.');

// Every supported new connection owns its catalog scope within the same transaction.
foreach(InventoryCatalogPage::SUPPORTED as $index=>$provider){
    $company=3000+$index;catalogEnrollmentTenant($db,$company,$index%2===0?'both':'marketplace');
    catalogEnrollmentChannel($db,$company,$provider,$provider==='trendyol'?true:null);catalogEnrollmentOpaqueCredential($db,$company,$provider);
    $privateBefore=catalogEnrollmentPrivateState($db);$db->pdo->beginTransaction();
    $run=(new IntegrationImportRuns($db))->begin($company,$provider,1,'synthetic-revision');
    $row=$db->one('SELECT baseline,report FROM integration_import_runs WHERE run_id=?',[$run]);
    $baseline=json_decode($row['baseline'],true,32,JSON_THROW_ON_ERROR);$report=json_decode($row['report'],true,32,JSON_THROW_ON_ERROR);
    checkCatalogEnrollment($baseline['inventoryEnabled']&&$baseline['catalogSupported'],$provider.': yeni bağlantı raporu katalog kapsamını içerir.');
    checkCatalogEnrollment($report['stages']['products']['status']==='queued'&&$report['stages']['stock']['status']==='queued',$provider.': yeni rapor ürün/stok tamamlanmasını bekler.');
    checkCatalogEnrollment($db->one('SELECT company_id FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider])!==null,$provider.': başlangıçta doğru görev oluşur.');
    $db->pdo->rollBack();
    checkCatalogEnrollment(!$db->one('SELECT company_id FROM inventory_catalog_jobs WHERE company_id=?',[$company]),$provider.': dış işlem geri alınırsa türetilmiş iş de geri alınır.');
    checkCatalogEnrollment(catalogEnrollmentPrivateState($db)===$privateBefore,$provider.': başlangıç raporu özel kayıtları değiştirmez.');
}

// The real queue enrolls all seven providers, including legacy channels, without ignoring its deadline.
$key=tempnam(sys_get_temp_dir(),'omnigo-enrollment-');
try{
    file_put_contents($key,random_bytes(32));$vault=new IntegrationVault($db,$key);
    catalogEnrollmentTenant($db,4000);catalogEnrollmentTenant($db,4001);
    foreach(InventoryCatalogPage::SUPPORTED as $provider){
        catalogEnrollmentChannel($db,4000,$provider,$provider==='trendyol'?true:null);
        $vault->save(4000,['enabled'=>$provider!=='idefix','revision'=>'synthetic-'.$provider],$provider);
    }
    catalogEnrollmentChannel($db,4001,'n11');$vault->save(4001,['enabled'=>true,'revision'=>'other-tenant'],'n11');
    $privateBefore=catalogEnrollmentPrivateState($db);$queue=new InventoryCatalogQueue($db,$vault);
    $queue->tick(4000,time());
    $providers=array_column($db->all('SELECT provider FROM inventory_catalog_jobs WHERE company_id=4000 ORDER BY provider'),'provider');
    $expected=array_values(array_diff(InventoryCatalogPage::SUPPORTED,['idefix']));sort($expected);
    checkCatalogEnrollment($providers===$expected,'Ortak kuyruk yalnız tüm etkin bağlantıların eksik işlerini oluşturur.');
    checkCatalogEnrollment(!$db->one('SELECT company_id FROM inventory_catalog_jobs WHERE company_id=4001'),'Kuyruk diğer müşteriye görev oluşturmaz.');
    checkCatalogEnrollment((int)$db->one('SELECT COUNT(*) n FROM inventory_catalog_jobs WHERE company_id=4000 AND (page_no<>0 OR next_run<>0 OR attempts<>0)')['n']===0,'Süresi dolmuş tick hiçbir kaynak sayfasını çalıştırmaz veya deneme sayısını değiştirmez.');
    checkCatalogEnrollment(catalogEnrollmentPrivateState($db)===$privateBefore,'Ortak kuyruk API, profil ve aktivasyon kayıtlarını değiştirmez.');
    $jobs=$db->all('SELECT * FROM inventory_catalog_jobs WHERE company_id=4000 ORDER BY provider');$queue->tick(4000,time());
    checkCatalogEnrollment($db->all('SELECT * FROM inventory_catalog_jobs WHERE company_id=4000 ORDER BY provider')===$jobs,'Tekrarlanan kuyruk hazırlığı mevcut işleri değiştirmez.');
}finally{if(is_file($key))unlink($key);}

// Historical n11 reports gain the current derived catalog view without reopening runs or notices.
OrderNotificationSchema::install($db);$now=time();$at=gmdate('c',$now-600);
$oldDisabledWarning='Bu pazaryerinin stok aktarımı henüz etkinleştirilmedi. Sipariş aktarımı bağımsız devam eder.';
$catalog=new InventoryCatalog($db);$legacyRuns=[];
foreach([5000,5001,5002] as $index=>$company){
    catalogEnrollmentTenant($db,$company,'horoz',$company!==5002);catalogEnrollmentChannel($db,$company,'n11',null);catalogEnrollmentOpaqueCredential($db,$company,'n11');
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_n11',?,?)",[$company,json_encode(['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now-10)],JSON_THROW_ON_ERROR),gmdate('c',$now-10)]);
    $db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(?,'n11','pending',?)",[$company,$now+60]);
    $runId=str_repeat((string)($index+5),32);
    $historical=['runId'=>$runId,'provider'=>'n11','providerLabel'=>'n11','state'=>'complete','startedAt'=>$at,'updatedAt'=>$at,'finishedAt'=>$at,
        'counts'=>['products'=>null,'stockRecords'=>null,'orders'=>7+$index,'returns'=>$index],'countsScope'=>'persisted_snapshot','error'=>null,'nextRetryAt'=>null,
        'stages'=>['connection'=>['status'=>'complete'],'orders'=>['status'=>'complete'],'products'=>['status'=>'not_enabled'],'stock'=>['status'=>'not_enabled']],
        'warnings'=>[$oldDisabledWarning,'Sentetik başka kapsam uyarısı korunmalı.']];
    $payload=json_encode(['importReport'=>$historical,'sentinel'=>'historical-notification-'.$company],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $db->run("INSERT INTO order_notifications(company_id,provider,order_key,created,event_type,payload) VALUES(?,'n11',?,?,'operation',?)",[$company,hash('sha256','synthetic-import-'.$company),$at,$payload]);
    $noticeId=(int)$db->insertId();
    $db->run("INSERT INTO integration_import_runs(run_id,company_id,provider,credential_version,credential_revision,state,started_at,updated_at,finished_at,baseline,report,success_notice_id) VALUES(?,?,'n11',1,'synthetic-old-n11-revision','complete',?,?,?,?,?,?)",[
        $runId,$company,$at,$at,$at,json_encode(['inventoryEnabled'=>false,'catalogSupported'=>$company!==5001],JSON_THROW_ON_ERROR),json_encode($historical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$noticeId]);
    $legacyRuns[$company]=['runId'=>$runId,'historical'=>$historical,'noticeId'=>$noticeId];
    if($company!==5002)(new InventoryProgress($db))->ensure($company,'n11');
}
$catalog->ingest(5001,'n11',['id'=>'OTHER-TENANT-N11','sku'=>'OTHER-TENANT-N11','title'=>'Diğer müşteriye özel ürün','quantity'=>3,'saleActive'=>true]);
$db->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,unknown_quantities=0 WHERE company_id=5001 AND provider='n11'",[$now-30,$now-10]);
$db->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=5001 AND provider='n11'",[gmdate('c',$now-10)]);
$historicalRows=$db->all('SELECT * FROM integration_import_runs WHERE company_id>=5000 ORDER BY company_id,provider');
$noticesBefore=$db->all('SELECT * FROM order_notifications ORDER BY id');$privateBefore=catalogEnrollmentPrivateState($db);
$reports=(new IntegrationImportRuns($db))->listing(5000);$shown=$reports[0];
checkCatalogEnrollment(count($reports)===1&&$shown['runId']===$legacyRuns[5000]['runId'],'n11 eski raporu ve canlı snapshot yalnız tek kart üretir.');
checkCatalogEnrollment($shown['stages']['products']['status']==='queued'&&$shown['stages']['stock']['status']==='queued','n11 eski not_enabled aşamaları gerçek yeni katalog kuyruğunu izler.');
checkCatalogEnrollment($shown['state']==='running'&&$shown['finishedAt']===null,'n11 katalog kuyruğu beklerken tüm aktarım tamamlandı gösterilmez.');
checkCatalogEnrollment(!in_array($oldDisabledWarning,$shown['warnings'],true),'Aktif n11 aktarımında eski stok aktarımı kapalı uyarısı temizlenir.');
checkCatalogEnrollment(in_array('Sentetik başka kapsam uyarısı korunmalı.',$shown['warnings'],true),'İlgisiz tarihsel uyarılar korunur.');
checkCatalogEnrollment($shown['counts']['orders']===7&&$shown['counts']['returns']===0&&$shown['stages']['orders']['status']==='complete','Tarihsel n11 sipariş/iade sayaçları ve aşaması değişmez.');
checkCatalogEnrollment($shown['noticeId']===$legacyRuns[5000]['noticeId'],'n11 tarihsel başarı bildiriminin kimliği korunur.');
checkCatalogEnrollment($shown['currentCatalogSummary']['products']===0,'Başka müşterinin n11 katalog ürünleri ilk müşteriye sızmaz.');
$other=(new IntegrationImportRuns($db))->listing(5001);
checkCatalogEnrollment(count($other)===1&&$other[0]['runId']===$legacyRuns[5001]['runId'],'İkinci müşterinin aynı sağlayıcı raporu da tekilleştirilir.');
checkCatalogEnrollment($other[0]['stages']['stock']['status']==='complete'&&$other[0]['counts']['products']===1&&$other[0]['currentCatalogSummary']['products']===1,'İkinci müşterinin tamamlanmış katalog sayaçları kendi verisinden gelir.');
checkCatalogEnrollment($other[0]['currentCatalogSummary']['sourceState']==='verified'&&$other[0]['currentCatalogSummary']['saleActiveProducts']===1,'İkinci müşterinin doğrulanmış satış durumu kendi güncel kaynağına dayanır.');
checkCatalogEnrollment($other[0]['noticeId']===$legacyRuns[5001]['noticeId']&&$other[0]['counts']['orders']===8,'İkinci müşterinin bildirim kimliği ve eski sipariş sayısı korunur.');
$inactive=(new IntegrationImportRuns($db))->listing(5002)[0];
checkCatalogEnrollment($inactive['stages']['stock']['status']==='not_enabled'&&in_array($oldDisabledWarning,$inactive['warnings'],true),'Gerçekte etkinleştirilmemiş müşteride kapalı stok uyarısı korunur.');

foreach(['N11-A','N11-B','N11-C'] as $index=>$sku)$catalog->ingest(5000,'n11',['id'=>$sku,'sku'=>$sku,'title'=>'Sentetik n11 ürün '.$sku,'quantity'=>5+$index,'saleActive'=>$index!==2]);
$db->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,pages_done=1,items_done=3,unknown_quantities=0 WHERE company_id=5000 AND provider='n11'",[$now-20,$now-2]);
$db->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=5000 AND provider='n11'",[gmdate('c',$now-2)]);
$reports=(new IntegrationImportRuns($db))->listing(5000);$shown=$reports[0];
checkCatalogEnrollment(count($reports)===1&&$shown['stages']['products']['status']==='complete'&&$shown['stages']['stock']['status']==='complete','n11 gerçek katalog tamamlanınca aynı karttaki iki aşama tamamlanır.');
checkCatalogEnrollment($shown['state']==='complete'&&$shown['counts']['products']===3&&$shown['counts']['stockRecords']===3,'n11 eski rapor güncel gerçek ürün/stok sayaçlarını gösterir.');
checkCatalogEnrollment($shown['currentCatalogSummary']['products']===3&&$shown['currentCatalogSummary']['saleActiveProducts']===2&&$shown['currentCatalogSummary']['saleInactiveProducts']===1,'n11 güncel katalog özeti müşteri/sağlayıcı kapsamını korur.');
checkCatalogEnrollment($shown['runId']===$legacyRuns[5000]['runId']&&$shown['noticeId']===$legacyRuns[5000]['noticeId'],'Tamamlanmış n11 görünümü yeni rapor veya bildirim kimliği üretmez.');
checkCatalogEnrollment(!in_array($oldDisabledWarning,$shown['warnings'],true),'Tamamlanmış n11 aktarımı eski kapalı uyarısını yeniden göstermez.');
checkCatalogEnrollment($db->all('SELECT * FROM integration_import_runs WHERE company_id>=5000 ORDER BY company_id,provider')===$historicalRows,'Listeleme tüm tarihsel rapor, baseline, zaman, sayaç ve bildirim alanlarını byte-for-byte korur.');
checkCatalogEnrollment($db->all('SELECT * FROM order_notifications ORDER BY id')===$noticesBefore,'Listeleme tarihsel bildirim içeriğini değiştirmez ve yinelenen bildirim oluşturmaz.');
checkCatalogEnrollment(catalogEnrollmentPrivateState($db)===$privateBefore,'n11 canlı rapor güncellemesi API zarfları, profil ve tracking kayıtlarını değiştirmez.');
$other=(new IntegrationImportRuns($db))->listing(5001)[0];
checkCatalogEnrollment($other['counts']['products']===1&&$other['currentCatalogSummary']['products']===1,'İlk müşterinin yeni ürünleri ikinci müşterinin raporuna karışmaz.');

echo $checks." ortak katalog görevi, müşteri ayrımı ve yeni bağlantı kontrolü geçti.\n";
