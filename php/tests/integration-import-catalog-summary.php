<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,IntegrationImportCatalogSummary,InventorySchema};

$checks=0;
function verifyCatalogSummary(bool $condition,string $message):void
{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function catalogSummaryDb(bool $inventory=true):Database
{
    $db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
    if($inventory)InventorySchema::install($db);
    $db->run("INSERT INTO companies VALUES(1,'Birinci müşteri'),(2,'İkinci müşteri')");return $db;
}
function catalogSummaryConnection(Database $db,int $company,string $provider,int $configured,?int $success,string $error=''):void
{
    $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,?,?)',[$company,'integration_'.$provider,'PRIVATE-API-ENVELOPE',3,gmdate('c',$configured)]);
    if($success!==null){
        $db->run('INSERT INTO inventory_catalog_jobs(company_id,provider,last_success,error) VALUES(?,?,?,?)',[$company,$provider,gmdate('c',$success),$error]);
        $db->run("INSERT INTO inventory_catalog_progress(company_id,provider,state,started_at,completed_at) VALUES(?,?,'complete',?,?)",[$company,$provider,$configured,$success]);
    }
}
function catalogSummaryListing(Database $db,int $company,string $provider,string $product,string $remote,string $evidence,?int $quantity,int $observed):void
{
    $db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) SELECT ?,?,?,?,?,?,? WHERE NOT EXISTS (SELECT 1 FROM inventory_products WHERE company_id=? AND product_key=?)',[$company,hash('sha256',$product),'Örnek ürün '.$product,'SYNTHETIC-SKU','','',gmdate('c',$observed),$company,hash('sha256',$product)]);
    $db->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$company,$provider,hash('sha256',$remote),$remote,hash('sha256',$product),'SYNTHETIC-SKU',$evidence,$quantity,gmdate('c',$observed)]);
}

$now=time();$db=catalogSummaryDb();
foreach(['hepsiburada','n11','idefix','pttavm'] as $provider)catalogSummaryConnection($db,1,$provider,$now-300,$now-20,$provider==='idefix'?'HTTP 401 API erişimi doğrulanamadı.':'');
catalogSummaryConnection($db,2,'hepsiburada',$now-300,$now-20);
catalogSummaryListing($db,1,'Hepsiburada','P1','HB-1','{"saleActive":1}',10,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P1','HB-1-CLOSED','{"saleActive":0}',0,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P2','HB-2','{"saleActive":false}',3,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P3','HB-3','{"saleActive":null}',null,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P4','HB-4','{"saleActive":"1"}',3,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P5','HB-5','{malformed-private-evidence',3,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','P6','HB-6','{"saleActive":1}',7,$now-3600);
catalogSummaryListing($db,1,'Hepsiburada','P7','HB-7','{"saleActive":0}',null,$now-10);
catalogSummaryListing($db,1,'Hepsiburada','ORDER-ONLY','order-sku:ORDER-ONLY','{"saleActive":1}',99,$now-10);
catalogSummaryListing($db,1,'n11','P1','N11-1','{"saleActive":0}',5,$now-10);
catalogSummaryListing($db,1,'idefix','P1','IDE-1','{"saleActive":true}',8,$now-10);
catalogSummaryListing($db,1,'Pazarama','P1','PZR-1','{"saleActive":1}',8,$now-10);
catalogSummaryListing($db,2,'Hepsiburada','OTHER','OTHER-HB','{"saleActive":true}',0,$now-10);
$tables=['records','inventory_catalog_jobs','inventory_catalog_progress','inventory_listings','inventory_products'];$before=[];foreach($tables as $table)$before[$table]=$db->all('SELECT * FROM '.$table.' ORDER BY 1,2,3');
$service=new IntegrationImportCatalogSummary($db);$db->pdo->exec('PRAGMA query_only=ON');
try{$all=$service->forProviders(1,['hepsiburada','n11','idefix','pazarama','pttavm','hepsiburada','invalid-provider'],$now);}
finally{$db->pdo->exec('PRAGMA query_only=OFF');}
$hb=$all['hepsiburada'];
verifyCatalogSummary(count($all)===5,'Yalnız istenen ve geçerli sağlayıcılar birer kez okunmalı.');
verifyCatalogSummary($hb['catalogRecords']===8&&$hb['products']===7,'Katalog kaydı ve tekil ürün sayısı ayrılmalı; siparişten üretilen order-sku kayıtları sayılmamalı.');
verifyCatalogSummary($hb['saleActiveProducts']===1&&$hb['saleInactiveProducts']===2&&$hb['saleUnknownProducts']===4,'Aynı ürünün tek açık ilanı yeterli; tümü kapalı veya belirsiz olanlar ayrı sayılmalı.');
verifyCatalogSummary($hb['saleActiveProducts']+$hb['saleInactiveProducts']+$hb['saleUnknownProducts']===$hb['products'],'Satış durumu grupları ürün sayısını bir kez kapsamalı.');
verifyCatalogSummary($hb['saleActiveRecords']===1&&$hb['saleInactiveRecords']===3&&$hb['saleUnknownRecords']===4,'Katalog satırlarında aynı ürünün farklı açık/kapalı ilanları ayrı kayıt olarak sayılmalı.');
verifyCatalogSummary($hb['saleActiveRecords']+$hb['saleInactiveRecords']+$hb['saleUnknownRecords']===$hb['catalogRecords'],'Satış durumu kayıt grupları katalog kaydı ile aynı paydada olmalı.');
verifyCatalogSummary($hb['incompleteRecords']===5,'Eksik/doğrulanamayan kayıtlar ayrı sorunlar yüzünden birden çok sayılmamalı.');
verifyCatalogSummary($hb['unknownStockRecords']===2&&$hb['unknownSaleStatusRecords']===3&&$hb['invalidEvidenceRecords']===1&&$hb['staleRecords']===1,'Null stok, bilinmeyen satış, bozuk JSON ve eski kaynak kanıtı doğru ayrılmalı.');
verifyCatalogSummary($hb['sourceState']==='verified'&&$hb['sourceCheckedAt']===gmdate('c',$now-20),'Kaynak doğrulaması gerçek son başarılı katalog zamanını kullanmalı.');
verifyCatalogSummary($all['n11']['catalogRecords']===1&&$all['n11']['products']===1&&$all['n11']['saleActiveProducts']===0&&$all['n11']['saleInactiveProducts']===1,'Başka sağlayıcıdaki aynı ürün/kod sayıları birbirine karışmamalı.');
verifyCatalogSummary($all['idefix']['sourceState']==='unverified'&&$all['idefix']['saleActiveProducts']===null&&$all['idefix']['saleUnknownProducts']===1,'API hatası satışa açık başarısı üretmemeli.');
verifyCatalogSummary($all['idefix']['incompleteRecords']===1&&$all['idefix']['catalogRecords']===1,'HTTP 401 hata kodu ürün veya hata adedi olarak sayılmamalı.');
verifyCatalogSummary($all['pazarama']['sourceState']==='unavailable'&&$all['pazarama']['saleActiveProducts']===null&&$all['pazarama']['saleUnknownProducts']===1,'API kaydı bulunmayan pazaryerinin tarihsel kataloğu güncel başarı sayılmamalı.');
verifyCatalogSummary($all['pttavm']['sourceState']==='verified'&&$all['pttavm']['catalogRecords']===0&&$all['pttavm']['saleActiveProducts']===0&&$all['pttavm']['incompleteRecords']===0,'Doğrulanmış boş katalogda gerçek sıfır korunmalı.');
$other=$service->forProviders(2,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($other['catalogRecords']===1&&$other['saleActiveProducts']===1&&$other['incompleteRecords']===0,'Diğer müşterinin verisi ayrılmalı; kaynak satışa açık bildiriyorsa sıfır stok kapalı diye tahmin edilmemeli.');
foreach($tables as $table)verifyCatalogSummary($before[$table]===$db->all('SELECT * FROM '.$table.' ORDER BY 1,2,3'),'Salt okunur özet '.$table.' tablosunu değiştirmemeli.');
verifyCatalogSummary(!str_contains(json_encode($all,JSON_THROW_ON_ERROR),'PRIVATE')&&!str_contains(json_encode($all,JSON_THROW_ON_ERROR),'malformed-private-evidence'),'API zarfları ve ham kaynak kanıtı rapora sızmamalı.');
$original=['provider'=>'n11','noticeId'=>42,'counts'=>['products'=>999],'finishedAt'=>'2026-11-01T00:00:00Z'];$attached=$service->attach(1,[$original],$now)[0];
verifyCatalogSummary(array_diff_key($attached,['currentCatalogSummary'=>true])===$original,'Eski raporun sayı, tarihi ve bildirim kimliği değiştirilmemeli.');
verifyCatalogSummary($attached['currentCatalogSummary']['catalogRecords']===1,'Eski rapora güncel katalog ayrı alan olarak eklenmeli.');
$db->run('UPDATE inventory_products SET title=? WHERE company_id=1 AND product_key=?',[' ',hash('sha256','P1')]);
$db->run('UPDATE inventory_products SET title=sku WHERE company_id=1 AND product_key=?',[hash('sha256','P2')]);
$db->run('UPDATE inventory_products SET title=? WHERE company_id=1 AND product_key=?',['Stok kodu: P3',hash('sha256','P3')]);
$named=$service->forProviders(1,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($named['missingNameRecords']===4&&$named['incompleteRecords']===8,'Boş ad, stok kodu yer tutucusu ve yalnız SKU olan ad kayıtlarda sayılmalı; diğer eksiklerle bir kez birleşmeli.');
verifyCatalogSummary($service->forProviders(2,['hepsiburada'],$now)['hepsiburada']['missingNameRecords']===0,'Aynı ürün anahtarı olsa bile başka müşterinin geçerli adı etkilenmemeli.');
$db->run('UPDATE inventory_products SET title=? WHERE company_id=1 AND product_key=?',['Stok kodu çözüm rehberi',hash('sha256','P1')]);
verifyCatalogSummary($service->forProviders(1,['hepsiburada'],$now)['hepsiburada']['missingNameRecords']===2,'Stok kodu ifadesi içeren gerçek ürün adı yer tutucu sanılmamalı.');
$db->run('UPDATE records SET updated=? WHERE company_id=1 AND kind=?',[gmdate('c',$now),'integration_hepsiburada']);
$newKey=$service->forProviders(1,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($newKey['sourceState']==='unverified'&&$newKey['saleActiveProducts']===null&&$newKey['saleUnknownProducts']===7&&$newKey['incompleteRecords']===8,'API bilgisi değişince eski katalog yeni bağlantının güncel başarısı sayılmamalı.');
$empty=catalogSummaryDb(false);$unavailable=(new IntegrationImportCatalogSummary($empty))->forProviders(1,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($unavailable['catalogRecords']===null&&$unavailable['saleActiveProducts']===null&&$unavailable['incompleteRecords']===null,'Katalog tablosu yoksa bilinmeyen sayılar sıfır gösterilmemeli.');
$scanDb=catalogSummaryDb();catalogSummaryConnection($scanDb,1,'hepsiburada',$now-600,$now-20);catalogSummaryConnection($scanDb,1,'n11',$now-600,$now-20);
$scanDb->run("UPDATE inventory_catalog_progress SET started_at=? WHERE company_id=1 AND provider='hepsiburada'",[$now-100]);
catalogSummaryListing($scanDb,1,'Hepsiburada','CURRENT','CURRENT','{"saleActive":1}',5,$now-10);
catalogSummaryListing($scanDb,1,'Hepsiburada','REMOVED','REMOVED','{"saleActive":1}',5,$now-200);
catalogSummaryListing($scanDb,1,'n11','OTHER-PROVIDER','OTHER-PROVIDER','{"saleActive":1}',5,$now-200);
$scanService=new IntegrationImportCatalogSummary($scanDb);$scans=$scanService->forProviders(1,['hepsiburada','n11'],$now);$completed=$scans['hepsiburada'];
verifyCatalogSummary($completed['catalogRecords']===2&&$completed['saleActiveRecords']===1&&$completed['saleUnknownRecords']===1&&$completed['staleRecords']===1,'30 dakikadan yeni olsa bile son tam taramada görülmeyen eski ilan açık sayılamaz.');
verifyCatalogSummary($scans['n11']['saleActiveRecords']===1,'Bir sağlayıcının tam tarama başlangıcı diğer sağlayıcının kayıtlarını geçersizleştirmemeli.');
$scanDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL WHERE company_id=1 AND provider='hepsiburada'",[$now-5]);
$partial=$scanService->forProviders(1,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($partial['sourceState']==='unverified'&&$partial['saleActiveRecords']===null&&$partial['saleInactiveRecords']===null&&$partial['saleUnknownRecords']===2,'Kısmi tarama eski last_success taze olsa bile tam başarı sayılmamalı.');
verifyCatalogSummary($partial['sourceCheckedAt']===gmdate('c',$now-20),'Kısmi tarama gerçek last_success tarihini değiştirmemeli.');
$scanDb->run("DELETE FROM inventory_catalog_progress WHERE company_id=1 AND provider='hepsiburada'");
$missingProgress=$scanService->forProviders(1,['hepsiburada'],$now)['hepsiburada'];
verifyCatalogSummary($missingProgress['sourceState']==='unverified'&&$missingProgress['saleActiveRecords']===null,'Tam tarama kanıtı yoksa taze last_success tek başına açık ilan başarısı üretmemeli.');

// A scan begun before credential replacement cannot verify the new connection merely by ending later.
$rotationDb=catalogSummaryDb();catalogSummaryConnection($rotationDb,1,'n11',$now-300,$now-20);catalogSummaryConnection($rotationDb,2,'n11',$now-600,$now-20);
$rotationDb->run("UPDATE inventory_catalog_progress SET started_at=? WHERE company_id=1 AND provider='n11'",[$now-600]);
catalogSummaryListing($rotationDb,1,'n11','OLD-CONNECTION','OLD-CONNECTION','{"saleActive":1}',5,$now-400);
catalogSummaryListing($rotationDb,1,'n11','LATE-RESPONSE','LATE-RESPONSE','{"saleActive":1}',5,$now-10);
catalogSummaryListing($rotationDb,2,'n11','UNCHANGED-TENANT','UNCHANGED-TENANT','{"saleActive":1}',5,$now-10);
$rotationBefore=[];foreach($tables as $table)$rotationBefore[$table]=$rotationDb->all('SELECT * FROM '.$table.' ORDER BY 1,2,3');
$rotationService=new IntegrationImportCatalogSummary($rotationDb);$rotationDb->pdo->exec('PRAGMA query_only=ON');
try{$rotated=$rotationService->forProviders(1,['n11'],$now)['n11'];$untouched=$rotationService->forProviders(2,['n11'],$now)['n11'];}
finally{$rotationDb->pdo->exec('PRAGMA query_only=OFF');}
verifyCatalogSummary($rotated['sourceState']==='unverified'&&$rotated['sourceCheckedAt']===gmdate('c',$now-20),'API yenilenmeden önce başlayan tarama, sonradan bitse de yeni bağlantının başarısı sayılamaz.');
verifyCatalogSummary($rotated['saleActiveRecords']===null&&$rotated['saleInactiveRecords']===null&&$rotated['saleUnknownRecords']===2&&$rotated['incompleteRecords']===2,'Yeni API kaydını kapsamayan eski taramanın tüm ilanları doğrulanmamış kalmalı.');
verifyCatalogSummary($untouched['sourceState']==='verified'&&$untouched['saleActiveRecords']===1,'Bir müşterinin API değişikliği başka müşterinin aynı sağlayıcı özetini etkilememeli.');
foreach($tables as $table)verifyCatalogSummary($rotationBefore[$table]===$rotationDb->all('SELECT * FROM '.$table.' ORDER BY 1,2,3'),'API sürüm kontrolü '.$table.' tablosunu değiştirmemeli.');
$rotationDb->run("UPDATE inventory_catalog_progress SET started_at=?,completed_at=? WHERE company_id=1 AND provider='n11'",[$now-200,$now-5]);
$rotationDb->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1 AND provider='n11'",[gmdate('c',$now-5)]);
$refreshed=$rotationService->forProviders(1,['n11'],$now)['n11'];
verifyCatalogSummary($refreshed['sourceState']==='verified'&&$refreshed['saleActiveRecords']===1&&$refreshed['saleUnknownRecords']===1,'Yeni API kaydından sonra başlayan tam tarama yalnız kendi gördüğü ilanları doğrulamalı.');

$rotationDb->run("INSERT INTO inventory_catalog_verified_passes(company_id,provider,started_at,completed_at) VALUES(1,'n11',?,?)",[$now-200,$now-5]);
$rotationDb->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,completed_at=NULL WHERE company_id=1 AND provider='n11'",[$now]);
$during=$rotationService->forProviders(1,['n11'],$now)['n11'];
verifyCatalogSummary($during['sourceState']==='verified'&&$during['staleRecords']===$refreshed['staleRecords'],'Yeni tarama sırasında son tam geçişin kayıtları korunmalı.');
verifyCatalogSummary($rotationService->forProviders(1,['n11'],$now+2000)['n11']['sourceState']==='unverified','Eski taramanın güncelliği süre sonunda düşmeli.');
$rotationDb->run("UPDATE inventory_catalog_jobs SET error='HTTP 401' WHERE company_id=1 AND provider='n11'");
verifyCatalogSummary($rotationService->forProviders(1,['n11'],$now)['n11']['sourceState']==='unverified','Kaynak hatası önceki başarıyla gizlenmemeli.');
$sample=['urunId'=>7,'urunAdi'=>'deneme','urunKodu'=>'deneme','gtin'=>'deneme','miktar'=>1];
$samplePage=\Omnigo\InventoryCatalogPage::normalize('pttavm',[$sample],0,0);
verifyCatalogSummary($samplePage['items']===[]&&$samplePage['next']&&$samplePage['sourceIds']===['7'],'Yalnız örnek içeren kaynak sayfası imleci ilerletmeli.');
$real=[...$sample,'urunId'=>8,'urunAdi'=>'Deneme Seti','gtin'=>'8690000000001'];
$realPage=\Omnigo\InventoryCatalogPage::normalize('pttavm',[$sample,$real],0,0);
verifyCatalogSummary(array_column($realPage['items'],'id')===['8']&&$realPage['sourceIds']===['7','8'],'Gerçek ürünün adında deneme kelimesi bulunabilir.');
catalogSummaryListing($db,1,'PTT AVM','ptt-test','old-test','{"saleActive":0}',0,$now-10000);
$sampleKey=hash('sha256','ptt-test');
$db->run("UPDATE inventory_products SET sku='deneme',title='deneme' WHERE company_id=1 AND product_key=?",[$sampleKey]);
$db->run("UPDATE inventory_listings SET sku='deneme' WHERE company_id=1 AND product_key=?",[$sampleKey]);
$db->run('INSERT INTO inventory_removed_products(company_id,product_key,actor_id,removed_at) VALUES(1,?,0,?)',[$sampleKey,gmdate('c')]);
$clean=$service->forProviders(1,['pttavm'],$now)['pttavm'];
verifyCatalogSummary($clean['catalogRecords']===0&&$clean['missingNameRecords']===0&&$clean['staleRecords']===0,'Kapsam dışı bırakılan geçmiş deneme kaydı eksik veya eski olarak sayılmamalı.');
echo 'GEÇTİ: '.$checks." sağlayıcı katalog özeti denetimi.\n";
