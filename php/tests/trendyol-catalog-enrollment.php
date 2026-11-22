<?php
declare(strict_types=1);
// Synthetic SQLite fixtures only; no provider request or production credential.
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationImportRuns,IntegrationImportSchema,InventoryCatalog,InventoryCatalogPage,InventoryMarketplaceFilter,InventoryProgress,InventoryRead,InventorySchema,RecentOrderSchema,TrendyolCatalogEnrollment};

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);RecentOrderSchema::install($db);IntegrationImportSchema::install($db);
$checks=0;
function verifyEnrollment(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);++$checks;}
function enrollmentRecord(Database $db,int $company,string $kind,string $payload):void{
    $row=$db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
    if($row)$db->run('UPDATE records SET payload=? WHERE id=?',[$payload,$row['id']]);
    else $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$payload,gmdate('c',time()-900)]);
}
function enrollmentCompany(Database $db,int $company,string $mode='horoz',bool $tracking=false,bool $selected=true):void{
    $db->run('INSERT INTO companies(id,name) VALUES(?,?)',[$company,'Sentetik müşteri '.$company]);
    $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>$mode],'pages'=>['inventory'=>'shown']]],JSON_THROW_ON_ERROR)]);
    enrollmentRecord($db,$company,'channels',json_encode(['name'=>'Trendyol','selected'=>$selected,'hidden'=>true],JSON_THROW_ON_ERROR));
    enrollmentRecord($db,$company,'integration_trendyol','OPAQUE-SYNTHETIC-ENVELOPE-NOT-A-CREDENTIAL');
    if($tracking)$db->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(?,?)',[$company,gmdate('c',time()-900)]);
}
function enrollmentPrivateState(Database $db):array{
    return [$db->all('SELECT * FROM records ORDER BY id'),$db->all('SELECT * FROM company_profiles ORDER BY company_id'),$db->all('SELECT * FROM inventory_tracking ORDER BY company_id')];
}

verifyEnrollment(in_array('trendyol',InventoryCatalogPage::SUPPORTED,true),'Trendyol katalog kapasitesi yayımla birlikte etkin olmalı.');
enrollmentCompany($db,1);enrollmentCompany($db,2,'horoz',true,false);enrollmentCompany($db,3,'store',true);enrollmentCompany($db,4,'horoz',true);enrollmentCompany($db,5,'both',true);
$enrollment=new TrendyolCatalogEnrollment($db);$privateBefore=enrollmentPrivateState($db);
verifyEnrollment(!$enrollment->ensure(1,true),'Henüz stok aktarımı açılmamış müşteri otomatik etkinleştirilmez.');
verifyEnrollment(!$enrollment->ensure(2,true),'Seçilmemiş Trendyol mağazası yeniden açılmaz.');
verifyEnrollment(!$enrollment->ensure(3,true),'Yalnız manuel stok modeli pazaryeri işine dönüştürülmez.');
verifyEnrollment(!$enrollment->ensure(4,false),'Devre dışı API bağlantısı katalog işi oluşturmaz.');
verifyEnrollment(!$enrollment->ensure(987654,true),'Olmayan müşteri için görev oluşmaz.');
verifyEnrollment((int)$db->one('SELECT COUNT(*) n FROM inventory_catalog_jobs')['n']===0,'Reddedilen başlangıçlarda görev kaydı yok.');
verifyEnrollment($enrollment->ensure(4,true),'Gizli fakat seçili, etkin bağlantının eksik görevleri oluşturulur.');
verifyEnrollment((int)$db->one("SELECT COUNT(*) n FROM inventory_catalog_jobs WHERE company_id=4 AND provider='trendyol'")['n']===1,'Tek ve doğru müşteriye ait katalog görevi.');
verifyEnrollment((int)$db->one("SELECT COUNT(*) n FROM inventory_catalog_progress WHERE company_id=4 AND provider='trendyol'")['n']===1,'Tek ve doğru müşteriye ait ilerleme kaydı.');
verifyEnrollment(!$enrollment->ensure(4,true),'Tekrarlanan hazırlık idempotenttir.');
$db->run("UPDATE inventory_catalog_jobs SET page_no=7,next_run=?,attempts=4,error='Sentetik 429',last_success=? WHERE company_id=4 AND provider='trendyol'",[time()+900,gmdate('c',time()-120)]);
$db->run("UPDATE inventory_catalog_progress SET state='retry',lease_token='existing-lease',lease_until=? WHERE company_id=4 AND provider='trendyol'",[time()+90]);
$oldJob=$db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=4 AND provider='trendyol'");
$oldProgress=$db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=4 AND provider='trendyol'");
$enrollment->ensure(4,true);
verifyEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=4 AND provider='trendyol'")===$oldJob,'Mevcut sayfa, backoff, deneme ve hata değişmez.');
verifyEnrollment($db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=4 AND provider='trendyol'")===$oldProgress,'Mevcut ilerleme ve işçi kilidi değişmez.');
(new InventoryProgress($db))->ensure(4,'hepsiburada');
$otherJob=$db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=4 AND provider='hepsiburada'");
$db->run("DELETE FROM inventory_catalog_progress WHERE company_id=4 AND provider='trendyol'");
verifyEnrollment($enrollment->ensure(4,true),'Yalnız eksik ilerleme kaydı tamamlanabilir.');
verifyEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=4 AND provider='trendyol'")===$oldJob,'Eksik ilerleme tamamlanırken mevcut görev sıfırlanmaz.');
verifyEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=4 AND provider='hepsiburada'")===$otherJob,'Diğer pazaryerinin görevi değişmez.');
verifyEnrollment(enrollmentPrivateState($db)===$privateBefore,'API zarfları, müşteri profilleri ve etkinleştirme kayıtları değişmez.');
verifyEnrollment((int)$db->one('SELECT COUNT(*) n FROM inventory_catalog_jobs WHERE company_id IN (1,2,3)')['n']===0,'Diğer müşterilerde görev oluşmaz.');
$db->pdo->beginTransaction();
verifyEnrollment($enrollment->ensure(5,true),'Bağlantı kaydının dış işlemi içinde hazırlık çalışır.');
$db->pdo->rollBack();
verifyEnrollment(!$db->one('SELECT company_id FROM inventory_catalog_jobs WHERE company_id=5'),'Dış işlem geri alındığında türetilmiş görev de geri alınır.');

// A new enabled connection reports a queued stock pass without changing its credential.
$recordsBefore=$db->all('SELECT * FROM records ORDER BY id');
$run=$db->transaction(fn()=>(new IntegrationImportRuns($db))->begin(5,'trendyol',1,'synthetic-revision-new'));
$runRow=$db->one('SELECT * FROM integration_import_runs WHERE run_id=?',[$run]);$baseline=json_decode($runRow['baseline'],true,32,JSON_THROW_ON_ERROR);$report=json_decode($runRow['report'],true,32,JSON_THROW_ON_ERROR);
verifyEnrollment($baseline['catalogSupported']&&$baseline['inventoryEnabled'],'Yeni bağlantı raporu gerçek katalog görevini başlangıç kapsamına alır.');
verifyEnrollment($report['stages']['stock']['status']==='queued'&&$report['stages']['products']['status']==='queued','Yeni bağlantı ürün ve stok tamamlanmasını bekler.');
verifyEnrollment($db->all('SELECT * FROM records ORDER BY id')===$recordsBefore,'Bağlantı raporu ve görev hazırlığı API zarfını değiştirmez.');
$job5=$db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=5 AND provider='trendyol'");
verifyEnrollment($db->transaction(fn()=>(new IntegrationImportRuns($db))->begin(5,'trendyol',1,'synthetic-revision-new'))===$run,'Aynı bağlantı sürümü aynı raporu döndürür.');
verifyEnrollment($db->one("SELECT * FROM inventory_catalog_jobs WHERE company_id=5 AND provider='trendyol'")===$job5,'Aynı bağlantının tekrarı katalog görevini değiştirmez.');

// A formerly unsupported legacy report follows current stock without rewriting history/notices.
$legacyId=str_repeat('a',32);$at=gmdate('c',time()-600);
$legacy=['runId'=>$legacyId,'provider'=>'trendyol','providerLabel'=>'Trendyol','state'=>'complete','startedAt'=>$at,'updatedAt'=>$at,'finishedAt'=>$at,'counts'=>['products'=>null,'stockRecords'=>null,'orders'=>2,'returns'=>0],'countsScope'=>'persisted_snapshot','error'=>null,'nextRetryAt'=>null,'stages'=>['connection'=>['status'=>'complete'],'orders'=>['status'=>'complete'],'products'=>['status'=>'unsupported'],'stock'=>['status'=>'unsupported']],'warnings'=>[]];
$db->run('INSERT INTO integration_import_runs(run_id,company_id,provider,credential_version,credential_revision,state,started_at,updated_at,finished_at,baseline,report,success_notice_id) VALUES(?,4,\'trendyol\',1,\'synthetic-legacy-revision\',\'complete\',?,?,?,?,?,321)',[$legacyId,$at,$at,$at,json_encode(['inventoryEnabled'=>false,'catalogSupported'=>false],JSON_THROW_ON_ERROR),json_encode($legacy,JSON_THROW_ON_ERROR)]);
$now=time();
enrollmentRecord($db,4,'sync_trendyol',json_encode(['phase'=>'ready','ordersPublished'=>true,'salesPublished'=>true,'lastSuccessAt'=>gmdate('c',$now-5),'historyNext'=>$now*1000,'historyEnd'=>$now*1000,'recentThrough'=>$now*1000],JSON_THROW_ON_ERROR));
$db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(4,'Trendyol','pending',?)",[$now+60]);
$db->run("UPDATE inventory_catalog_jobs SET page_no=0,next_run=0,attempts=0,error=NULL,last_success=NULL WHERE company_id=4 AND provider='trendyol'");
$db->run("UPDATE inventory_catalog_progress SET state='queued',started_at=0,completed_at=NULL,lease_token=NULL,lease_until=0 WHERE company_id=4 AND provider='trendyol'");
$legacyStored=$db->one('SELECT * FROM integration_import_runs WHERE run_id=?',[$legacyId]);$privateBefore=enrollmentPrivateState($db);
$reports=(new IntegrationImportRuns($db))->listing(4);$shown=array_values(array_filter($reports,fn($r)=>$r['runId']===$legacyId))[0];
verifyEnrollment(count(array_filter($reports,fn($r)=>$r['provider']==='trendyol'))===1,'Eski rapor ve canlı durum yinelenen Trendyol kartları üretmez.');
verifyEnrollment($shown['stages']['stock']['status']==='queued'&&$shown['stages']['products']['status']==='queued','Eski unsupported yerine güncel katalog durumu gösterilir.');
verifyEnrollment($shown['state']==='running'&&$shown['finishedAt']===null,'Yeni katalog beklerken tüm aktarım tamamlandı gösterilmez.');
verifyEnrollment($shown['stages']['orders']===$legacy['stages']['orders']&&$shown['counts']['orders']===2,'Sipariş aşaması ve tarihsel sipariş sayısı değiştirilmez.');
verifyEnrollment($shown['noticeId']===321&&$shown['runId']===$legacyId,'Eski bildirim ve rapor kimliği korunur.');
verifyEnrollment($db->one('SELECT * FROM integration_import_runs WHERE run_id=?',[$legacyId])===$legacyStored,'Listeleme tarihsel raporu veya bildirim kaydını yeniden yazmaz.');
verifyEnrollment(enrollmentPrivateState($db)===$privateBefore,'Canlı görünüm kimlik bilgisi ve müşteri ayarı değiştirmez.');
$catalog=new InventoryCatalog($db);
foreach(['SYNTHETIC-SKU-A','SYNTHETIC-SKU-B'] as $sku)$catalog->ingest(4,'Trendyol',['id'=>$sku,'title'=>'Sentetik katalog ürünü','sku'=>$sku,'quantity'=>7]);
$db->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,unknown_quantities=0 WHERE company_id=4 AND provider='trendyol'",[$now-20,$now-2]);
$db->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=4 AND provider='trendyol'",[gmdate('c',$now-2)]);
$shown=array_values(array_filter((new IntegrationImportRuns($db))->listing(4),fn($r)=>$r['runId']===$legacyId))[0];
verifyEnrollment($shown['stages']['stock']['status']==='complete'&&$shown['stages']['products']['status']==='complete','Doğrulanmış yeni katalog tamamlanınca güncel görünüm tamamlanır.');
verifyEnrollment($shown['state']==='complete'&&$shown['counts']['products']===2&&$shown['counts']['stockRecords']===2,'Gerçek tamamlanan ürün/stok sayaçları gösterilir.');
verifyEnrollment($db->one('SELECT * FROM integration_import_runs WHERE run_id=?',[$legacyId])===$legacyStored,'Tamamlanan canlı görünüm de eski başarı bildirimini yeniden üretmez.');

$productsBefore=$db->all('SELECT * FROM inventory_products ORDER BY company_id,product_key');
$inventory=new InventoryRead($db);$filtered=$inventory->listing(4,'',0,'all','Trendyol');
verifyEnrollment(in_array('Trendyol',$filtered['providers'],true),'Arayüzün ortak pazaryeri seçeneklerine Trendyol dahil edilir.');
verifyEnrollment($filtered['total']===2&&count($filtered['items'])===2,'Trendyol seçeneği sayım ve sayfalamadan önce gerçek katalog ürünlerini filtreler.');
verifyEnrollment(InventoryMarketplaceFilter::label('trendyol')==='Trendyol'&&$inventory->listing(5,'',0,'all','Trendyol')['total']===0,'Trendyol kısa anahtarı kabul edilir; başka müşterinin ürünleri görünmez.');
verifyEnrollment($db->all('SELECT * FROM inventory_products ORDER BY company_id,product_key')===$productsBefore,'Pazaryeri filtresi ürün veya stok kayıtlarını değiştirmez.');

echo $checks." Trendyol katalog görevi, yeni müşteri ve eski rapor sürekliliği kontrolü geçti.\n";
