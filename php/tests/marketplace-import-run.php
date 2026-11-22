<?php
declare(strict_types=1);

// Synthetic SQLite fixtures only: no production database, provider request, or real credential.
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CustomerNotificationPolicy,Database,DeliveryCipher,DeliverySchema,HepsiburadaConnection,HepsiburadaOrderDiscovery,HttpError,IntegrationImportEvidence,IntegrationImportReconciler,IntegrationImportRuns,IntegrationImportSchema,IntegrationVault,InventoryProgress,InventorySchema,KoctasConnection,MarketplaceConnection,MobileNotificationPolicy,MobilePushDevices,MobilePushMessage,N11Connection,NotificationAudience,NotificationFeed,NotificationSchema,OperationalNotifications,PazaramaConnection,PushDevices,PushWorker,RecentOrderSchema,ShipmentSchema,SupportSchema};

$checks=0;
function verifyImport(bool $condition,string $message):void
{
    global $checks;
    if(!$condition)throw new RuntimeException($message);
    $checks++;
}

function importDatabase():Database
{
    $db=new Database(['dsn'=>'sqlite::memory:']);
    $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
    InventorySchema::install($db);
    DeliverySchema::install($db);
    ShipmentSchema::install($db);
    SupportSchema::migrate($db);
    NotificationSchema::migrate($db);
    RecentOrderSchema::install($db);
    IntegrationImportSchema::install($db);
    $db->run("INSERT INTO companies VALUES(1,'Örnek Firma'),(2,'İkinci Firma')");
    $db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL),(2,'client@example.test','Müşteri','hash','client',1),(3,'other@example.test','Diğer müşteri','hash','client',2)");
    foreach([1,2] as $company)$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both'],'pages'=>['inventory'=>'shown']]],JSON_THROW_ON_ERROR)]);
    return $db;
}

function importRecord(Database $db,int $company,string $kind,array $payload,int $now,?int $version=null):void
{
    $row=$db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
    $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
    if($row)$db->run('UPDATE records SET payload=?,version=?,updated=? WHERE id=?',[$encoded,$version??((int)$row['version']+1),gmdate('c',$now),$row['id']]);
    else $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,?,?)',[$company,$kind,$encoded,$version??1,gmdate('c',$now)]);
}

function importRun(Database $db,int $company,string $provider,string $revision,int $now,int $version=1):string
{
    return $db->transaction(function()use($db,$company,$provider,$revision,$now,$version){
        importRecord($db,$company,'integration_'.$provider,[],$now,$version);
        return (new IntegrationImportRuns($db))->begin($company,$provider,$version,$revision,$now);
    });
}

function importReport(Database $db,int $company,string $id):array
{
    foreach((new IntegrationImportRuns($db))->listing($company) as $report)if((string)$report['runId']===$id)return $report;
    throw new RuntimeException('Aktarım raporu bulunamadı.');
}

function completeImportOrders(Database $db,int $company,string $provider,string $revision,int $now):void
{
    $state=['phase'=>'ready','ordersPublished'=>true,'sampleOnly'=>false,'salesPublished'=>true,'snapshotRevision'=>$revision,'lastSuccessAt'=>gmdate('c',$now),'importCompletion'=>['revision'=>$revision,'completedAt'=>gmdate('c',$now)]];
    if($provider==='hepsiburada')$state['feedCompletedAt']=array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,gmdate('c',$now));
    importRecord($db,$company,'sync_'.$provider,$state,$now);
}

function importJob(Database $db,int $company,string $provider,string $state,int $now,?string $error=null):void
{
    $label=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pazarama'=>'Pazarama','pttavm'=>'PTT AVM','koctas'=>'Koçtaş','trendyol'=>'Trendyol'][$provider];
    $job=$db->one('SELECT id FROM jobs WHERE company_id=? AND provider=?',[$company,$label]);
    if($job)$db->run('UPDATE jobs SET state=?,attempts=?,error=?,next_run=? WHERE id=?',[$state,$error?1:0,$error,$now+120,$job['id']]);
    else $db->run('INSERT INTO jobs(company_id,provider,state,attempts,error,next_run) VALUES(?,?,?,?,?,?)',[$company,$label,$state,$error?1:0,$error,$now+120]);
}

$now=time()-1000;
$db=importDatabase();
$notices=[];
$reconciler=new IntegrationImportReconciler($db,function(int $id)use(&$notices){$notices[]=$id;});
IntegrationImportSchema::install($db);
IntegrationImportSchema::ensure($db);
verifyImport(IntegrationImportSchema::available($db),'Hazır şema tekrar kurulmadan doğrulanabilmeli.');

$id=importRun($db,1,'n11','revision-n11-one',$now);
importJob($db,1,'n11','pending',$now);
importRecord($db,1,'sync_n11',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now-10),'ordersPublished'=>true,'salesPublished'=>true,'importCompletion'=>['revision'=>'old-revision','completedAt'=>gmdate('c',$now-10)]],$now+1);
$reconciler->check(1,'n11',$now+2);
verifyImport(importReport($db,1,$id)['state']!=='complete'&&$notices===[],'Eski başarı ve önceki bağlantı revizyonu yeni aktarımı tamamlamamalı.');

importRecord($db,1,'sync_n11',['phase'=>'syncing','lastSuccessAt'=>gmdate('c',$now+3),'ordersPublished'=>true,'salesPublished'=>true,'scan'=>['page'=>2,'revision'=>'revision-n11-one']],$now+3);
$reconciler->check(1,'n11',$now+4);
verifyImport(importReport($db,1,$id)['state']!=='complete'&&$notices===[],'İlk başarılı sayfa ve yayımlanan siparişler tam tarama kanıtı değildir.');

foreach(['one','two'] as $key)$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_n11',json_encode(['externalId'=>$key,'createdAt'=>gmdate('c',$now)],JSON_THROW_ON_ERROR),gmdate('c',$now+5)]);
$beforeOrders=$db->all("SELECT * FROM records WHERE kind='order_n11' ORDER BY id");
completeImportOrders($db,1,'n11','revision-n11-one',$now+6);
$reconciler->check(1,'n11',$now+7);
$report=importReport($db,1,$id);
verifyImport($report['state']==='complete'&&count($notices)===1,'Güncel bağlantının doğrulanmış tam sipariş taraması bir kez tamamlanmalı.');
verifyImport($report['counts']['orders']===2,'Sipariş sayısı yalnız seçilen müşteri ve sağlayıcının kalıcı kayıtlarından gelmeli.');
verifyImport(($report['countsScope']??'')==='persisted_snapshot','Kalıcı anlık görüntü sayıları yeni/güncellenen ürün sayısı gibi sunulmamalı.');
verifyImport($report['stages']['stock']['status']==='not_enabled','Stok aktarımı açılmamış müşteride API kaydı stok takibini kendiliğinden başlatmamalı.');
verifyImport($db->all("SELECT * FROM records WHERE kind='order_n11' ORDER BY id")===$beforeOrders,'Aktarım raporu iş kayıtlarını değiştirmemeli.');
verifyImport($db->one('SELECT company_id FROM inventory_tracking WHERE company_id=1')===null,'Raporlama stok modelini veya etkinleştirmeyi değiştirmemeli.');
$reconciler->check(1,'n11',$now+8);
verifyImport(count($notices)===1,'Tekrarlanan gözlem başarı bildirimini çoğaltmamalı.');
$event=$db->one('SELECT * FROM order_notifications WHERE id=?',[$notices[0]]);
$envelope=json_decode($event['payload'],true,32,JSON_THROW_ON_ERROR);
verifyImport($event['event_type']==='operation'&&$envelope['audience']==='admin'&&in_array('marketplace_import_completed',$envelope['events'],true),'Tamamlanma olayı yalnız yöneticiye yönelik açık operasyon olayı olmalı.');
verifyImport($envelope['importReport']['runId']===$id&&$envelope['importReport']['counts']['orders']===2,'Bildirim değişmez koşu raporunu taşımalı.');
$frozen=$event['payload'];
$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_n11','{"externalId":"later"}',gmdate('c',$now+9)]);
$reconciler->check(1,'n11',$now+10);
verifyImport($db->one('SELECT payload FROM order_notifications WHERE id=?',[$notices[0]])['payload']===$frozen,'Sonradan gelen kayıtlar tamamlanmış bildirim raporunu değiştirmemeli.');

// Transient provider responses keep the run open; a permanent block notifies once.
$retryId=importRun($db,1,'idefix','revision-retry',$now+20);
importJob($db,1,'idefix','pending',$now+20,'Servis yanıtı: 503');
importRecord($db,1,'sync_idefix',['phase'=>'error','failure'=>['code'=>503]],$now+21);
$reconciler->check(1,'idefix',$now+22);
$retryReport=importReport($db,1,$retryId);
verifyImport($retryReport['state']==='retry'&&$retryReport['nextRetryAt']!==null&&count($notices)===1,'Geçici hata yeniden denenecek durumda kalmalı; terminal hata bildirimi oluşturmamalı.');
importJob($db,1,'idefix','blocked',$now+23,'401 confidential-token@example.test');
$reconciler->check(1,'idefix',$now+24);
$failed=importReport($db,1,$retryId);
verifyImport($failed['state']==='failed'&&count($notices)===2,'Kalıcı bağlantı engeli hata raporu ve tek bildirim oluşturmalı.');
verifyImport(is_string($failed['error'])&&$failed['error']!==''&&!str_contains($failed['error'],'confidential-token'),'Hata raporu ham sağlayıcı metnini veya sırları döndürmemeli.');
$reconciler->check(1,'idefix',$now+25);
verifyImport(count($notices)===2,'Tekrarlanan hata gözlemi aynı uyarıyı çoğaltmamalı.');
importJob($db,1,'idefix','pending',$now+26);
completeImportOrders($db,1,'idefix','revision-retry',$now+27);
$reconciler->check(1,'idefix',$now+28);
verifyImport(importReport($db,1,$retryId)['state']==='complete'&&count($notices)===3,'Engel düzeldikten sonra doğrulanan aktarım tek başarı bildirimiyle tamamlanabilmeli.');
$reconciler->check(1,'idefix',$now+29);
verifyImport(count($notices)===3,'İyileşme başarısı da tekil kalmalı.');

// Full-pass evidence is fail-closed across all seven providers and isolated order conflicts.
foreach(['hepsiburada','n11','idefix','pazarama','pttavm','koctas','trendyol'] as $provider){
    $run=['provider'=>$provider,'credential_revision'=>'current-revision','started_at'=>gmdate('c',$now),'baseline'=>json_encode(['syncVersion'=>5,'inventoryStartedAt'=>$now-100,'inventoryCompletedAt'=>$now-90],JSON_THROW_ON_ERROR)];
    $state=['importCompletion'=>['revision'=>'current-revision','completedAt'=>gmdate('c',$now+1)],'lastSuccessAt'=>gmdate('c',$now+1)];
    if($provider==='hepsiburada')$state['feedCompletedAt']=array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,gmdate('c',$now+1));
    verifyImport(IntegrationImportEvidence::orders($run,$state,6,0),$provider.': güncel tam geçiş kanıtı kabul edilmeli.');
    verifyImport(!IntegrationImportEvidence::orders($run,$state,5,0),$provider.': değiştirilmemiş sync kaydı tamamlanmış sayılmamalı.');
    verifyImport(!IntegrationImportEvidence::orders($run,$state,6,1),$provider.': izole sipariş hatası tam başarıyı engellemeli.');
    verifyImport(!IntegrationImportEvidence::orders($run,[...$state,'financialSnapshotHeld'=>true],6,0),$provider.': finansal görüntü beklemesi tam başarıyı engellemeli.');
    verifyImport(!IntegrationImportEvidence::orders($run,[...$state,'importCompletion'=>['revision'=>'current-revision','completedAt'=>gmdate('c',$now-1)]],6,0),$provider.': API kaydından eski marker reddedilmeli.');
    if($provider==='hepsiburada'){
        $partial=$state;$partial['feedCompletedAt'][HepsiburadaOrderDiscovery::FEEDS[0]]=gmdate('c',$now-1);
        verifyImport(!IntegrationImportEvidence::orders($run,$partial,6,0),'Hepsiburada bütün akışları yeniden tamamlamalı.');
        verifyImport(!IntegrationImportEvidence::orders($run,[...$state,'pendingOrders'=>['pending-id']],6,0),'Hepsiburada bekleyen sipariş detayları varken tamamlanmamalı.');
        $sameSecond=array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,gmdate('c',$now));
        $sameRun=[...$run,'baseline'=>json_encode(['syncVersion'=>5,'feedCompletedAt'=>$sameSecond],JSON_THROW_ON_ERROR)];
        verifyImport(!IntegrationImportEvidence::orders($sameRun,[...$state,'feedCompletedAt'=>$sameSecond],6,0),'Hepsiburada aynı saniyedeki önceki akış tamamlanmaları yeni revizyonun kanıtı sayılmamalı.');
        verifyImport(IntegrationImportEvidence::orders($sameRun,$state,6,0),'Hepsiburada her akışın yeni tamamlanma kanıtı geldiğinde ilerleyebilmeli.');
    }
}

// A new API save must fence any result belonging to the previous credential revision.
$fenceDb=importDatabase();$fenceNotices=[];
$fence=new IntegrationImportReconciler($fenceDb,function(int $id)use(&$fenceNotices){$fenceNotices[]=$id;});
$oldRun=importRun($fenceDb,1,'n11','revision-old',$now+40);
importJob($fenceDb,1,'n11','pending',$now+40);
$newRun=importRun($fenceDb,1,'n11','revision-new',$now+42,2);
completeImportOrders($fenceDb,1,'n11','revision-old',$now+43);
$fence->check(1,'n11',$now+44);
verifyImport(importReport($fenceDb,1,$newRun)['state']!=='complete'&&$fenceNotices===[],'Eski işçinin geç gelen sonucu yeni API revizyonunu tamamlamamalı.');
completeImportOrders($fenceDb,1,'n11','revision-new',$now+45);
$fence->check(1,'n11',$now+46);
verifyImport(importReport($fenceDb,1,$newRun)['state']==='complete'&&count($fenceNotices)===1,'Yalnız güncel revizyonun sonucu tamamlanma bildirmeli.');

$otherRun=importRun($fenceDb,2,'n11','revision-other',$now+50);
importJob($fenceDb,2,'n11','pending',$now+50);
$fenceDb->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[2,'order_n11','{"externalId":"other-tenant"}',gmdate('c',$now+51)]);
$fence->check(1,null,$now+52);
verifyImport(importReport($fenceDb,2,$otherRun)['state']!=='complete','Bir müşterinin gözlemi diğer müşterinin koşusunu ilerletmemeli.');
completeImportOrders($fenceDb,2,'n11','revision-other',$now+53);
$fence->check(2,'n11',$now+54);
verifyImport(importReport($fenceDb,2,$otherRun)['counts']['orders']===1,'Aynı sağlayıcının diğer müşteriye ait kayıtları rapora karışmamalı.');

// Enabled stock requires a new full catalogue pass, rather than an old first-import flag.
$stockDb=importDatabase();$stockNotices=[];
$stockObserver=new IntegrationImportReconciler($stockDb,function(int $id)use(&$stockNotices){$stockNotices[]=$id;});
$stockDb->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(1,?)',[gmdate('c',$now-100)]);
(new InventoryProgress($stockDb))->ensure(1,'n11');
$stockDb->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,pages_done=1 WHERE company_id=1",[$now-100,$now-90]);
$stockDb->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',[gmdate('c',$now-90)]);
$stockRun=importRun($stockDb,1,'n11','revision-stock',$now+60);
importJob($stockDb,1,'n11','pending',$now+60);
completeImportOrders($stockDb,1,'n11','revision-stock',$now+61);
$stockObserver->check(1,'n11',$now+62);
verifyImport(importReport($stockDb,1,$stockRun)['state']!=='complete'&&$stockNotices===[],'Eski katalog başarısı yeni API kaydının stok aktarımını tamamlamamalı.');
$stockDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL,pages_done=1 WHERE company_id=1",[$now+63]);
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'n11','page_no'=>0,'partition_no'=>0],['next'=>true],'revision-stock');
$stockObserver->check(1,'n11',$now+64);
verifyImport(importReport($stockDb,1,$stockRun)['state']!=='complete','Yeni katalog taramasının ilk sayfası yeterli değildir.');
$product=hash('sha256','synthetic-product');
$stockDb->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[1,$product,'Sentetik ürün','TEST-SKU','','',gmdate('c',$now+65)]);
$stockDb->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[1,'n11',hash('sha256','listing'),'TEST-REMOTE',$product,'TEST-SKU','{}',5,gmdate('c',$now+65)]);
$stockDb->run("UPDATE inventory_catalog_progress SET state='complete',completed_at=?,pages_done=2,items_done=1,unknown_quantities=0 WHERE company_id=1",[$now+66]);
$stockDb->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',[gmdate('c',$now+66)]);
$stockDb->run('UPDATE inventory_catalog_progress SET unknown_quantities=1 WHERE company_id=1');
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'n11','page_no'=>1,'partition_no'=>0],['next'=>false],'revision-stock');
$stockObserver->check(1,'n11',$now+66);
verifyImport(importReport($stockDb,1,$stockRun)['state']!=='complete','Kaynak miktarı doğrulanmamış ürün tam stok başarısı gibi sunulmamalı.');
$stockDb->run('UPDATE inventory_catalog_progress SET unknown_quantities=0 WHERE company_id=1');
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'n11','page_no'=>1,'partition_no'=>0],['next'=>false],'revision-stock');
$stockObserver->check(1,'n11',$now+67);
$stockReport=importReport($stockDb,1,$stockRun);
verifyImport($stockReport['state']==='complete'&&count($stockNotices)===1,'Güncel sipariş ve tam katalog kanıtı birlikte tamamlanmalı.');
verifyImport($stockReport['counts']['products']===1&&$stockReport['counts']['stockRecords']===1,'Ürün ve stok sayıları kalıcı sağlayıcı kayıtlarıyla doğrulanmalı.');

(new InventoryProgress($stockDb))->ensure(1,'trendyol');
$trendyolRun=importRun($stockDb,1,'trendyol','revision-trendyol',$now+70);
importJob($stockDb,1,'trendyol','pending',$now+70);
completeImportOrders($stockDb,1,'trendyol','revision-trendyol',$now+71);
$stockObserver->check(1,'trendyol',$now+72);
$trendyolReport=importReport($stockDb,1,$trendyolRun);
verifyImport($trendyolReport['state']!=='complete'&&$trendyolReport['stages']['stock']['status']==='queued','Trendyol sipariş başarısı ürün ve stok aktarımı tamamlanmadan tam başarıya dönüşmemeli.');
verifyImport($trendyolReport['counts']['products']===null&&$trendyolReport['counts']['stockRecords']===null,'Trendyol katalog kanıtı gelmeden ürün ve stok adetleri uydurulmamalı.');
$beforeTrendyolNotice=count($stockNotices);
$stockDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL,pages_done=1,items_done=1 WHERE company_id=1 AND provider='trendyol'",[$now+73]);
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'trendyol','page_no'=>0,'partition_no'=>0],['next'=>true],'revision-trendyol');
$stockObserver->check(1,'trendyol',$now+74);
verifyImport(importReport($stockDb,1,$trendyolRun)['state']!=='complete'&&count($stockNotices)===$beforeTrendyolNotice,'Trendyol ilk başarılı katalog sayfası erken başarı bildirimi üretmemeli.');
foreach([['TY-ONE',0],['TY-TWO',7]] as [$remote,$quantity]){
    $productKey=hash('sha256','trendyol-'.$remote);
    foreach([1,2] as $company){
        $stockDb->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$productKey,'Sentetik Trendyol ürünü '.$remote,$remote,'','',gmdate('c',$now+74)]);
        $stockDb->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$company,'Trendyol',hash('sha256',$remote),$remote,$productKey,$remote,'{}',$company===1?$quantity:999,gmdate('c',$now+74)]);
    }
}
$stockDb->run("UPDATE inventory_catalog_progress SET state='complete',completed_at=?,pages_done=2,items_done=2,unknown_quantities=1 WHERE company_id=1 AND provider='trendyol'",[$now+75]);
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'trendyol','page_no'=>1,'partition_no'=>0],['next'=>false],'revision-trendyol');
$stockObserver->check(1,'trendyol',$now+75);
verifyImport(importReport($stockDb,1,$trendyolRun)['state']!=='complete','Trendyol son sayfaya ulaşsa bile bilinmeyen stok varken tamamlanmış sayılmamalı.');
$stockDb->run("UPDATE inventory_catalog_progress SET unknown_quantities=0 WHERE company_id=1 AND provider='trendyol'");
$stockDb->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1 AND provider='trendyol'",[gmdate('c',$now+76)]);
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'trendyol','page_no'=>1,'partition_no'=>0],['next'=>false],'revision-trendyol');
$stockObserver->check(1,'trendyol',$now+77);
$trendyolReport=importReport($stockDb,1,$trendyolRun);
verifyImport($trendyolReport['state']==='complete'&&$trendyolReport['stages']['products']['status']==='complete'&&$trendyolReport['stages']['stock']['status']==='complete','Trendyol tam sipariş, ürün ve stok kanıtı aynı koşuda tamamlanmalı.');
verifyImport($trendyolReport['counts']['products']===2&&$trendyolReport['counts']['stockRecords']===2,'Trendyol sıfır stok dahil gerçek kaynak kayıtlarını saymalı; diğer müşterinin aynı SKU kayıtları eklenmemeli.');
verifyImport(count($stockNotices)===$beforeTrendyolNotice+1,'Trendyol tam aktarımı yöneticilere yalnız bir kez bildirmeli.');
$stockObserver->check(1,'trendyol',$now+78);
verifyImport(count($stockNotices)===$beforeTrendyolNotice+1,'Trendyol tekrarlanan gözlemleri başarı bildirimini çoğaltmamalı.');

$catalogJobCount=(int)$stockDb->one('SELECT COUNT(*) n FROM inventory_catalog_jobs')['n'];
$noCatalogRun=importRun($stockDb,1,'idefix','revision-no-catalog',$now+80);
importJob($stockDb,1,'idefix','pending',$now+80);
completeImportOrders($stockDb,1,'idefix','revision-no-catalog',$now+81);
$stockObserver->check(1,'idefix',$now+82);
$noCatalogReport=importReport($stockDb,1,$noCatalogRun);
verifyImport($noCatalogReport['state']==='complete'&&$noCatalogReport['stages']['stock']['status']==='not_enabled'&&!empty($noCatalogReport['warnings']),'Yeni sağlayıcı katalog görevi olmadan kaydedilirse stok aktarımı açılmamış olarak açıkça belirtilmeli.');
verifyImport((int)$stockDb->one('SELECT COUNT(*) n FROM inventory_catalog_jobs')['n']===$catalogJobCount,'Rapor gözlemcisi yeni stok görevi veya model etkinleştirmesi oluşturmamalı.');

// Optional catalogue metadata cannot hold a verified source stock/order import open forever.
(new InventoryProgress($stockDb))->ensure(1,'hepsiburada');
$metadataRun=importRun($stockDb,1,'hepsiburada','revision-metadata',$now+90);
importJob($stockDb,1,'hepsiburada','pending',$now+90);
completeImportOrders($stockDb,1,'hepsiburada','revision-metadata',$now+91);
$metadataProduct=hash('sha256','synthetic-metadata-product');
$stockDb->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[1,$metadataProduct,'Stok kodu: META-SKU','META-SKU','','',gmdate('c',$now+91)]);
$stockDb->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[1,'Hepsiburada',hash('sha256','metadata-listing'),'META-REMOTE',$metadataProduct,'META-SKU','{"saleActive":1}',3,gmdate('c',$now+91)]);
$stockDb->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,pages_done=1,items_done=1,unknown_quantities=0 WHERE company_id=1 AND provider='hepsiburada'",[$now+91,$now+92]);
IntegrationImportEvidence::catalogPage($stockDb,1,['provider'=>'hepsiburada','page_no'=>0,'partition_no'=>0],['next'=>false],'revision-metadata');
$stockObserver->check(1,'hepsiburada',$now+93);
$metadataReport=importReport($stockDb,1,$metadataRun);
verifyImport($metadataReport['state']==='complete'&&!empty($metadataReport['warnings']),'Eksik ürün adı ayrı uyarıyla belirtilmeli; doğrulanmış stok/sipariş aktarımı tamamlanabilmeli.');

// Equal-second completion and a credential change halfway through a catalogue pass are fenced.
$raceDb=importDatabase();$raceNotices=[];
$raceObserver=new IntegrationImportReconciler($raceDb,function(int $id)use(&$raceNotices){$raceNotices[]=$id;});
$raceDb->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(1,?)',[gmdate('c',$now-100)]);
(new InventoryProgress($raceDb))->ensure(1,'n11');
importRun($raceDb,1,'n11','catalog-old',$now+200);
importJob($raceDb,1,'n11','pending',$now+200);
$raceDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL,pages_done=1 WHERE company_id=1",[$now+200]);
IntegrationImportEvidence::catalogPage($raceDb,1,['provider'=>'n11','page_no'=>0,'partition_no'=>0],['next'=>true],'catalog-old');
$currentRace=importRun($raceDb,1,'n11','catalog-new',$now+200,2);
completeImportOrders($raceDb,1,'n11','catalog-new',$now+201);
$raceDb->run("UPDATE inventory_catalog_progress SET state='complete',completed_at=?,pages_done=2,unknown_quantities=0 WHERE company_id=1",[$now+201]);
IntegrationImportEvidence::catalogPage($raceDb,1,['provider'=>'n11','page_no'=>1,'partition_no'=>0],['next'=>false],'catalog-old');
$raceObserver->check(1,'n11',$now+202);
verifyImport(importReport($raceDb,1,$currentRace)['state']!=='complete'&&$raceNotices===[],'Aynı saniyedeki eski katalog sonucu yeni API revizyonunu tamamlamamalı.');
IntegrationImportEvidence::catalogPage($raceDb,1,['provider'=>'n11','page_no'=>1,'partition_no'=>0],['next'=>false],'catalog-new');
$raceObserver->check(1,'n11',$now+203);
verifyImport(importReport($raceDb,1,$currentRace)['state']!=='complete','Yeni bağlantıyla yalnız kalan sayfalar okunursa tam katalog taraması sayılmamalı.');
$raceDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL,pages_done=1 WHERE company_id=1",[$now+204]);
IntegrationImportEvidence::catalogPage($raceDb,1,['provider'=>'n11','page_no'=>0,'partition_no'=>0],['next'=>true],'catalog-new');
$raceDb->run("UPDATE inventory_catalog_progress SET state='complete',completed_at=?,pages_done=2,unknown_quantities=0 WHERE company_id=1",[$now+205]);
IntegrationImportEvidence::catalogPage($raceDb,1,['provider'=>'n11','page_no'=>1,'partition_no'=>0],['next'=>false],'catalog-new');
$raceObserver->check(1,'n11',$now+206);
verifyImport(importReport($raceDb,1,$currentRace)['state']==='complete'&&count($raceNotices)===1,'Yeni API revizyonu ilk sayfadan son sayfaya taranınca tamamlanmalı.');

$returnsDb=importDatabase();$returnsObserver=new IntegrationImportReconciler($returnsDb,static function(int $id):void{});
$staleReturnsRun=importRun($returnsDb,1,'idefix','returns-old',$now+210);
importJob($returnsDb,1,'idefix','pending',$now+210);
completeImportOrders($returnsDb,1,'idefix','returns-old',$now+211);
$returnsState=json_decode($returnsDb->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_idefix'")['payload'],true,32,JSON_THROW_ON_ERROR);
$requests=['items'=>[['id'=>'r1'],['id'=>'r2']],'checkedAt'=>gmdate('c',$now+209),'through'=>gmdate('c',$now+209)];
importRecord($returnsDb,1,'sync_idefix',[...$returnsState,'returnRequests'=>$requests],$now+211);
$returnsObserver->check(1,'idefix',$now+212);
verifyImport(importReport($returnsDb,1,$staleReturnsRun)['counts']['returns']===null,'Yeni API kaydından eski iade görüntüsü güncel aktarım sayısı gibi sunulmamalı.');
$freshReturnsRun=importRun($returnsDb,1,'idefix','returns-new',$now+220,2);
completeImportOrders($returnsDb,1,'idefix','returns-new',$now+221);
$returnsState=json_decode($returnsDb->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_idefix'")['payload'],true,32,JSON_THROW_ON_ERROR);
$requests['checkedAt']=gmdate('c',$now+221);$requests['through']=gmdate('c',$now+221);
importRecord($returnsDb,1,'sync_idefix',[...$returnsState,'returnRequests'=>$requests],$now+221);
$returnsObserver->check(1,'idefix',$now+222);
verifyImport(importReport($returnsDb,1,$freshReturnsRun)['counts']['returns']===2,'Doğrulanmış güncel iade görüntüsü gerçek kayıt sayısını vermeli.');

verifyImport(in_array('CASCADE',array_column($returnsDb->all('PRAGMA foreign_key_list(integration_import_runs)'),'on_delete'),true),'Müşteri silme aktarım raporu yabancı anahtarı tarafından engellenmemeli.');
$backupExtension=file_get_contents(__DIR__.'/../src/BackupSchemaExtension.php');
verifyImport(str_contains($backupExtension,"'integration_import_runs'"),'Aktarım koşuları mevcut yedekleme kapsamına dahil edilmeli.');

// Synthetic credentials cover every real save entry point without making upstream requests.
$hookDb=importDatabase();$keyPath=tempnam(sys_get_temp_dir(),'import-runs-test-');file_put_contents($keyPath,random_bytes(32));
try{
    $vault=new IntegrationVault($hookDb,$keyPath);
    $uuid='12345678-1234-1234-1234-123456789abc';
    (new HepsiburadaConnection($hookDb,$vault))->save(1,['version'=>0,'merchantId'=>$uuid,'username'=>'synthetic-user','serviceKey'=>'synthetic-hepsiburada-secret','userAgent'=>'synthetic_dev'],1);
    (new N11Connection($hookDb,$vault))->save(1,['version'=>0,'apiKey'=>'synthetic-n11-key','apiSecret'=>'synthetic-n11-secret'],1);
    (new PazaramaConnection($hookDb,$vault))->save(1,['version'=>0,'sellerId'=>$uuid,'apiKey'=>'synthetic-pazarama-key','apiSecret'=>'synthetic-pazarama-secret'],1);
    (new KoctasConnection($hookDb,$vault))->save(1,['version'=>0,'apiKey'=>$uuid,'shopId'=>123],1);
    foreach(['idefix','pttavm','trendyol'] as $provider)(new MarketplaceConnection($hookDb,$vault,$provider))->save(1,['version'=>0,'vendorId'=>'123','supplierId'=>'123','apiKey'=>'synthetic-'.$provider.'-key','apiSecret'=>'synthetic-'.$provider.'-secret','accessToken'=>'synthetic-'.$provider.'-token']);
    $hookReports=(new IntegrationImportRuns($hookDb))->listing(1);
    verifyImport(count($hookReports)===7,'Yedi sağlayıcının başarılı API kaydı aktarım koşusu oluşturmalı.');
    $encoded=json_encode($hookReports,JSON_THROW_ON_ERROR);
    verifyImport(!str_contains($encoded,'synthetic-')&&!str_contains($encoded,$uuid),'Koşu listesi API alanlarını veya sırlarını döndürmemeli.');
    $before=count($hookDb->all('SELECT * FROM integration_import_runs'));
    (new MarketplaceConnection($hookDb,$vault,'idefix'))->retry(1);
    verifyImport(count($hookDb->all('SELECT * FROM integration_import_runs'))===$before,'Aynı bağlantının tekrar denemesi yeni koşu üretmemeli.');
    $connectionBefore=$hookDb->one("SELECT * FROM records WHERE company_id=1 AND kind='integration_idefix'");
    try{(new MarketplaceConnection($hookDb,$vault,'idefix'))->save(1,['version'=>0,'vendorId'=>'123','apiKey'=>'synthetic-new-key','apiSecret'=>'synthetic-new-secret']);throw new RuntimeException('Eski sürüm kabul edildi.');}
    catch(HttpError $error){verifyImport($error->getCode()===409,'Eski bağlantı sürümü reddedilmeli.');}
    verifyImport(count($hookDb->all('SELECT * FROM integration_import_runs'))===$before&&$hookDb->one("SELECT * FROM records WHERE company_id=1 AND kind='integration_idefix'")===$connectionBefore,'Başarısız API kaydı eski bağlantıyı ve koşu sayısını değiştirmemeli.');
}finally{unlink($keyPath);}

// Permanent feed, direct detail access policy, desktop arrival and actual push delivery share scope.
$admin=$db->one('SELECT * FROM users WHERE id=1');$client=$db->one('SELECT * FROM users WHERE id=2');
verifyImport(NotificationAudience::permits($admin,$event)&&!NotificationAudience::permits($client,$event),'Aktarım raporu müşteri cihazına yetkili olay gibi görünmemeli.');
verifyImport((new CustomerNotificationPolicy($db,$admin,1))->permits($event)&&!(new CustomerNotificationPolicy($db,$client,1))->permits($event),'Bildirim ayrıntı rotası müşteri için kapalı, Omnigo yöneticisi için açık olmalı.');
$feed=new NotificationFeed($db,DeliveryCipher::withKey(str_repeat('i',32)));
$runCount=(int)$db->one("SELECT COUNT(*) AS n FROM integration_import_runs WHERE company_id=1")['n'];
verifyImport(count($feed->listing(1,1,0)['items'])===3+$runCount&&$feed->listing(2,1,0)['items']===[],'Başarı ve hata kayıtları yalnız yönetici bildirim listesinde görünmeli.');
$mixed=[...$envelope,'events'=>['marketplace_import_completed','payment_updated']];
$db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(1,'n11',?,'operation',?,?)",[hash('sha256','mixed-admin-only'),json_encode($mixed,JSON_THROW_ON_ERROR),gmdate('c',$now+300)]);
verifyImport($feed->listing(2,1,0)['items']===[],'Karma olayda ödeme alanı bulunsa bile yönetici aktarım bildirimi müşteri listesine sızmamalı.');
$detail=(new OperationalNotifications($db,DeliveryCipher::withKey(str_repeat('i',32))))->detail(1,(int)$event['id']);
verifyImport(($detail['importReport']['runId']??null)===$id,'Mevcut operasyon ayrıntı endpointi aktarım raporunu açabilmeli.');
try{(new OperationalNotifications($db))->detail(2,(int)$event['id']);throw new RuntimeException('Diğer müşteri raporuna erişildi.');}
catch(HttpError $error){verifyImport($error->getCode()===404,'Rapor başka müşteriden okunamamalı.');}
verifyImport(MobileNotificationPolicy::allows(['kind'=>'android'],$event)&&MobileNotificationPolicy::allowsArrival(['type'=>'operation','events'=>$envelope['events']]),'Aktarım sonuçları telefon ve Windows geliş politikalarında açıkça tanınmalı.');
verifyImport(!MobileNotificationPolicy::allows(['kind'=>'android'],['event_type'=>'integration']),'Eski bağlantı uyarılarının cihaz engeli bozulmamalı.');
$presentation=OperationalNotifications::presentation('operation',$envelope);
verifyImport($presentation['view']==='inventory'&&str_contains(mb_strtolower($presentation['title']),'aktar'),'Aktarım bildirimi stok yönetimi ve özel aktarım başlığına yönlenmeli.');
$message=MobilePushMessage::create([], $event,'synthetic-tag');
verifyImport(str_contains($message['url'],'view=inventory')&&str_contains($message['url'],'noticeType=operation')&&str_contains($message['url'],'noticeId='.$event['id']),'Cihaz bildirimi doğru müşteri ve aktarım raporuna derin bağlantı taşımalı.');

$cipher=DeliveryCipher::withKey(str_repeat('i',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
foreach([1=>'admin',2=>'client'] as $userId=>$role){
    $token=str_repeat((string)$userId,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$userId,'csrf',time()+3600]);
    $user=[...$db->one('SELECT * FROM users WHERE id=?',[$userId]),'token'=>$token];
    $mobile->save($user,['kind'=>'android','appId'=>'com.omnigoglobal.'.$role,'installationId'=>hash('sha256','import-'.$role),'token'=>str_repeat($role==='admin'?'a':'b',140),'environment'=>'production','sound'=>true]);
}
$devices->enqueue((int)$event['id']);$devices->enqueue((int)$event['id']);
$sent=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$sent){$sent[]=$message;return ['success'=>true,'expired'=>false,'status'=>200];});
while($worker->runOne()){}
verifyImport(count($sent)===1&&$sent[0]['title']==='Omnigo Global Yönetici Portalı','Aktarım sonucu gerçek gönderim adımında yalnız yönetici cihazına gitmeli.');
verifyImport((int)$db->one("SELECT COUNT(*) n FROM push_deliveries WHERE event_id=? AND state='delivered'",[$event['id']])['n']===1,'Cihaz başına aynı aktarım bildirimi bir kez teslim edilmeli.');

echo "$checks aktarım koşusu, eski başarı/ilk sayfa engeli, 7 API kayıt yolu, retry/iyileşme, revizyon, stok tam tarama, müşteri ayrımı ve yönetici bildirim kontrolü geçti.\n";
