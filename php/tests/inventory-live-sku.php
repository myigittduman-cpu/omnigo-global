<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,InventoryBarcodeIdentity,InventoryCatalog,InventoryLiveSkuIdentity,InventoryManual,InventoryMetadataWriter,InventoryOrderProduct,InventoryRead,InventorySchema,InventorySettings,InventorySkuMigration};

function check(bool $passed,string $message):void
{
    if(!$passed)throw new RuntimeException($message);
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Canlı mağaza'),(2,'Yeni mağaza'),(3,'Farklı model')");
$policy=['inventory'=>['mode'=>'horoz'],'pages'=>['inventory'=>'all_roles']];
foreach([1,2] as $company)$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>$policy],JSON_THROW_ON_ERROR)]);
InventoryBarcodeIdentity::enable($db,1,[]);
$catalog=new InventoryCatalog($db);
$make=fn(string $id,string $sku,string $barcode='',int $quantity=8)=>[
    'id'=>$id,'sku'=>$sku,'title'=>'Örnek '.$id,'barcode'=>$barcode,'quantity'=>$quantity,'saleActive'=>true,
];
$a=$make('a','SKU-A','4006381333931');
$b=$make('b','sku-a','5901234123457',3);
$c=$make('c','SKU-B','5901234123457',2);
$d=$make('d','SKU-C');
$main=$catalog->ingest(1,'n11',$a);
$duplicate=$catalog->ingest(1,'Trendyol',$b);
check($catalog->ingest(1,'Trendyol',$c)===$duplicate&&$main!==$duplicate,'Önceki barkod ayrımı kurulmalı.');
$catalog->ingest(1,'Hepsiburada',[...$d,'sourceCatalogId'=>'HBCV3']);
$catalog->ingest(1,'PTT AVM',$make('100:4006381333931','4006381333931','4006381333931'));
$catalog->ingest(1,'n11',$make('missing',''));
$other=$catalog->ingest(3,'n11',$a);
$catalog->ingest(3,'n11',$c);
$db->run('INSERT INTO inventory_shared_stock(company_id,product_key,adjustment,manual_quantity) VALUES(1,?,-2,NULL)',[$main]);
$db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,actor_id,updated) VALUES(1,?,25000,1,?)',[$main,gmdate('c')]);
$db->run("INSERT INTO inventory_balances VALUES(1,'Trendyol',?,?, 'OLD-1',1,?,1,0,0,1,1000,?,?)",
    [str_repeat('a',64),$duplicate,str_repeat('b',64),gmdate('c'),gmdate('c')]);
$snapshot=fn()=>array_map(fn($table)=>$db->all('SELECT * FROM '.$table),['inventory_balances','inventory_shared_stock','inventory_purchase_prices','inventory_ledger','inventory_pending_lines']);
$before=$snapshot();

$migration=new InventorySkuMigration($db);
$preview=$migration->run(1,preview:true,backup:fn()=>throw new RuntimeException('Önizleme yedek yazdı.'));
check($preview['stockCodes']===3&&$preview['pending']===2&&InventoryBarcodeIdentity::load($db,1)!==null,'Önizleme bekleyen kodları saymalı ve yazmamalı.');
$result=$migration->run(1,backup:fn($data)=>check(count($data['listings'])===6,'Geçişten önce kaynaklar yedeklenmeli.'));
check($result['applied']&&$before===$snapshot(),'Mevcut stok, sipariş ve fiyat geçmişi korunmalı.');
check(!$migration->run(1)['applied']&&InventoryBarcodeIdentity::load($db,1)===null,'Geçiş bir kez çalışmalı.');
$read=new InventoryRead($db);
$items=$read->listing(1,filter:'all');
check($items['total']===3&&$items['skuOnly']&&count($items['pendingSkuListings'])===2,'Canlı tabloda her kod tek satır olmalı.');
check($read->listing(1,filter:'legacy')['total']===1,'Önceki ürün kaydı erişilebilir olmalı.');
check($main===$catalog->ingest(1,'Trendyol',[...$b,'sku'=>' SKU-a ']),'Harf farkı ve boşluk aynı stok kodunu bölmemeli.');
check($main!==$catalog->ingest(1,'Trendyol',$c),'Aynı barkod farklı stok kodlarını birleştirmemeli.');
check($other===$catalog->ingest(3,'n11',$a)&&!isset($read->listing(3,filter:'all')['skuOnly']),'Diğer stok modeli ayrı kalmalı.');

$order=new InventoryOrderProduct($db);
$line=['provider'=>'Trendyol','sku'=>'sku-a','title'=>'Başka başlık','lineKey'=>str_repeat('c',64)];
check($order->resolve(1,$line)===$main,'Yeni sipariş stok koduna bağlanmalı.');
check($order->resolve(1,[...$line,'lineKey'=>str_repeat('a',64)])===$duplicate,'İşlenmiş siparişin geçmiş bağlantısı korunmalı.');
$metadata=new InventoryMetadataWriter($db);
$detail=['sku'=>'SKU-C','hbSku'=>'HBCV3','barcode'=>'4006381333931','title'=>'Doğru Hepsiburada başlığı','images'=>[],'brand'=>''];
check(!$metadata->apply(1,[...$detail,'sku'=>'SKU-A'])&&$metadata->apply(1,$detail),'Ürün bilgisi yalnız aynı mağaza kodu ve kaynakla eşleşmeli.');
check($read->listing(1,filter:'all')['total']===3,'Bilgi aktarımı ürünleri yeniden gruplamamalı.');

(new InventorySettings($db))->configure(2,$policy);
check(InventoryLiveSkuIdentity::load($db,2)===null,'Aynı stok modeli, mağazaya özel kuralı kendiliğinden açmamalı.');
$migration->run(2);
$manual=new InventoryManual($db);
$input=['title'=>'Manuel ürün','sku'=>'YENI-1','quantity'=>3,'requestId'=>'request-123456789','connect'=>false];
$manualKey=$manual->create(2,$input,1)['key'];
check($manual->create(2,$input,1)['replayed'],'Manuel ürün tekrar isteği güvenli olmalı.');
check($catalog->ingest(2,'n11',$make('new','yeni-1'))===$manualKey,'Sonradan gelen aynı stok kodu manuel ürüne bağlanmalı.');
check($read->listing(2,filter:'all')['total']===1&&InventoryLiveSkuIdentity::load($db,2)!==null,'Seçilen mağaza aynı stok koduyla devam etmeli.');
$title='Profilo FRTA122J 66 Litre Çelik Ankastre Fırın';
$named=$catalog->ingest(2,'n11',[...$make('oven','FRTA122J','4242006291259'),'title'=>$title]);
check($catalog->ingest(2,'idefix',[...$make('oven-alias','4242006291259','4242006291259'),'title'=>$title])===$named,'Doğrulanan farklı kodlu aynı ürün bir satırda kalmalı.');
check($catalog->ingest(2,'Trendyol',[...$make('different','FRTA122K','4242006291259'),'title'=>'Profilo FRTA122K Beyaz Ankastre Fırın'])!==$named,'Farklı ürün adı veya varyant ortak barkodla birleşmemeli.');
check($catalog->ingest(2,'Pazarama',[...$make('variant','FRTA122J-V2','5901234123457'),'title'=>$title])!==$named,'Aynı ürün adı, farklı doğrulanmış varyantları birleştirmemeli.');
echo "Stok kodu geçişi, tekrar aktarım, geçmiş koruma, katalog, sipariş, ürün bilgisi ve mağaza kapsamı doğrulandı.\n";
