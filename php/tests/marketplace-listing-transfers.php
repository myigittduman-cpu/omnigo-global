<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/MarketplaceStockPilotFixture.php';

use Omnigo\{BackupArchive,IntegrationVault,MarketplaceListingTransfers,MarketplaceListingTransferWorker,MarketplaceStockPilot,MarketplaceStockSchema};

function assertTransfer(bool $result,string $reason):void{
    if(!$result)throw new RuntimeException($reason);
}
$fixture=new MarketplaceStockPilotFixture();
$db=$fixture->db;
MarketplaceStockSchema::installAccess($db);
$db->run('INSERT INTO marketplace_model_access(company_id,enabled) VALUES(2,1)');
$card=$fixture->inputs['hepsiburada']['productKey'];
foreach(['n11','trendyol'] as $provider){
    $db->run('UPDATE inventory_listings SET product_key=? WHERE company_id=2 AND product_key=?',[$card,$fixture->inputs[$provider]['productKey']]);
}
$key=tempnam(sys_get_temp_dir(),'listing-transfer-vault-');
try{
    file_put_contents($key,random_bytes(32));
    $vault=new IntegrationVault($db,$key);
    foreach(['hepsiburada','n11','trendyol'] as $provider)$vault->save(2,['enabled'=>true],$provider);
    $db->run("UPDATE records SET updated=? WHERE company_id=2 AND kind LIKE 'integration_%'",[gmdate('c',time()-200)]);
    $transfers=new MarketplaceListingTransfers($db,$vault);
    $worker=new MarketplaceListingTransferWorker($db,MarketplaceStockPilot::load());
    $input=['cardKey'=>$card,'quantity'=>3,'priceCents'=>99900,'confirmed'=>true,'requestId'=>'test-transfer-sequence-0001'];
    $first=$transfers->submit(2,$input,$fixture->actor);
    assertTransfer(count($first['lines'])===7&&!$first['done']&&$transfers->submit(2,$input,$fixture->actor)===$first,'Yedi pazaryeri görünür, tekrar aynı işi kullanır.');
    $table=MarketplaceListingTransfers::TABLE;
    $restored=BackupArchive::restoredRow($table,$db->one('SELECT * FROM marketplace_listing_transfers'));
    assertTransfer(in_array($table,BackupArchive::TABLES,true)&&$restored['done']===1
        &&json_decode($restored['payload'],true)['lines'][0]['state']==='uncertain','Yedekten dönen bekleyen aktarım otomatik devam etmez.');
    assertTransfer($worker->tick(),'İlk pazaryeri sıradadır.');
    $stock=fn()=>$db->all('SELECT request_id FROM marketplace_stock_requests ORDER BY request_id');
    $prices=fn()=>$db->all('SELECT provider FROM marketplace_price_jobs ORDER BY provider');
    $next=function()use($db,$worker){
        $db->run('UPDATE marketplace_listing_transfers SET due=0 WHERE done=0');
        assertTransfer($worker->tick(),'Bekleyen aktarım bulunmalı.');
    };
    assertTransfer(count($stock())===1&&str_starts_with($stock()[0]['request_id'],'pilot:hepsiburada:')&&$prices()===[['provider'=>'hepsiburada']],
        'İlk adımda yalnızca ilk pazaryerinin stok ve fiyat işlemleri başlar.');
    $db->run("UPDATE marketplace_stock_requests SET state='succeeded'");
    $next();
    assertTransfer(count($stock())===1,'Fiyat kesinleşmeden ikinci pazaryeri başlamaz.');
    $db->run("UPDATE marketplace_price_jobs SET state='failed',message='Test reddi'");
    $next();$next();
    assertTransfer(count($stock())===2&&$prices()===[['provider'=>'hepsiburada'],['provider'=>'n11']],'İlk pazaryerinin hatası ikinciyi engellemez.');
    $db->run("UPDATE marketplace_stock_requests SET state='succeeded'");
    $db->run("UPDATE marketplace_price_jobs SET state='succeeded' WHERE provider='n11'");
    $next();
    // A changed connection after the click must never send the approved values to a new store.
    $db->run("UPDATE records SET version=version+1 WHERE company_id=2 AND kind='integration_trendyol'");
    $next();
    $last=$transfers->latest(2,$card);
    $states=array_column($last['lines'],'state','provider');
    assertTransfer($last['done']&&$states['Hepsiburada']==='failed'&&$states['n11']==='succeeded'
        &&$states['Trendyol']==='failed'&&count($stock())===2&&$states['PTT AVM']==='unconnected','Son durum kalıcıdır; bağlantı değişirse üçüncü kanal çağrılmaz.');
    assertTransfer($transfers->latest(1,$card)['requestId']===null,'Başka mağaza önceki sonucu göremez.');
    $editor=$transfers->editor(2,$card);
    assertTransfer(count($editor['lines'])===7&&$editor['product']['sku']!=='','Düzenleme penceresi stok kartı ve yedi pazaryerini döndürmeli.');
    $changes=['cardKey'=>$card,'confirmed'=>true,'requestId'=>'test-transfer-selected-0002','changes'=>[
        ['provider'=>'Hepsiburada','quantity'=>0],['provider'=>'n11','priceCents'=>84200],
    ]];
    $started=$transfers->submit(2,$changes,$fixture->actor);
    $reordered=[...$changes,'changes'=>array_reverse($changes['changes'])];
    assertTransfer($transfers->submit(2,$reordered,$fixture->actor)===$started,'Alan sırası değişse de aynı iş tekrar üretilmemeli.');
    $next();
    assertTransfer(count($stock())===3&&count($prices())===3,'Bir turda yalnızca iki seçili pazaryerinin değişen alanları kuyruğa alınmalı.');
    $result=$transfers->latest(2,$card,$changes['requestId']);
    $states=array_column($result['lines'],'state','provider');
    assertTransfer($states['Trendyol']==='skipped'&&$states['Hepsiburada']!=='skipped'&&$states['n11']!=='skipped',
        'Güncellenmeyen kanala iş açılmamalı ve seçili kanallar aynı turda ilerlemeli.');
}finally{
    unlink($key);
}
echo "Sıralı ve kalıcı stok kartı aktarımı doğrulandı.\n";
