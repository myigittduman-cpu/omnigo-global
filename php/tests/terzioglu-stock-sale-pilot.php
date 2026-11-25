<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/MarketplaceStockPilotFixture.php';

use Omnigo\{IntegrationVault, InventoryCentralStockSalePilot, InventoryCentralStockLivePreview, MarketplaceStockSchema};

function verifySalePilot(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
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
$countedAt = $now->modify('-4 minutes');
$time = static fn(int $offset) => gmdate('c', time()+$offset);
$db->run('INSERT INTO inventory_jobs(company_id,last_success) VALUES(2,?)', [$time(-20)]);
$db->run('UPDATE inventory_products SET sku=? WHERE company_id=2 AND product_key=?', [$sku,$card]);
foreach (['hepsiburada','n11','trendyol'] as $channel) {
    $fixture->gateways[$channel]->quantity = 2;
    $db->run("UPDATE inventory_listings SET product_key=?,sku=?,source_quantity=2,evidence=JSON_SET(evidence,'$.saleActive',1)
        WHERE company_id=2 AND listing_key=?", [$card,$sku,$fixture->inputs[$channel]['listingKey']]);
}
$reader = new InventoryCentralStockSalePilot($db,new IntegrationVault($db,__FILE__),
    static fn(int $company,string $channel,string $code,int $deadline) =>
        $fixture->gateways[$channel]->read(['company'=>$company,'channel'=>$channel],$code,$deadline));
$run = '1234567890abcdef';
$next = static fn() => $reader->tick($run,$sku,2,'Yönetici',$countedAt,$now->modify('+1 day'));
verifySalePilot($next()['state']==='waiting_sale', 'Satış yoksa pilot beklemeli.');
verifySalePilot((int)$db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n']===0, 'Satış olmadan stok gönderimi oluştu.');

$db->run("INSERT INTO inventory_balances(company_id,provider,line_key,product_key,order_number,sequence_no,source_hash,ordered,cancelled,returned,consumed,placed_at,updated)
    VALUES(2,'n11','sale',?,'1',1,'',1,0,0,1,?,?)",[$card,$time(-100),$time(-80)]);
$fixture->gateways['n11']->quantity = 1;
$db->run("UPDATE inventory_listings SET source_quantity=1 WHERE company_id=2 AND provider='n11'");
$first = $next();
verifySalePilot($first['quantity']===1 && $first['channels']['n11']['state']==='verified'
    && $first['channels']['Trendyol']['state']==='queued','Satan kanal korunmalı ve yalnız diğer kanala 1 adet sıraya alınmalı.');
verifySalePilot($next()['channels']['Trendyol']['state']==='queued'
    && (int)$db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n']===1,'Tekrarlanan çalışma ikinci gönderimi açmamalı.');

$gateway = $fixture->gateways['trendyol'];
$gateway->receiptStatus = 'done';
$fixture->tick();
$fixture->tick();
verifySalePilot($next()['state']==='verified' && $gateway->posts===1 && $gateway->quantity===1
    && $fixture->gateways['hepsiburada']->posts===0 && $fixture->gateways['n11']->posts===0,
    'Sıfır dış kanal ve tek Trendyol gönderimi canlı geri okuma ile doğrulanmalı.');
verifySalePilot(InventoryCentralStockSalePilot::action(1,0,'would_send_latest',null,true)==='quantity_below_central',
    'Satış pilotu pazaryeri stoğunu yükseltmemeli.');
echo "Satış tetiklemeli stok pilotu, tek görev ve sonuç geri okumasıyla doğrulandı.\n";
