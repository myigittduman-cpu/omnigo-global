<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, InventoryCentralStockLivePreview, InventorySchema, MarketplaceStockSchema};

function verifyLivePreview(bool $expectation): void
{
    if (!$expectation) {
        throw new RuntimeException('Gönderimsiz canlı stok karşılaştırması doğrulanamadı.');
    }
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
MarketplaceStockSchema::install($db);
$db->run("INSERT INTO companies VALUES (1,'Terzioğlu Shop'),(2,'Fiesta Ankastre')");
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$countedAt = $now->modify('-10 minutes');
$utc = static fn($date) => $date->format('Y-m-d\TH:i:s\Z');
$key = str_repeat('1', 64);
$db->run("INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated)
    VALUES(1,?,'Örnek','SKU-A','','',?)", [$key, $utc($now)]);
$db->run('INSERT INTO inventory_jobs(company_id,last_success) VALUES (1,?)',[$utc($now->modify('-10 seconds'))]);
foreach (['n11'=>'n11', 'trendyol'=>'Trendyol'] as $channel=>$provider) {
    $db->run('INSERT INTO records(company_id,kind,payload,version,updated) VALUES (1,?,?,1,?)',
        ['integration_'.$channel,'{}',$utc($now->modify('-20 minutes'))]);
    $db->run('INSERT INTO inventory_catalog_jobs(company_id,provider,last_success) VALUES (1,?,?)',
        [$channel,$utc($now->modify('-10 seconds'))]);
    $db->run("INSERT INTO inventory_catalog_progress(company_id,provider,state,started_at,completed_at)
        VALUES (1,?,'complete',?,?)",[$channel,$now->getTimestamp()-120,$now->getTimestamp()-10]);
    $db->run("INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at)
        VALUES(1,?,?,?,?,'SKU-A',?,9,?)", [$provider,hash('sha256',$channel),$channel,$key,
        '{"saleActive":1,"barcode":"8698957282723"}',$utc($now->modify('-90 seconds'))]);
}
$db->run("INSERT INTO inventory_balances(company_id,provider,line_key,product_key,order_number,sequence_no,source_hash,ordered,cancelled,returned,consumed,placed_at,updated)
    VALUES (1,'n11','a',?,'1',1,'',2,1,0,1,?,?),(2,'n11','b',?,'2',1,'',4,0,0,4,?,?)",
    [$key,$utc($now->modify('-5 minutes')),$utc($now->modify('-4 minutes')),
      $key,$utc($now->modify('-3 minutes')),$utc($now->modify('-3 minutes'))]);

$reader = new InventoryCentralStockLivePreview($db);
$db->run('PRAGMA query_only=ON');
$preview = $reader->read('sku-a', 9, $countedAt, $now);
verifyLivePreview($preview['mode'] === 'read_only' && $preview['orderLines'] === 1);
verifyLivePreview($preview['card']['availableQuantity'] === 8);
verifyLivePreview($preview['card']['marketplaceQuantities']['n11']['decision'] === 'would_update');
verifyLivePreview($preview['card']['marketplaceQuantities']['n11']['observed'] === 9);
verifyLivePreview($preview['card']['transferPreview']['n11']['action'] === 'would_send_latest');
verifyLivePreview((int)$db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n'] === 0);
$db->run('PRAGMA query_only=OFF');

$db->run("INSERT INTO marketplace_stock_requests
    (company_id,request_id,product_key,listing_key,sku,remote_id,quantity,source_quantity,connection_version,request_hash,state,actor_id,created,updated)
    VALUES (1,'pilot:n11:previous',?,?,'SKU-A','n11',8,9,1,'','failed',10,?,?)",
    [$key,hash('sha256','n11'),$utc($now->modify('-3 minutes')),$utc($now->modify('-2 minutes'))]);
$db->run("INSERT INTO inventory_balances(company_id,provider,line_key,product_key,order_number,sequence_no,source_hash,ordered,cancelled,returned,consumed,placed_at,updated)
    VALUES (1,'Trendyol','c',?,'3',1,'',1,0,0,1,?,?)",
    [$key,$utc($now->modify('-110 seconds')),$utc($now->modify('-100 seconds'))]);
$retry = $reader->read('SKU-A', 9, $countedAt, $now)['card']['transferPreview']['n11'];
verifyLivePreview($retry === [
    'action'=>'would_retry_latest', 'latestQuantity'=>7, 'previousState'=>'failed', 'previousQuantity'=>8,
]);
foreach (['queued'=>'hold_stale_queued', 'accepted'=>'verify_previous', 'uncertain'=>'verify_previous'] as $state=>$action) {
    $db->run("UPDATE marketplace_stock_requests SET state=?",[$state]);
    $db->run('PRAGMA query_only=ON');
    $plan = $reader->read('SKU-A', 9, $countedAt, $now)['card']['transferPreview']['n11'];
    verifyLivePreview($plan['action'] === $action && $plan['latestQuantity'] === 7);
    verifyLivePreview($db->one('SELECT state,quantity FROM marketplace_stock_requests') === ['state'=>$state,'quantity'=>8]);
    $db->run('PRAGMA query_only=OFF');
}
$db->run("UPDATE marketplace_stock_requests SET state='succeeded',updated=?",[$utc($now->modify('-1 minute'))]);
verifyLivePreview($reader->read('SKU-A', 9, $countedAt, $now)['card']['transferPreview']['n11']['action'] === 'verify_catalog_after_transfer');

$db->run("UPDATE inventory_listings SET evidence=? WHERE company_id=1 AND provider='Trendyol'", ['{"saleActive":1,"barcode":"2493324364657"}']);
$db->run("UPDATE inventory_balances SET cancelled=0,returned=1 WHERE company_id=1 AND line_key='a'");
$blocked = $reader->read('SKU-A', 9, $countedAt, $now)['card'];
verifyLivePreview($blocked['availableQuantity'] === 6 && in_array('returned_quantity_needs_stock_confirmation', $blocked['holds'], true));
verifyLivePreview(in_array('listing_barcode_conflict', $blocked['holds'], true));
verifyLivePreview($blocked['marketplaceQuantities']['n11']['decision'] === 'hold');
echo "Terzioğlu Shop canlı stok önizlemesi, ayrı mağaza kapsamı ve gönderimsiz çalışma doğrulandı.\n";
