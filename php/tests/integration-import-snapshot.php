<?php
declare(strict_types=1);

// Read-only legacy projection tests. All provider data and credentials below are synthetic.
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,HepsiburadaOrderDiscovery,IntegrationImportRuns,IntegrationImportSchema,IntegrationImportSnapshot,InventoryProgress,InventorySchema,NotificationSchema,RecentOrderSchema};

$checks=0;
function verifySnapshot(bool $condition,string $message):void
{
    global $checks;
    if(!$condition)throw new RuntimeException($message);
    $checks++;
}

function snapshotDatabase():Database
{
    $db=new Database(['dsn'=>'sqlite::memory:']);
    $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
    InventorySchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);IntegrationImportSchema::install($db);
    $db->run("INSERT INTO companies VALUES(1,'Birinci müşteri'),(2,'İkinci müşteri')");
    foreach([1,2] as $company)$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both'],'pages'=>['inventory'=>'shown']]],JSON_THROW_ON_ERROR)]);
    return $db;
}

function snapshotRecord(Database $db,int $company,string $kind,mixed $value,int $at,int $version=1):void
{
    $payload=is_string($value)?$value:json_encode($value,JSON_THROW_ON_ERROR);
    $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,?,?)',[$company,$kind,$payload,$version,gmdate('c',$at)]);
}

function snapshotState(Database $db):string
{
    $tables=['records','jobs','inventory_tracking','inventory_catalog_jobs','inventory_catalog_progress','inventory_products','inventory_listings','order_notifications','integration_import_runs'];
    $state=[];foreach($tables as $table)$state[$table]=$db->all('SELECT * FROM '.$table.' ORDER BY 1,2');
    return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}

function snapshotByProvider(array $items,string $provider):array
{
    foreach($items as $item)if($item['provider']===$provider)return $item;
    throw new RuntimeException('Sağlayıcı özeti bulunamadı: '.$provider);
}

function snapshotReplace(Database $db,int $company,string $kind,array $value,int $at):void
{
    $db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE company_id=? AND kind=?',[json_encode($value,JSON_THROW_ON_ERROR),gmdate('c',$at),$company,$kind]);
}

function snapshotRead(Database $db,int $company):array
{
    $before=snapshotState($db);$db->pdo->exec('PRAGMA query_only=ON');
    try{$items=(new IntegrationImportSnapshot($db))->forCompany($company);verifySnapshot($before===snapshotState($db),'Güncel kaynak durumu yalnız okunmalı.');return $items;}
    finally{$db->pdo->exec('PRAGMA query_only=OFF');}
}

$now=time()-1000;
$db=snapshotDatabase();
// Malformed/encrypted payloads deliberately cannot be decoded; only record metadata is public.
foreach(['n11','idefix','pazarama','hepsiburada','pttavm','trendyol','koctas'] as $provider)snapshotRecord($db,1,'integration_'.$provider,'ENCRYPTED-NOT-JSON: synthetic-secret-'.$provider,$now,3);
snapshotRecord($db,2,'integration_n11','{malformed-private-company-two',$now,8);
snapshotRecord($db,1,'sync_n11',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now+100),'sampleOnly'=>false,'salesPublished'=>true,'snapshotRevision'=>'ignored-private-revision'],$now+100);
snapshotRecord($db,1,'sync_idefix',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now-1),'ordersPublished'=>true],$now+100);
snapshotRecord($db,1,'sync_pazarama',['phase'=>'syncing','lastSuccessAt'=>gmdate('c',$now+100),'scan'=>['page'=>2]],$now+100);
snapshotRecord($db,1,'sync_hepsiburada',['phase'=>'live','lastSuccessAt'=>gmdate('c',$now+100),'ordersPublished'=>true,'feedCompletedAt'=>array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,gmdate('c',$now+100)),'pendingOrders'=>[]],$now+100);
snapshotRecord($db,1,'sync_pttavm',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now+100),'ordersPublished'=>true,'recentOrders'=>['lastSuccessAt'=>gmdate('c',$now+100)]],$now+100);
snapshotRecord($db,1,'sync_trendyol',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now+100),'ordersPublished'=>true,'salesPublished'=>true,'startDate'=>gmdate('c',$now-100),'watermark'=>gmdate('c',$now+100)],$now+100);
snapshotRecord($db,2,'sync_n11',['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now+100),'sampleOnly'=>false,'salesPublished'=>true],$now+100);
foreach(['n11','hepsiburada'] as $provider)foreach(range(1,2) as $i)snapshotRecord($db,1,'order_'.$provider,['externalId'=>$provider.'-'.$i],$now+100);
foreach(range(1,4) as $i)snapshotRecord($db,2,'order_n11',['externalId'=>'other-'.$i],$now+100);
foreach(['Hepsiburada','n11','idefix','Pazarama','PTT AVM','Trendyol','Koçtaş'] as $label)$db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(?,?,'pending',?)",[1,$label,$now+200]);
$before=snapshotState($db);
$db->pdo->exec('PRAGMA query_only=ON');
$snapshot=new IntegrationImportSnapshot($db);
$items=$snapshot->forCompany(1);
verifySnapshot(count($items)===7,'Yedi yapılandırılmış sağlayıcı eski bağlantı özeti oluşturmalı.');
foreach($items as $item){
    verifySnapshot(($item['kind']??'')==='live_snapshot'&&$item['countsScope']==='current_snapshot','Eski bağlantı görünümü yeni API aktarım koşusu gibi sunulmamalı.');
    verifySnapshot($item['runId']==='snapshot-'.$item['provider'].'-3','Özet kimliği sağlayıcı ve kayıt sürümüne bağlı kararlı olmalı.');
    verifySnapshot(!isset($item['noticeId'])&&$item['startedAt']===null&&$item['finishedAt']===null,'Eski bağlantı için geçmiş başlangıç/bitiş veya yeni bildirim uydurulmamalı.');
}
verifySnapshot(snapshotByProvider($items,'n11')['state']==='complete','Yeni API kaydından sonra doğrulanmış tam n11 görüntüsü tamam görünmeli.');
verifySnapshot(snapshotByProvider($items,'idefix')['state']!=='complete','API güncellemesinden eski başarı yeni bağlantının başarısı sayılamaz.');
verifySnapshot(snapshotByProvider($items,'pazarama')['state']!=='complete','Yarım tarama son başarı tarihi olsa da tamam gösterilmemeli.');
verifySnapshot(snapshotByProvider($items,'hepsiburada')['state']==='complete','Bütün güncel Hepsiburada akışları tamamlanınca canlı özet hazır olmalı.');
verifySnapshot(snapshotByProvider($items,'pttavm')['state']!=='complete','PTT yalnız yakın tarihli sipariş başarısıyla geçmiş aktarım tamamlanmış sayılamaz.');
verifySnapshot(snapshotByProvider($items,'koctas')['state']!=='complete','Başarı kanıtı olmayan sağlayıcı tamamlanmış sayılamaz.');
verifySnapshot(snapshotByProvider($items,'trendyol')['stages']['stock']['status']==='not_enabled','Katalog desteği olan Trendyol için açılmamış stok aktarımı açıkça belirtilmeli; özet okuması kendiliğinden stok görevi başlatmamalı.');
verifySnapshot(snapshotByProvider($items,'n11')['counts']['orders']===2,'İlk müşterinin n11 kayıt sayısı diğer müşteriyle karışmamalı.');
$other=$snapshot->forCompany(2);
verifySnapshot(count($other)===1&&$other[0]['provider']==='n11'&&$other[0]['counts']['orders']===4,'Diğer müşterinin sağlayıcı ve sayıları yalnız kendine ait olmalı.');
verifySnapshot(!str_contains(json_encode([$items,$other],JSON_THROW_ON_ERROR),'synthetic-secret')&&!str_contains(json_encode($items,JSON_THROW_ON_ERROR),'ignored-private-revision'),'Şifreli API payload veya gizli revizyon rapora taşınmamalı.');
$excluded=$snapshot->forCompany(1,['n11','hepsiburada']);
verifySnapshot(count($excluded)===5&&!array_intersect(['n11','hepsiburada'],array_column($excluded,'provider')),'Gerçek koşusu bulunan sağlayıcılar fallback listesine ikinci kez eklenmemeli.');
verifySnapshot($before===snapshotState($db),'Salt okunur özet hiçbir kaydı, işi, olayı veya aktarım koşusunu değiştirmemeli.');
verifySnapshot($db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0&&$db->one('SELECT COUNT(*) n FROM integration_import_runs')['n']===0,'Sağlıklı eski bağlantıları okumak bildirim veya yeni koşu üretmemeli.');

$db->pdo->exec('PRAGMA query_only=OFF');
$realRun=$db->transaction(fn()=>(new IntegrationImportRuns($db))->begin(1,'n11',3,'synthetic-current-revision',$now+300));
$mergedBefore=snapshotState($db);$db->pdo->exec('PRAGMA query_only=ON');
$merged=(new IntegrationImportRuns($db))->listing(1);
verifySnapshot(count($merged)===7&&count(array_unique(array_column($merged,'provider')))===7,'Gerçek koşular ve eski bağlantı özetleri sağlayıcı başına tek kayıt olarak birleşmeli.');
verifySnapshot(snapshotByProvider($merged,'n11')['runId']===$realRun&&($merged[0]['kind']??'')!=='live_snapshot','Gerçek koşu mevcutsa aynı sağlayıcının canlı özeti onun yerini almamalı.');
verifySnapshot($mergedBefore===snapshotState($db),'Birleşik listeyi okumak gözlem veya yeni koşu yazımı yapmamalı.');

$states=snapshotDatabase();
foreach(['n11','idefix','pazarama','koctas'] as $provider)snapshotRecord($states,1,'integration_'.$provider,'PRIVATE-MALFORMED-'.$provider,$now,1);
snapshotRecord($states,1,'sync_n11',['phase'=>'error','failure'=>['code'=>401]],$now+100);
snapshotRecord($states,1,'sync_idefix',['phase'=>'error','failure'=>['code'=>503]],$now+100);
snapshotRecord($states,1,'sync_pazarama',['phase'=>'ready','lastSuccessAt'=>'invalid-time'],$now+100);
$states->run("INSERT INTO jobs(company_id,provider,state,attempts,error,next_run) VALUES(1,'n11','blocked',1,?,?),(1,'idefix','pending',2,?,?)",['Pazaryeri yetkisi doğrulanamadı.',$now+500,'503 synthetic-sensitive-error',$now+500]);
$stateBefore=snapshotState($states);$states->pdo->exec('PRAGMA query_only=ON');
$stateItems=(new IntegrationImportSnapshot($states))->forCompany(1);
verifySnapshot(snapshotByProvider($stateItems,'n11')['state']==='failed','Engellenen eski bağlantı açık hata durumu göstermeli.');
verifySnapshot(snapshotByProvider($stateItems,'idefix')['state']==='retry'&&snapshotByProvider($stateItems,'idefix')['nextRetryAt']===gmdate('c',$now+500),'Geçici hata mevcut yeniden deneme zamanıyla gösterilmeli.');
verifySnapshot(snapshotByProvider($stateItems,'pazarama')['state']!=='complete'&&snapshotByProvider($stateItems,'koctas')['state']!=='complete','Bozuk veya eksik başarı zamanı hazır bağlantı gibi gösterilmemeli.');
verifySnapshot(!str_contains(json_encode($stateItems,JSON_THROW_ON_ERROR),'synthetic-sensitive-error'),'Ham sağlayıcı hata metni ve olası gizli bilgiler kısaltılmış rapora taşınmamalı.');
verifySnapshot($stateBefore===snapshotState($states),'Hatalı eski bağlantıyı okumak bile retry durumunu, takvimini veya kaydını değiştirmemeli.');

$coverage=snapshotDatabase();
foreach(['hepsiburada','pttavm','trendyol','koctas'] as $provider)snapshotRecord($coverage,1,'integration_'.$provider,'opaque-private-payload',$now,1);
$fresh=gmdate('c',$now+100);
$hb=['phase'=>'live','lastSuccessAt'=>$fresh,'feedCompletedAt'=>array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,$fresh)];
$hb['feedCompletedAt'][HepsiburadaOrderDiscovery::FEEDS[0]]=gmdate('c',$now-1);
snapshotRecord($coverage,1,'sync_hepsiburada',$hb,$now+100);
snapshotRecord($coverage,1,'sync_pttavm',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyLastSuccessAt'=>gmdate('c',$now-1)],$now+100);
snapshotRecord($coverage,1,'sync_trendyol',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyNext'=>$now+50,'historyEnd'=>$now+100,'recentThrough'=>$now+100],$now+100);
snapshotRecord($coverage,1,'sync_koctas',['lastSuccessAt'=>$fresh,'offset'=>100,'detailsVersion'=>1],$now+100);
$partial=snapshotRead($coverage,1);
foreach(['hepsiburada','pttavm','trendyol','koctas'] as $provider)verifySnapshot(snapshotByProvider($partial,$provider)['state']!=='complete',$provider.': sağlayıcıya özgü eksik kapsam tamamlanmış gösterilmemeli.');
$hb['feedCompletedAt']=array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,$fresh);
snapshotReplace($coverage,1,'sync_hepsiburada',[...$hb,'pendingOrders'=>['unresolved-order'=>true]],$now+100);
verifySnapshot(snapshotByProvider(snapshotRead($coverage,1),'hepsiburada')['state']!=='complete','Hepsiburada bekleyen sipariş ayrıntısını bitirmeden tamamlanmamalı.');
snapshotReplace($coverage,1,'sync_hepsiburada',$hb,$now+100);
snapshotReplace($coverage,1,'sync_pttavm',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyLastSuccessAt'=>$fresh],$now+100);
snapshotReplace($coverage,1,'sync_trendyol',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyNext'=>$now+100,'historyEnd'=>$now+100,'recentThrough'=>$now+100],$now+100);
snapshotReplace($coverage,1,'sync_koctas',['lastSuccessAt'=>$fresh,'offset'=>0,'detailsVersion'=>1],$now+100);
$full=snapshotRead($coverage,1);
foreach(['hepsiburada','pttavm','trendyol','koctas'] as $provider)verifySnapshot(snapshotByProvider($full,$provider)['state']==='complete',$provider.': sağlayıcının doğrulanmış tam kapsamı kabul edilmeli.');

$catalog=snapshotDatabase();snapshotRecord($catalog,1,'integration_n11','opaque',$now,1);
snapshotRecord($catalog,1,'sync_n11',['phase'=>'ready','lastSuccessAt'=>$fresh],$now+100);
$catalog->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(1,?)',[gmdate('c',$now-50)]);
(new InventoryProgress($catalog))->ensure(1,'n11');
$catalog->run("UPDATE inventory_catalog_progress SET state='complete',started_at=?,completed_at=?,unknown_quantities=0 WHERE company_id=1",[$now-20,$now+10]);
$catalog->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',[$fresh]);
$oldCatalog=snapshotByProvider(snapshotRead($catalog,1),'n11');
verifySnapshot($oldCatalog['state']!=='complete'&&$oldCatalog['stages']['stock']['status']!=='complete','API güncellenmeden başlamış katalog taraması yeni katalog kanıtı sayılamaz.');
$catalog->run('UPDATE inventory_catalog_progress SET started_at=?,completed_at=? WHERE company_id=1',[$now+5,$now+10]);
verifySnapshot(snapshotByProvider(snapshotRead($catalog,1),'n11')['state']==='complete','API kaydı sonrası başlayan ve eksiksiz tamamlanan katalog kabul edilmeli.');
$catalog->run("UPDATE inventory_catalog_progress SET state='incomplete',unknown_quantities=1 WHERE company_id=1");
verifySnapshot(snapshotByProvider(snapshotRead($catalog,1),'n11')['state']==='retry','Eksik kaynak stok miktarı yeniden kontrol durumunda kalmalı.');
$catalog->run("UPDATE inventory_catalog_progress SET state='complete',unknown_quantities=0 WHERE company_id=1");
snapshotRecord($catalog,1,'order_retry_n11',['externalId'=>'synthetic-conflict'],$now+100);
verifySnapshot(snapshotByProvider(snapshotRead($catalog,1),'n11')['state']!=='complete','İzole sipariş hatası varken eski bağlantı tam başarılı sunulmamalı.');
$catalog->run("DELETE FROM records WHERE company_id=1 AND kind='order_retry_n11'");
snapshotReplace($catalog,1,'sync_n11',['phase'=>'ready','lastSuccessAt'=>$fresh,'financialSnapshotHeld'=>true],$now+100);
verifySnapshot(snapshotByProvider(snapshotRead($catalog,1),'n11')['state']!=='complete','Doğrulanmayı bekleyen mali görüntü varken tamam gösterilmemeli.');

// Trendyol support uses the same read-only, tenant-specific full-catalogue proof.
$trendyolCatalog=snapshotDatabase();snapshotRecord($trendyolCatalog,1,'integration_trendyol','opaque-trendyol-envelope',$now,1);
snapshotRecord($trendyolCatalog,1,'sync_trendyol',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyNext'=>$now+100,'historyEnd'=>$now+100,'recentThrough'=>$now+100],$now+100);
$trendyolCatalog->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(1,?)',[gmdate('c',$now-50)]);
(new InventoryProgress($trendyolCatalog))->ensure(1,'trendyol');
$trendyolCatalog->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,pages_done=1,items_done=1 WHERE company_id=1 AND provider='trendyol'",[$now+5]);
$trendyolPartial=snapshotByProvider(snapshotRead($trendyolCatalog,1),'trendyol');
verifySnapshot($trendyolPartial['state']!=='complete'&&$trendyolPartial['stages']['stock']['status']==='running','Trendyol siparişleri hazır olsa bile eksik katalog sayfası tamam gösterilmemeli.');
$trendyolCatalog->run("UPDATE inventory_catalog_progress SET state='incomplete',unknown_quantities=1,completed_at=? WHERE company_id=1 AND provider='trendyol'",[$now+10]);
verifySnapshot(snapshotByProvider(snapshotRead($trendyolCatalog,1),'trendyol')['state']==='retry','Trendyol kaynak stok miktarı eksik olduğunda yeniden kontrol durumunda kalmalı.');
$trendyolCatalog->run("UPDATE inventory_catalog_progress SET state='complete',unknown_quantities=0,completed_at=?,pages_done=2,items_done=2 WHERE company_id=1 AND provider='trendyol'",[$now+10]);
$trendyolCatalog->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1 AND provider='trendyol'",[$fresh]);
$trendyolProduct=hash('sha256','snapshot-trendyol-product');
foreach([1,2] as $company){
    $trendyolCatalog->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$trendyolProduct,'Sentetik katalog ürünü','TY-TEST-SKU','','',$fresh]);
    $trendyolCatalog->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$company,'Trendyol',hash('sha256','snapshot-trendyol-listing'),'TY-REMOTE',$trendyolProduct,'TY-TEST-SKU','{}',$company===1?0:99,$fresh]);
}
$trendyolComplete=snapshotByProvider(snapshotRead($trendyolCatalog,1),'trendyol');
verifySnapshot($trendyolComplete['state']==='complete'&&$trendyolComplete['stages']['products']['status']==='complete'&&$trendyolComplete['stages']['stock']['status']==='complete','Trendyol doğrulanmış tam ürün ve stok kataloğu tamamlanmış görünmeli.');
verifySnapshot($trendyolComplete['counts']['products']===1&&$trendyolComplete['counts']['stockRecords']===1,'Trendyol sıfır kaynak stoku geçerli kayıt olarak saymalı ve aynı SKU kullanan başka müşterinin ürünü rapora karışmamalı.');

// One malformed provider state cannot hide or stop the other four healthy providers.
$malformed=snapshotDatabase();
foreach(['n11','idefix','pazarama','hepsiburada','pttavm','trendyol','koctas'] as $provider)snapshotRecord($malformed,1,'integration_'.$provider,'PRIVATE-ENVELOPE',$now,2);
snapshotRecord($malformed,1,'sync_n11','{"private-invalid-json":"NEVER-EXPOSE-THIS',$now+900);
snapshotRecord($malformed,1,'sync_idefix','"PRIVATE-SCALAR-STRING"',$now+900);
snapshotRecord($malformed,1,'sync_pazarama','12345',$now+900);
snapshotRecord($malformed,1,'sync_hepsiburada',['phase'=>'live','lastSuccessAt'=>$fresh,'checkedAt'=>gmdate('c',$now+101),'deliveryLastPageAt'=>gmdate('c',$now+102),'feedCompletedAt'=>array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,$fresh)],$now+900);
snapshotRecord($malformed,1,'sync_pttavm',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyLastSuccessAt'=>gmdate('c',$now+103)],$now+900);
snapshotRecord($malformed,1,'sync_trendyol',['phase'=>'ready','lastSuccessAt'=>$fresh,'historyNext'=>$now+100,'historyEnd'=>$now+100,'recentThrough'=>$now+100,'lastPageAt'=>gmdate('c',$now+104)],$now+900);
snapshotRecord($malformed,1,'sync_koctas',['lastSuccessAt'=>$fresh,'offset'=>0,'detailsVersion'=>1],$now+900);
$malformedItems=snapshotRead($malformed,1);
verifySnapshot(count($malformedItems)===7,'Tek sağlayıcının bozuk durumu diğer sağlayıcıları listeden düşürmemeli.');
foreach(['n11','idefix','pazarama'] as $provider){
    $item=snapshotByProvider($malformedItems,$provider);
    verifySnapshot($item['state']==='unknown'&&!empty($item['warnings']),$provider.': bozuk veya scalar JSON güvenli bilinmiyor uyarısıyla sunulmalı.');
    verifySnapshot($item['updatedAt']===null,$provider.': yalnız kayıt satırı tarihi kaynak doğrulama zamanı gibi sunulmamalı.');
}
foreach(['hepsiburada','pttavm','trendyol','koctas'] as $provider)verifySnapshot(snapshotByProvider($malformedItems,$provider)['state']==='complete',$provider.': diğer sağlayıcının bozuk JSON verisi sağlıklı bağlantıyı etkilememeli.');
$encodedMalformed=json_encode($malformedItems,JSON_THROW_ON_ERROR);
verifySnapshot(!str_contains($encodedMalformed,'NEVER-EXPOSE')&&!str_contains($encodedMalformed,'PRIVATE-SCALAR')&&!str_contains($encodedMalformed,'PRIVATE-ENVELOPE'),'Bozuk kayıt veya scalar payload içerikleri rapor metnine yansımamalı.');
verifySnapshot(snapshotByProvider($malformedItems,'hepsiburada')['updatedAt']===gmdate('c',$now+102),'Hepsiburada son kaynak kontrolü ve teslimat sayfası zamanının gerçeğini göstermeli.');
verifySnapshot(snapshotByProvider($malformedItems,'pttavm')['updatedAt']===gmdate('c',$now+103),'PTT kaynak tarihçesi başarısı kaynak güncelleme zamanına dahil edilmeli.');
verifySnapshot(snapshotByProvider($malformedItems,'trendyol')['updatedAt']===gmdate('c',$now+104),'Son kaynak sayfası zamanı güncel kayıt satırı tarihinden ayrılmalı.');

$queueOnly=snapshotDatabase();snapshotRecord($queueOnly,1,'integration_n11','OPAQUE',$now,1);
snapshotRecord($queueOnly,1,'sync_n11',['phase'=>'pending','nextRequestAt'=>$now+999],$now+900);
$queueReport=snapshotByProvider(snapshotRead($queueOnly,1),'n11');
verifySnapshot($queueReport['state']==='queued'&&$queueReport['updatedAt']===null,'API kaydetme kuyruğunun yeni records.updated tarihi kaynak aktarımı yapılmış gibi görünmemeli.');
$queueOnly->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_n11'",['{"phase":"pending","checkedAt":"invalid-source-time"}']);
verifySnapshot(snapshotByProvider(snapshotRead($queueOnly,1),'n11')['updatedAt']===null,'Geçersiz kaynak zaman damgası için uydurma güncel tarih dönülmemeli.');

$empty=snapshotDatabase();$empty->pdo->exec('PRAGMA query_only=ON');
verifySnapshot((new IntegrationImportSnapshot($empty))->forCompany(1)===[],'Hiç bağlantısı olmayan müşteri için hayalî sağlayıcı veya başarı üretilmemeli.');

echo "$checks eski bağlantı özeti: salt okuma, güncellik/tam tarama, şifreli payload koruması, müşteri ayrımı, fallback tekilleştirme ve yeni olay üretmeme kontrolü geçti.\n";
