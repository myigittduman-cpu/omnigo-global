<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,DeliveryRepository,DeliverySchema,FinanceOverviewRoutes,FinanceSchema,FinanceStore,HttpError,InventoryCatalog,
    InventoryPurchasePrices,InventorySchema,ProfitabilityCommission,ProfitabilityCommissionRates,ProfitabilityOrderCapture,ProfitabilityPrices,ProfitabilityReport,
    ProfitabilitySchema,RecentOrderSchema,Repository,Security};

function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function saveOrder(Database $db,int $company,string $provider,array $order):void
{
    $value=json_encode($order,JSON_THROW_ON_ERROR);
    if($provider==='koctas'){
        $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'order_koctas',?,?)",[$company,$value,gmdate('c')]);
        return;
    }
    $db->run('INSERT OR REPLACE INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',
        [$company,$provider,$order['externalId'],$value,gmdate('c')]);
}
function financialLine(array $product,int $gross,int $cancel=0,int $returned=0,?int $remaining=null):array
{
    return [...$product,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$returned,
        'netQuantity'=>$remaining??$product['quantity']];
}
function order(string $id,int $time,array $products,string $state='SHIPPING'):array
{
    return ['externalId'=>$id,'createdAt'=>gmdate('c',$time),'orders'=>1,'state'=>$state,'products'=>$products,
        'grossCents'=>array_sum(array_column($products,'grossCents')),'cancelCents'=>array_sum(array_column($products,'cancelCents')),
        'returnCents'=>array_sum(array_column($products,'returnCents'))];
}
function delivery(string $id,int $time,array $products,string $channel='Hepsiburada',?string $orderNumber=null):array
{
    return ['provider'=>$channel,'externalId'=>$id,'orderNumber'=>$orderNumber??$id,'createdAt'=>gmdate('c',$time),
        'buyerName'=>'Yerel test','city'=>'Ankara','phone'=>'','address'=>'','active'=>false,'products'=>[],'details'=>['products'=>$products]];
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
RecentOrderSchema::install($db);
DeliverySchema::install($db);
$repo=new Repository($db);
$company=$repo->createCompany('Bir mağaza');
$other=$repo->createCompany('Ayrı mağaza');
$actor=['role'=>'admin','id'=>1];
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL)");
$catalog=new InventoryCatalog($db);
$product=['lineId'=>'10','sku'=>'SKU-1','sellerStockCode'=>'SKU-1','title'=>'Örnek stok ürünü','quantity'=>2,
    'sourceProduct'=>['listingId'=>'LIST-1','barcode'=>'8690000000014']];
$extra=['lineId'=>'20','sku'=>'SKU-2','sellerStockCode'=>'SKU-2','title'=>'İkinci örnek ürün','quantity'=>1,
    'sourceProduct'=>['listingId'=>'LIST-2','barcode'=>'8690000000021']];
$initial=$catalog->ingest($company,'Hepsiburada',['id'=>'LIST-1','sku'=>'SKU-1','title'=>$product['title'],'barcode'=>'8690000000014','quantity'=>5]);
$second=$catalog->ingest($company,'Hepsiburada',['id'=>'LIST-2','sku'=>'SKU-2','title'=>$extra['title'],'barcode'=>'8690000000021','quantity'=>5]);
$prices=new InventoryPurchasePrices($db);
$set=fn(string $key,int $version,int $amount,string $request)=>$prices->save($company,
    ['productKey'=>$key,'version'=>$version,'amountCents'=>$amount,'requestId'=>$request],$actor);
$set($initial,0,20000,'test-profit-price-01');
$set($second,0,15000,'test-profit-price-02');
$clock=time();
$db->run('UPDATE inventory_purchase_prices SET updated=?',[gmdate('c',$clock-1000)]);

$capture=new ProfitabilityOrderCapture($db);
$observe=fn(array $value)=>$db->transaction(fn()=>$capture->observe($company,$value));
$observe(delivery('before-schema',$clock-800,[$product]));
ProfitabilitySchema::install($db);
$observe(delivery('before-activation',$clock-600,[$product]));
check($db->all('SELECT * FROM profitability_orders')===[],'Hazırlıkta sipariş yazılmaz.');
ProfitabilitySchema::activate($db);
$active=ProfitabilitySchema::startedAt($db);
ProfitabilitySchema::activate($db);
check(ProfitabilitySchema::startedAt($db)===$active,'Tekrar etkinleştirme başlangıç tarihini bozdu.');
$db->run('UPDATE profitability_activation SET started_at=?',[$clock-500]);
$observe(delivery('OLD',$clock-501,[$product]));
check($db->all('SELECT * FROM profitability_orders')===[],'Eski sipariş yeni rapora alınamaz.');

$a=$clock-400;
$b=$clock-300;
$received=$clock-230;
$newPrice=$clock-200;
$legacy=delivery('A',$a,[$product,$extra]);
$deliveryRepo=new DeliveryRepository($db,DeliveryCipher::withKey(str_repeat('x',32)));
$db->transaction(fn()=>$deliveryRepo->ingest($company,$legacy,false));
check((int)$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===1,'Canlı teslimat aktarımı kesildi.');
$observe($legacy);
$set($initial,1,30001,'test-profit-price-03');
$set($initial,1,30001,'test-profit-price-03');
check((int)$db->one('SELECT COUNT(*) n FROM profitability_price_versions WHERE product_key=?',[$initial])['n']===2,'Fiyat tekrarlı yazıldı.');
$db->run('UPDATE profitability_price_versions SET effective_at=? WHERE product_key=? AND version=2',[$newPrice*1000000+400000,$initial]);
$observe(delivery('B',$b,[$product]));
$observe(delivery('AFTER',$clock-150,[$product]));
$observe(delivery('AMBIGUOUS',$newPrice,[$product]));
$observe(delivery('NO-LISTING',$received,[[...$product,'sourceProduct'=>[]]]));
$observe(delivery('NO-LISTING',$received,[$product]));
$set($initial,2,100,'test-profit-price-04');
$db->run('UPDATE profitability_price_versions SET effective_at=? WHERE product_key=? AND version=3',[($clock-5)*1000000,$initial]);
$observe($legacy);

$gross=financialLine($product,95001);
$extraGross=financialLine($extra,31000);
foreach(['A'=>$a,'B'=>$b,'AFTER'=>$clock-150,'AMBIGUOUS'=>$newPrice,'NO-LISTING'=>$received] as $id=>$date)
    saveOrder($db,$company,'hepsiburada',order($id,$date,$id==='A'?[$gross,$extraGross]:[$gross]));
$cancelled=order('CANCELLED',$clock-120,[financialLine($product,95001,95001,0,0)],'CANCELED');
$observe(delivery('CANCELLED',$clock-120,[$product]));
saveOrder($db,$company,'hepsiburada',[...$cancelled,'orders'=>0]);
$observe(delivery('RETURNED',$clock-100,[$product]));
saveOrder($db,$company,'hepsiburada',order('RETURNED',$clock-100,[$gross]));
saveOrder($db,$company,'hepsiburada_sales',order('RETURNED',$clock-100,[financialLine($product,95001,0,95001,0)]));
$observe(delivery('PARTIAL',$clock-75,[$product]));
saveOrder($db,$company,'hepsiburada',order('PARTIAL',$clock-75,[financialLine($product,95001,47500,0,1)]));
$observe(delivery('MISMATCH',$clock-60,[$product]));
saveOrder($db,$company,'hepsiburada',[...order('MISMATCH',$clock-60,[$gross]),'grossCents'=>95004]);
$db->transaction(fn()=>$capture->observe($other,delivery('AFTER',$clock-150,[$product])));
saveOrder($db,$other,'hepsiburada',order('AFTER',$clock-150,[$gross]));

$start=gmdate('Y-m-d',$clock-86400);
$end=gmdate('Y-m-d',$clock+86400);
$filters=['from'=>$start,'to'=>$end,'channel'=>'all'];
$report=new ProfitabilityReport($db);
$data=$report->read($actor,$company,$filters);
$rows=array_column($data['rows'],null,'orderNumber');
check($data['total']===9&&$data['pageSize']===10,'Rapor sayısı veya sayfa boyutu yanlış.');
check($rows['A']['purchaseCents']===55000&&$rows['A']['commissionCents']===null&&$rows['A']['shippingCents']===null
    &&$rows['A']['profitCents']===null&&count($rows['A']['products'])===2,'Sipariş tutarı, iki ürün, adet veya kargo kuralı yanlış.');
check($rows['B']['purchaseCents']===40000&&$rows['AFTER']['purchaseCents']===60002,'Geç gelen ve güncel siparişler doğru fiyat sürümünü almalı.');
check($rows['AMBIGUOUS']['purchaseCents']===null&&$rows['NO-LISTING']['purchaseCents']===null,'Bilinmeyen fiyat veya tek başına SKU doldurulmamalı.');
check($rows['NO-LISTING']['purchaseStatus']==='pending'&&$rows['NO-LISTING']['profitCents']===null,'Kayıtlı bilinmeyen maliyet tekrar aktarımda değişti.');
check($rows['CANCELLED']['status']==='cancelled'&&$rows['RETURNED']['status']==='returned'
    &&$rows['RETURNED']['profitCents']===null&&$rows['CANCELLED']['profitCents']===null,'İptal/iade aktif kâra eklenemez.');
check($rows['PARTIAL']['purchaseCents']===30001&&$rows['PARTIAL']['salesCents']===47501
    &&$rows['PARTIAL']['profitCents']===null,'Kısmi iptalde kalan miktar veya gelir hatalı.');
check($rows['MISMATCH']['status']==='sales_pending','Tutarlarla ürünler uyuşmadığında kâr hesaplandı.');
check($report->read($actor,$other,$filters)['rows'][0]['purchaseCents']===null,'Firma sınırı aşıldı.');

$manual=new ProfitabilityCommissionRates($db);
$day=ProfitabilityCommission::day($a);
$tomorrow=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
$general=['channel'=>'Hepsiburada','sku'=>'','effectiveFrom'=>$day,'basisPoints'=>1000];
$specific=[...$general,'sku'=>'sku-1','basisPoints'=>750];
$manual->save($company,$general);
$manual->save($company,$specific);
$manual->save($company,$specific);
$manual->save($company,[...$specific,'effectiveFrom'=>$tomorrow,'basisPoints'=>1250]);
check(count($manual->all($company))===3&&$manual->all($other)===[],'Tarihli komisyon kaydı tekrarlı veya başka mağazaya yazıldı.');
try{$manual->save($company,[...$general,'basisPoints'=>10001]);throw new RuntimeException('Geçersiz oran kabul edildi.');}
catch(HttpError $error){check($error->getCode()===400,'Geçersiz oran hatası yanlış.');}
$rows=array_column($report->read($actor,$company,$filters)['rows'],null,'orderNumber');
check($rows['A']['commissionCents']===10225&&$rows['A']['commissionSource']==='manual'&&$rows['A']['purchaseCents']===55000,
    'Ürüne özel oran, genel oran veya orijinal alış maliyeti yanlış.');
check($rows['B']['commissionRateBasisPoints']===750&&$rows['PARTIAL']['commissionCents']===3563,'Gelecek oran ya da kısmi iptal yanlış uygulandı.');
check($report->read($actor,$other,$filters)['rows'][0]['commissionSource']==='pending','Manuel oran firma sınırını aştı.');

FinanceSchema::install($db);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'integration_hepsiburada','{}',?)",[$company,gmdate('c')]);
$connection=(string)$db->pdo->lastInsertId().':1';
$proof=['orderedAt'=>$a,'salesCents'=>126001,'fingerprint'=>ProfitabilityCommission::fingerprint([$gross,$extraGross]),
    'commissionCents'=>12100,'commissionRateBasisPoints'=>1100,'commissionSource'=>'marketplace'];
(new FinanceStore($db))->writeState($company,'pc:h:'.$day,['connection'=>$connection,'formatVersion'=>2,'rows'=>['A'=>$proof,
    'B'=>[...$proof,'orderedAt'=>$b,'salesCents'=>95001]]],null);
$rows=array_column($report->read($actor,$company,$filters)['rows'],null,'orderNumber');
check($rows['A']['commissionCents']===12100&&$rows['A']['commissionRateBasisPoints']===1100&&$rows['A']['profitCents']===null,
    'Teyitli pazaryeri komisyonu manuel oranın önüne geçmedi.');
check($rows['B']['commissionSource']==='manual','Tutarı uyuşsa bile farklı sipariş kalemleri eşleşti.');
$db->run("UPDATE records SET version=version+1 WHERE company_id=? AND kind='integration_hepsiburada'",[$company]);
$rows=array_column($report->read($actor,$company,$filters)['rows'],null,'orderNumber');
check($rows['A']['commissionSource']==='manual','Değişen bağlantının eski teyidi kullanıldı.');

foreach(range(1,12) as $index)$observe(delivery('PAGE-'.$index,$clock-40+$index,[$product]));
$table=$report->read($actor,$company,[...$filters,'channel'=>'Hepsiburada','page'=>2]);
check($table['total']===21&&$table['page']===2&&count($table['rows'])===10,'İkinci sayfa 10 satır olmalı.');
check(count($report->read($actor,$company,[...$filters,'page'=>3])['rows'])===1,'Üçüncü sayfa kalanı göstermeli.');
check($report->read($actor,$company,[...$filters,'channel'=>'Pazarama'])['total']===0,'Pazaryeri filtresi başka kaynak döndürdü.');
foreach([['role'=>'client'],['role'=>'guest']] as $unauthorized){
    try{(new FinanceOverviewRoutes($db,new Security($db,[])))->handle('/api/finance/profitability','GET',$unauthorized);}
    catch(HttpError $error){check($error->getCode()===403,'Yetkisiz hata kodu yanlış.');continue;}
    throw new RuntimeException('Yetkisiz finans erişimi.');
}
foreach(['GET','POST'] as $method){
    try{(new FinanceOverviewRoutes($db,new Security($db,[])))->handle('/api/finance/profitability/commission-rates',$method,['role'=>'client']);}
    catch(HttpError $error){check($error->getCode()===403,'Komisyon ayarı müşteri erişimine açık.');continue;}
    throw new RuntimeException('Yetkisiz komisyon oranı erişimi.');
}

$db->run('DROP TABLE profitability_lines');
$db->transaction(fn()=>$deliveryRepo->ingest($company,delivery('FAIL-ISOLATED',$clock-23,[$product]),false));
check((int)$db->one("SELECT COUNT(*) n FROM delivery_orders WHERE external_id='FAIL-ISOLATED'")['n']===1,'Yardımcı kayıt hatası sipariş aktarımını durdurdu.');
check($db->one("SELECT external_id FROM profitability_orders WHERE external_id='FAIL-ISOLATED'")===null,'Yarım kârlılık kaydı oluştu.');
echo "Kârlılık: etkinleştirme, sipariş anı maliyeti, adet, iptal/iade, firma yetkisi, sayfalama ve canlı aktarım ayrımı doğrulandı.\n";

