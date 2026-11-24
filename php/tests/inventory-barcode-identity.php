<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,InventoryBarcodeBackfill,InventoryBarcodeIdentity,InventoryCatalog,InventorySchema,InventorySkuMigration};

function check(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme mağazası'),(2,'Diğer mağaza')");
$catalog=new InventoryCatalog($db);
$listing=fn(string $id,string $sku,string $barcode='',string $hb='')=>[
    'id'=>$id,'title'=>'Örnek '.$id,'sku'=>$sku,'barcode'=>$barcode,'saleActive'=>true,
    'quantity'=>8,'sourceCatalogId'=>$hb,
];
$first=$listing('a','SKU-A','4006381333931');
$duplicate=$listing('b','SKU-B');
$different=$listing('c','SKU-A','Custom-C');
$missing=$listing('hb-1','SKU-A','','HBCV001');
$key=$catalog->ingest(1,'n11',$first);
$duplicateKey=$catalog->ingest(1,'Hepsiburada',[...$duplicate,'sourceCatalogId'=>'HBCV002']);
$sameSkuKey=$catalog->ingest(1,'Trendyol',$different);
$catalog->ingest(1,'Hepsiburada',$missing);
$other=$catalog->ingest(2,'n11',$first);
check($key===$sameSkuKey&&$duplicateKey!==$key,'Örnek veri önceki çakışmaları içermeli.');
$db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,actor_id,updated) VALUES(1,?,12345,1,?)',[$key,gmdate('c')]);
$historyBefore=$db->all('SELECT * FROM inventory_purchase_prices');
$snapshot=[
    [...$first,'provider'=>'n11'],
    [...$duplicate,'provider'=>'Hepsiburada','barcode'=>'04006381333931','hbSku'=>'HBCV002'],
    [...$different,'provider'=>'Trendyol'],
    [...$missing,'provider'=>'Hepsiburada','hbSku'=>'HBCV001'],
];
$backups=[];
$backfill=new InventoryBarcodeBackfill($db);
$preview=$backfill->apply(1,'Deneme mağazası',$snapshot,fn()=>throw new RuntimeException('Salt okuma yedek yazdı.'),true);
check($preview['barcodes']===2&&$preview['pending']===1&&$preview['duplicates']===1,'Kaynak barkodları doğru gruplanmalı.');
$result=$backfill->apply(1,'Deneme mağazası',$snapshot,function(array $backup)use(&$backups){$backups[]=$backup;});
check($result['enabled']&&count($backups)===1&&$result['legacyWithHistory']===1,'Çakışan geçmiş güvenle ayrı tutulmalı.');
check($historyBefore===$db->all('SELECT * FROM inventory_purchase_prices'),'Alış fiyatı kaydı korunmalı.');
check(InventoryBarcodeIdentity::load($db,2)===null&&$other===$catalog->ingest(2,'n11',$first),'Diğer mağazanın davranışı korunmalı.');
$identity=InventoryBarcodeIdentity::load($db,1);
$gtinKey=$identity->key($first['barcode']);
check($gtinKey===$identity->key('04006381333931'),'GTIN öneki aynı barkodu iki kez oluşturmamalı.');
check($gtinKey!==$identity->key('Custom-C'),'Eski barkod eşlemesinde farklı kodlar ayrı kalır.');
(new InventorySkuMigration($db))->run(1);
$originalSku=$catalog->matchSku(1,'n11','SKU-A');
check($originalSku!==null&&$originalSku===$catalog->matchSku(1,'Trendyol','SKU-A'),'Eski barkod grupları açık SKU üzerinden tek karta döner.');
check($originalSku!==$catalog->ingest(1,'Trendyol',$listing('another','ANOTHER','4006381333931')),'Ortak GTIN artık kartları birleştirmez.');
check($originalSku===$catalog->ingest(1,'Hepsiburada',$listing('unconfirmed','SKU-A','','HBCV003')),'Barkodsuz gelen aynı SKU mevcut kartı kullanır.');
check($historyBefore===$db->all('SELECT * FROM inventory_purchase_prices'),'SKU geçişinden sonra da alış fiyatı geçmişi korunur.');
echo "Eski barkod arşivi, SKU geçişi ve alış fiyatı geçmişi doğrulandı.\n";
