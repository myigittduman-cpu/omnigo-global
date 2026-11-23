<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{ActiveMarketplaceListings,ActiveMarketplaceListingRoutes,Database,HttpError,InventorySchema,MarketplaceStockPilot,MarketplaceStockSchema,Security};
$checks=0;
function checkPilot(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException($message);
    $checks++;
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);MarketplaceStockSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$now=time();
function catalog(Database $db,int $company,string $provider,int $now):void{
    $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES(?,?,?,1,?)',[$company,'integration_'.$provider,'{}',gmdate('c',$now-1000)]);
    $db->run('INSERT INTO inventory_catalog_jobs(company_id,provider,last_success) VALUES(?,?,?)',[$company,$provider,gmdate('c',$now-15)]);
    $db->run("INSERT INTO inventory_catalog_progress(company_id,provider,state,started_at,completed_at) VALUES(?,?,'complete',?,?)",[$company,$provider,$now-180,$now-15]);
}
function listing(Database $db,int $company,string $provider,string $id,string $sku,mixed $active,?int $quantity,int $seen,?string $product=null,?string $title=null):array{
    $product=hash('sha256',$product??$id);$key=hash('sha256',$id);
    if(!$db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$product]))
        $db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$product,$title??'Örnek ürün '.$id,$sku,'','',gmdate('c',$seen)]);
    $evidence=['saleActive'=>$active];if($provider==='Hepsiburada')$evidence['sourceCatalogId']='HB-'.$id;
    $db->run('INSERT INTO inventory_listings VALUES(?,?,?,?,?,?,?,?,?)',[$company,$provider,$key,$id,$product,$sku,json_encode($evidence),$quantity,gmdate('c',$seen)]);
    return [$product,$key];
}
foreach(['hepsiburada','n11','trendyol','pttavm','koctas','pazarama','idefix'] as $provider)catalog($db,2,$provider,$now);
catalog($db,1,'hepsiburada',$now);
$from=fn($provider,$id,$sku,$active,$q)=>listing($db,2,$provider,$id,$sku,$active,$q,$now-30);
$good=$from('Hepsiburada','HB-active','ABC_50%',true,7);
$excluded=$from('n11','closed','n11-closed',false,22);
$zero=$from('Trendyol','9988776655443','shared-zero',1,0);
$other=$from('PTT AVM','live-ptt','qty-unknown',1,null);
$from('Hepsiburada','no-evidence','unknown',null,22);
$from('Hepsiburada','malformed','false positive','1',6);
$from('Hepsiburada','old-order-sku-marker','deneme-001',1,2);
listing($db,2,'PTT AVM','ptt-test','regularSku',true,1,$now-24,null,'Test ürünü');
listing($db,2,'Hepsiburada','old-observation','too-old',true,1,$now-600);
listing($db,2,'Hepsiburada','future','clock-mismatch',true,1,$now+500);
listing($db,1,'Hepsiburada','HB-active','other-secret',true,99,$now-30);
$from('Hepsiburada','order-sku:old','archive-only',true,3);
$from('Pazarama','approval','approval-only',true,88);
$from('idefix','catalog','only-ready',true,88);
$service=new ActiveMarketplaceListings($db);
$read=fn(string $q='',string $m='',int $p=0)=>$service->page(2,$q,$m,$p);
$first=$read();$items=array_column($first['items'],null,'sku');
checkPilot($first['total']===3&&$first['pageSize']===15,'Yalnız taze, bu ilana ait gerçek aktiflik kanıtı.');
checkPilot($first['providers']===['Hepsiburada','PTT AVM','Trendyol'],'Doğrulanmış sağlayıcı seçenekleri.');
checkPilot($items['ABC_50%']['quantity']===7&&$items['qty-unknown']['quantity']===null&&$items['shared-zero']['quantity']===0,'Pozitif, sıfır ve eksik kaynak stokları ayrı.');
checkPilot($read('_50%')['total']===1&&$read('other-secret')['total']===0,'Arama kalıbı ve mağaza sınırı.');
checkPilot(count($read('eşleşmeyen','Hepsiburada')['providers'])===3,'Arama ve sağlayıcı filtrelerinden bağımsız seçenekler.');
checkPilot($items['qty-unknown']['writeTarget']===null&&$items['ABC_50%']['writeTarget']['sku']==='ABC_50%','Gönderilebilir hedefin pozitif kimliği ve bilinmeyen miktar.');
checkPilot($service->writableTarget(2,...$good)['provider']==='Hepsiburada'&&$service->writableTarget(2,...$zero)['quantity']===0,'Liste ve yazma sorgusu aynı adaylar.');
checkPilot($service->writableTarget(2,...$excluded)===null&&$service->writableTarget(2,...$other)===null,'Kapalı ve yazması desteklenmeyen hedef yok.');
$db->run("UPDATE inventory_catalog_jobs SET error='deneme hatası' WHERE company_id=2 AND provider='trendyol'");
checkPilot($service->writableTarget(2,...$zero)===null&&$read('','Trendyol')['total']===0,'Kaynak hata durumunda yazma hedefi ve liste düşer.');
$db->run("UPDATE inventory_catalog_jobs SET error=NULL WHERE company_id=2 AND provider='trendyol'");
$db->run("UPDATE records SET updated=? WHERE company_id=2 AND kind='integration_hepsiburada'",[gmdate('c',$now-1)]);
checkPilot($service->writableTarget(2,...$good)===null,'Yeni bağlantıdan önceki eski katalog kullanılamaz.');
$db->run("UPDATE records SET updated=? WHERE company_id=2 AND kind='integration_hepsiburada'",[gmdate('c',$now-1000)]);
for($i=1;$i<=17;$i++)$last=$from('n11','n-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'n-SKU-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),true,$i);
$n=$read('','n11',999);
checkPilot($n['total']===17&&$n['page']===1&&count($n['items'])===2&&$service->writableTarget(2,...$last)!==null,'15 li sayfalama ve ikinci sayfadaki hedef doğrulaması.');
$from('n11','duplicate-sku','n-SKU-017',true,6);
checkPilot($service->writableTarget(2,...$last)===null,'Aynı kanaldaki belirsiz SKU gönderim hedefi olamaz.');
$db->run("UPDATE inventory_listings SET observed_at=? WHERE company_id=2 AND provider='n11' AND remote_id='duplicate-sku'",[gmdate('c',$now-86400)]);
checkPilot($service->writableTarget(2,...$last)!==null,'Eski ilanın aynı satıcı kodunu taşıması güncel tekil ilanı engellemez.');
listing($db,2,'n11','HB-active','unique-n11',true,1,$now-30);
checkPilot($service->writableTarget(2,...$good)===null,'İki kanalın hash çakışması tek hedef kabul edilmez.');
$target=listing($db,2,'n11','n-pending','one-pending',true,10,$now-10);
$db->run("INSERT INTO marketplace_stock_requests(company_id,request_id,product_key,listing_key,sku,remote_id,quantity,source_quantity,connection_version,request_hash,state,actor_id,created,updated) VALUES(2,'pilot:n11:0001',?,?,'one-pending','n-pending',4,10,1,'qa','uncertain',1,?,?)",[...$target,gmdate('c'),gmdate('c')]);
checkPilot($service->writableTarget(2,...$target)['requestId']==='pilot:n11:0001','Belirsiz iş formu tekrar gönderime açmaz.');
$config=tempnam(sys_get_temp_dir(),'pilot-scope-');
try{
    file_put_contents($config,json_encode(['companies'=>[2=>hash('sha256','Örnek B')]]));
    $pilot=MarketplaceStockPilot::load($config);
    checkPilot($pilot->enabled(['id'=>2,'name'=>'Örnek B'])&&!$pilot->enabled(['id'=>2,'name'=>'Örnek A'])&&!$pilot->enabled(['id'=>1,'name'=>'Örnek B']),'Sunucu kimlik kaydı için hem id hem ad doğrulandı.');
    file_put_contents($config,'{bad');
    checkPilot(!MarketplaceStockPilot::load($config)->enabled(['id'=>2,'name'=>'Örnek B']),'Bozuk ayar güvenli kapalı.');
}finally{unlink($config);}
checkPilot(!MarketplaceStockPilot::load($config)->enabled(['id'=>2,'name'=>'Örnek B']),'Ayar yokken kapalı.');
$route=new ActiveMarketplaceListingRoutes($db,new Security($db,[]));
$_GET=['company'=>'2'];
try{$route->handle('/api/inventory/active-marketplace-listings','GET',['role'=>'client','company_id'=>2]);throw new RuntimeException('Müşteri reddedilmedi');}
catch(HttpError $error){checkPilot($error->getCode()===403,'Müşteri doğrudan URL ile ulaşamaz.');}
$db->run("INSERT INTO company_profiles(company_id,payload) VALUES(2,?)",[json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);
$db->run("INSERT INTO inventory_jobs(company_id,enabled,lease_token,lease_until) VALUES(2,1,'qa-worker',?)",[$now+120]);
$normal=new Omnigo\InventoryStock($db);
$catalog=new Omnigo\InventoryCatalog($db);
$source=['id'=>'real-catalog-1','sku'=>'REAL-SKU-1','title'=>'Katalogdaki ürün','quantity'=>7,'saleActive'=>true];
$real=$catalog->ingest(2,'n11',$source);
$snapshot=$read();
$manual=(new Omnigo\InventoryManual($db))->create(2,['title'=>'Yalnız mağazada','sku'=>'LOCAL-001','brand'=>'','model'=>'','barcode'=>'','quantity'=>23,'connect'=>false,'requestId'=>'test-local-stock-0001'],1);
checkPilot($normal->product(2,$manual['key'])['quantity']==23&&$read()===$snapshot,'Manuel ürün Stok Yönetimine eklendi, pazaryeri tablosu aynı kaldı.');
$normal->change(2,$real,'test-local-stock-0002',(int)$normal->product(2,$real)['version'],'initialize',20,1,'Başlangıç stoğu','qa-worker');
checkPilot($normal->product(2,$real)['quantity']==20&&$read('REAL-SKU-1')['items'][0]['quantity']===7,'Manuel adet ile ilanın kaynaktaki adedi birbirinden bağımsız.');
$source['quantity']=4;
$catalog->ingest(2,'n11',$source);
checkPilot($read('REAL-SKU-1')['items'][0]['quantity']===4&&$normal->product(2,$real)['quantity']==20,'Sonraki katalog aktarımı güncel ilan adedini getirdi.');
checkPilot((int)$db->one("SELECT COUNT(*) n FROM marketplace_stock_requests WHERE request_id<>'pilot:n11:0001'")['n']===0,'Yerel işlemler ve katalog okuması dışarı stok göndermedi.');
$card=listing($db,2,'Hepsiburada','cross-hb','QA-CROSS',true,0,$now-30,null,'Stok kartı modeli');
listing($db,2,'Trendyol','3210987654321','QA-CROSS-TR',true,0,$now-30,'cross-hb','Başka pazaryeri başlığı');
listing($db,2,'PTT AVM','cross-ptt','QA-CROSS-PTT',true,null,$now-30,'cross-hb');
$linked=listing($db,2,'n11','cross-n11','QA-CROSS-N11',true,6,$now-30,null,'Alternatif mağaza adı');
$db->run("INSERT INTO inventory_links(company_id,product_key,provider,source_product,match_code,state,reason,requested_at)
    VALUES(2,?,'n11',?,'QA-CROSS-N11','active','',?)",[$card[0],$linked[0],gmdate('c')]);
$shared=$read('Alternatif mağaza adı','n11');
checkPilot($shared['total']===1&&$shared['items'][0]['cardKey']===$card[0]
    &&$shared['items'][0]['providers']===['Hepsiburada','n11','PTT AVM','Trendyol']
    &&$shared['items'][0]['mixedQuantity']===true&&$shared['items'][0]['quantity']===null,'Dört ilan tek kartta ve aynı kartın farklı kaynak stokları korunur.');
checkPilot(in_array($linked[0],array_column($service->writableCardTargets(2,$card[0]),'productKey'),true),'Aktarımda bağlı ilanın gerçek ürün kimliği korunur.');
listing($db,2,'Koçtaş','cross-other','QA-CROSS',true,0,$now-30,null,'Gerçekte farklı ürün');
checkPilot($read('QA-CROSS')['total']===2,'Aynı stok kodu tek başına farklı ürünleri birleştirmez.');
echo "Aktif ilan ve pilot için $checks kontrol geçti.\n";

