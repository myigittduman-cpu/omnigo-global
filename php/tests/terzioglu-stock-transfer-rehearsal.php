<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/MarketplaceStockPilotFixture.php';

use Omnigo\{InventoryCentralStockLivePreview, MarketplaceStockJob, MarketplaceStockSchema};

function verifyRehearsal(bool $result, string $reason): void
{
    if (!$result) {
        throw new RuntimeException($reason);
    }
}

$fixture = new MarketplaceStockPilotFixture();
$db = $fixture->db;
MarketplaceStockSchema::installAccess($db);
$db->run('INSERT INTO marketplace_model_access(company_id,enabled) VALUES(2,1)');
$db->run('UPDATE companies SET name=? WHERE id=2', [InventoryCentralStockLivePreview::STORE]);
$sku = 'SKU-PROVA';
$card = $fixture->inputs['hepsiburada']['productKey'];
$now = new DateTimeImmutable();
$countedAt = $now->modify('-2 minutes');
$catalogAt = gmdate('c', time()-20);
$db->run('INSERT INTO inventory_jobs(company_id,last_success) VALUES(2,?)', [$catalogAt]);
$db->run('UPDATE inventory_products SET sku=? WHERE company_id=2 AND product_key=?', [$sku,$card]);
foreach (['hepsiburada'=>2, 'n11'=>1, 'trendyol'=>3] as $channel=>$current) {
    $fixture->gateways[$channel]->quantity = $current;
    $input = $fixture->inputs[$channel];
    $db->run("UPDATE inventory_listings SET product_key=?,sku=?,source_quantity=?,evidence=JSON_SET(evidence,'$.saleActive',1)
        WHERE company_id=2 AND listing_key=?", [$card,$sku,$current,$input['listingKey']]);
    $fixture->inputs[$channel] = array_replace($input, ['productKey'=>$card, 'sourceQuantity'=>$current]);
}

$preview = new InventoryCentralStockLivePreview($db);
$plan = fn() => $preview->read($sku, 2, $countedAt, new DateTimeImmutable())['card']['transferPreview'];
$before = $plan();
verifyRehearsal($before['Hepsiburada']['action']==='no_change','Doğru adetteki kanal gönderim dışında kalmalı.');
$requests = [];
foreach (MarketplaceStockJob::CHANNELS as $channel=>$provider) {
    $step = $before[$provider];
    if ($step['action'] !== 'would_send_latest') {
        continue;
    }
    $fixture->inputs[$channel]['quantity'] = $step['latestQuantity'];
    $requests[$channel] = $fixture->submit($channel)['requestId'];
}
verifyRehearsal(array_keys($requests)===['n11','trendyol'],'Prova yalnız farklı adetteki iki kanalı seçmeli.');

$fixture->gateways['n11']->receiptStatus = 'done';
$fixture->tick();
verifyRehearsal($fixture->status($requests['n11'])==='accepted','n11 kabulü nihai sonuç olarak işaretlenmemeli.');
$fixture->tick();
verifyRehearsal($fixture->status($requests['n11'])==='succeeded'&&$fixture->gateways['n11']->reads===2,
    'n11 başarıdan önce gönderilen adedi tekrar okumalı.');

$fixture->tick();
$trendyol = $fixture->gateways['trendyol'];
$fixture->tick();
verifyRehearsal($fixture->status($requests['trendyol'])==='accepted'&&$trendyol->polls===1,
    'Bekleyen Trendyol talebi onaylanmış sayılmamalı.');
verifyRehearsal($plan()['Trendyol']['action']==='verify_previous','Önizleme bekleyen Trendyol talebini doğrulamaya yönlendirmeli.');

$trendyol->receiptStatus = 'done';
$trendyol->quantity = 3;
$fixture->tick();
verifyRehearsal($fixture->status($requests['trendyol'])==='accepted'&&$trendyol->posts===1,
    'Makbuz tamamlanıp stok eski görünürken ikinci gönderim oluşturulmamalı.');
$trendyol->quantity = 2;
$fixture->tick();
verifyRehearsal($fixture->status($requests['trendyol'])==='succeeded'&&$trendyol->posts===1,
    'Gecikmeli katalog 2 adedi gösterince ilk gönderim doğrulanmalı.');

$db->run('UPDATE inventory_listings SET source_quantity=2,observed_at=? WHERE company_id=2', [gmdate('c')]);
$final = $plan();
verifyRehearsal(count(array_filter($final, static fn($step)=>$step['action']!=='no_change'))===0,
    'Son okuma her kanalda 2 adedi doğrulayınca yeni gönderim önermemeli.');
verifyRehearsal($fixture->gateways['hepsiburada']->posts===0&&$fixture->gateways['hepsiburada']->reads===0
    &&$fixture->legacy->posts===0&&$fixture->gateways['n11']->posts===1
    &&(int)$db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n']===2,
    'Her hedef en fazla bir gönderim almalı ve doğru adetteki kanal açılmamalı.');
echo "Terzioğlu stok provası geçti. n11 ve Trendyol birer simülasyon gönderimiyle doğrulandı. Hepsiburada işlem dışı.\n";
