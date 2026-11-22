<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,InventoryBarcodeBackfill,InventoryBarcodeIdentity,InventoryCatalog,InventoryMetadataWriter,InventoryOrderProduct,InventoryRead,InventorySchema};

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
$read=new InventoryRead($db);
$products=$read->listing(1,filter:'all');
check($products['total']===2&&$products['pendingBarcodeListings']===[['provider'=>'Hepsiburada','count'=>1]],'Canlı tablo yalnız doğrulanmış ürünleri içermeli.');
check($read->listing(1,filter:'legacy')['total']===1,'Eşleşmemiş eski ürün kaydı ayrı görünmeli.');

$identity=InventoryBarcodeIdentity::load($db,1);
$gtinKey=$identity->key($first['barcode']);
check($gtinKey===$identity->key('04006381333931'),'GTIN öneki aynı barkodu iki kez oluşturmamalı.');
check($gtinKey===$catalog->ingest(1,'Trendyol',$listing('another','ANOTHER','4006381333931')),'Farklı stok koduyla aynı barkod tek satır olmalı.');
check($gtinKey!==$identity->key('Custom-C'),'Aynı stok kodlu farklı barkodlar ayrı kalmalı.');
check($read->listing(1,filter:'all')['total']===2,'Tekrar aktarım ürün adedini artırmamalı.');
$catalog->ingest(1,'Hepsiburada',$listing('unconfirmed','SKU-A','','HBCV003'));
check($read->listing(1,filter:'all')['total']===2,'Barkodsuz yeni ilan ürün satırı oluşturmamalı.');
$writer=new InventoryMetadataWriter($db);
check(!$writer->apply(1,['hbSku'=>'WRONG','sku'=>'SKU-A','barcode'=>'Extra-D','title'=>'Örnek yanlış']), 'Stok kodu metadata eşleştirmesi için kullanılamamalı.');
check($writer->apply(1,['hbSku'=>'HBCV003','sku'=>'different','barcode'=>'Custom-C','title'=>'Doğru ürün']), 'Kesin Hepsiburada kimliğiyle barkod tamamlanmalı.');
$catalog->ingest(1,'Hepsiburada',$listing('unconfirmed','SKU-A','','HBCV003'));
check($read->listing(1,filter:'all')['total']===2,'Sonraki stok sayfası doğrulanmış metadata barkodunu korumalı.');
$row=$db->one("SELECT product_key FROM inventory_listings WHERE company_id=1 AND remote_id='unconfirmed'");
check($row['product_key']===$identity->key('Custom-C'),'Hepsiburada barkodu doğru ürünü kullanmalı.');
$order=['provider'=>'n11','sku'=>'SKU-A','lineKey'=>str_repeat('1',64)];
check((new InventoryOrderProduct($db))->resolve(1,$order)===$gtinKey,'Yeni sipariş yalnız doğrulanmış kaynak ilanıyla ilişkilendirilmeli.');
try {
    (new InventoryOrderProduct($db))->resolve(1,[...$order,'sku'=>'olmayan']);
    throw new RuntimeException('Bilinmeyen stok kodu yeni ürün oluşturdu.');
} catch (DomainException) {}
echo "Barkod tekilliği, kaynak kimliği, stok geçmişi, kuyruk tekrarları ve mağaza sınırı doğrulandı.\n";
