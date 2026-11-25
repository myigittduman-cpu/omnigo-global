<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, InventoryCentralPricePreview, InventoryChannelPricePreview, InventoryPricePreviewWorkspace, InventoryPurchaseTax, InventorySchema};

function verifyPricePreview(bool $condition,string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$rule = [
    'shippingNetCents'=>1000, 'otherNetCents'=>500, 'salesVatBasisPoints'=>2000,
    'effectiveCommissionBasisPoints'=>1000,'targetNetMarginBasisPoints'=>2000,'roundUpCents'=>100,
];
$quote = InventoryChannelPricePreview::quote(10000,$rule);
verifyPricePreview($quote['priceGrossCents']===20300 && $quote['projectedNetMarginBasisPoints']>=2000,
    'Kanal komisyonu ve KDV ayrıştırılarak fiyat hedef marjın üzerine yuvarlanmalı.');
try {
    InventoryChannelPricePreview::quote(10000,array_replace($rule,['effectiveCommissionBasisPoints'=>8900]));
    throw new RuntimeException('Zarar sınırında fiyat önerisi üretildi.');
} catch (DomainException) {
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Terzioğlu Shop'),(2,'Örnek mağaza')");
$key = str_repeat('1',64);
$now = gmdate('c');
foreach ([1,2] as $company) {
    $db->run("INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated)
        VALUES(?,?,'Örnek ürün','SKU-PROVA','','',?)",[$company,$key,$now]);
    $db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,version,actor_id,updated)
        VALUES(?,?,?,1,1,?)',[$company,$key,$company===1?12000:36000,$now]);
}
$tax = InventoryPurchaseTax::normalize(['amountCents'=>12000,'vatBasis'=>'included','vatRateBasisPoints'=>2000]);
InventoryPurchaseTax::save($db,1,$key,1,$tax);
foreach (['n11'=>'n11','trendyol'=>'Trendyol'] as $provider=>$label) {
    $db->run('INSERT INTO inventory_catalog_jobs(company_id,provider,last_success) VALUES(1,?,?)',[$provider,$now]);
    $db->run("INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at)
        VALUES(1,?,?,?,?,'SKU-PROVA',?,2,?)",[$label,hash('sha256',$label),$provider,$key,
        '{"saleActive":1,"offerPriceCents":19900}',$now]);
}
$db->run('PRAGMA query_only=ON');
$preview = (new InventoryCentralPricePreview($db))->read('sku-prova',['n11'=>$rule]);
verifyPricePreview($preview['netPurchaseCostCents']===10000,'Başka mağazanın alış fiyatı kullanılmamalı.');
verifyPricePreview($preview['marketplaces']['n11']['quote']['differenceCents']===400,'Öneri ve canlı fiyat farkı görünmeli.');
verifyPricePreview($preview['marketplaces']['Trendyol']['quote']===null
    && $preview['marketplaces']['Trendyol']['holds']===['pricing_rule_missing'],'Kanal kuralı eksikse fiyat önerilmemeli.');
$db->run('PRAGMA query_only=OFF');
$workspace = new InventoryPricePreviewWorkspace($db);
$draft = ['productKey'=>$key,'version'=>0,'costNetCents'=>11000,'rules'=>['n11'=>$rule]];
verifyPricePreview($workspace->get(1,$key)['draftCostNetCents']===null,'Önizleme maliyeti mevcut alış fiyatını değiştirmemeli.');
$saved = $workspace->save(1,$draft);
verifyPricePreview($saved===$workspace->get(1,$key) && $saved['netPurchaseCostCents']===11000
    && $saved['marketplaces']['n11']['quote']['priceGrossCents']===22100,'Kanal kuralı ve öneri yeniden açılabilmeli.');
verifyPricePreview((int)$db->one('SELECT amount_cents FROM inventory_purchase_prices WHERE company_id=1')['amount_cents']===12000
    && (int)$db->one("SELECT COUNT(*) n FROM records WHERE kind='inventory_price_preview'")['n']===1,
    'Yalnız önizleme kaydı yazılmalı.');
try {
    $workspace->save(1,$draft);
    throw new RuntimeException('Eski sürümle kayıt kabul edildi.');
} catch (DomainException) {
}
try {
    $workspace->get(2,$key);
    throw new RuntimeException('Başka mağaza önizleme kaydına erişti.');
} catch (OutOfBoundsException) {
}
echo "Terzioğlu Shop kanal fiyatı, hedef marj, KDV ve salt okunur çalışma doğrulandı.\n";
