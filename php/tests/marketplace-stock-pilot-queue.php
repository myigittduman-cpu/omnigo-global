<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/support/MarketplaceStockPilotFixture.php';

use Omnigo\{HttpError,MarketplaceStockJob};

$checks=0;
function pilotCheck(bool $condition,string $message):void
{
    global $checks;
    if(!$condition)throw new RuntimeException($message);
    $checks++;
}
function pilotRejects(callable $action,string $message):void
{
    try {$action();} catch(DomainException|HttpError|OutOfBoundsException|InvalidArgumentException) {
        pilotCheck(true,$message);
        return;
    }
    throw new RuntimeException($message);
}

foreach(array_diff_key(MarketplaceStockJob::CHANNELS,['pazarama'=>true]) as $channel=>$label){
    $f=new MarketplaceStockPilotFixture();
    $gateway=$f->gateways[$channel];
    $result=$f->submit($channel);
    $id=$result['requestId'];
    pilotCheck($result['provider']===$label&&$result['state']==='queued'&&!$result['complete'],"$label sıfır gönderim onayı bekliyor");
    pilotCheck($f->submit($channel)===$result,"$label ikinci tıklama aynı işlemi döndürür");
    $f->tick();
    pilotCheck($f->status($id)==='accepted'&&$gateway->posts===1&&$gateway->quantity===0&&$gateway->reads===1,"$label tek stok gönderimi kabul edildi");
    $f->tick();
    pilotCheck($f->status($id)==='accepted'&&$gateway->polls===1&&$gateway->posts===1,"$label ara durum başarıya dönüştürülmedi");
    // A zero-stock listing may disappear from the active catalog while its write is being confirmed.
    $f->db->run("UPDATE inventory_listings SET evidence=JSON_SET(evidence,'$.saleActive',0),source_quantity=0 WHERE company_id=2 AND provider=?",[$label]);
    $gateway->receiptStatus='done';
    $f->tick();
    pilotCheck($f->status($id)==='succeeded'&&$gateway->posts===1&&$gateway->reads===2,"$label satış kapanınca nihai adet yine kaynaktan okunur");
    pilotCheck((int)$f->db->one('SELECT COUNT(*) n FROM audit WHERE company_id=2')['n']===1,"$label tek doğrulama kaydı");
    pilotCheck($f->submit($channel)['state']==='succeeded',"$label satıştan kalkan ilan için aynı kimlikle tekrar okunan talep değişmez");
    foreach($f->gateways as $other=>$unrelated)if($other!==$channel)pilotCheck($unrelated->posts===0&&$unrelated->reads===0,"$label diğer kanala ulaşmadı");
    pilotCheck($f->legacy->posts===0&&$f->legacy->reads===0,"$label önceki stok entegrasyonuna ulaşmadı");
}

$f=new MarketplaceStockPilotFixture();
$input=$f->inputs['n11'];
pilotRejects(fn()=>$f->queue->submit(2,[...$input,'provider'=>'Pazarama'],$f->actor),'Genel mağaza izni Pazarama manuel gönderimini açmaz');
pilotRejects(fn()=>$f->queue->submit(1,$input,$f->actor),'Pilot dışındaki mağaza stok yazamaz');
pilotRejects(fn()=>$f->queue->submit(2,$input,['role'=>'client','id'=>20]),'Mağaza kullanıcısı stok yazamaz');
pilotRejects(fn()=>$f->queue->submit(2,array_replace($input,['sourceQuantity'=>4]),$f->actor),'Eski kaynak adedine verilen onay geçersiz');
pilotRejects(fn()=>$f->queue->submit(2,array_replace($input,['confirmed'=>false]),$f->actor),'Açık stok gönderimi onayı gerekir');
pilotRejects(fn()=>$f->queue->submit(2,array_replace($input,['provider'=>'Trendyol']),$f->actor),'Bir pazaryerine ait hedef diğerine kullanılamaz');
$f->db->run("UPDATE inventory_listings SET evidence='{\"saleActive\":false}' WHERE company_id=2 AND provider='n11'");
pilotRejects(fn()=>$f->submit('n11'),'Pasif ilana baştan stok gönderilemez');
pilotCheck((int)$f->db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n']===0,'Reddedilen gönderimler kuyruğa girmedi');

$f=new MarketplaceStockPilotFixture();
$input=$f->inputs['hepsiburada'];
$f->db->run('DELETE FROM inventory_listings WHERE company_id=2 AND provider=?',['n11']);
$f->addListing('n11','source-hepsiburada','SKU-32',$input['productKey']);
pilotRejects(fn()=>$f->submit('hepsiburada'),'Aynı anahtar ve ürün iki kanalda olduğunda belirsiz hedefe gönderim yapılmadı');

foreach(['adet','uzak kimlik','uzak satış kapalı','bağlantı','ikinci okuma sırasında bağlantı','yetki'] as $change){
    $f=new MarketplaceStockPilotFixture();
    $id=$f->submit('trendyol')['requestId'];
    $gateway=$f->gateways['trendyol'];
    $rotate=fn()=>$f->db->run("UPDATE records SET version=version+1 WHERE company_id=2 AND kind='integration_trendyol'");
    match($change){
        'adet'=>$gateway->quantity=3,
        'uzak kimlik'=>$gateway->remoteId='another-item',
        'uzak satış kapalı'=>$gateway->saleActive=false,
        'bağlantı'=>$rotate(),
        'ikinci okuma sırasında bağlantı'=>$gateway->afterRead=$rotate,
        'yetki'=>$f->db->run('INSERT INTO account_controls VALUES(10,0)'),
    };
    $f->tick();
    pilotCheck($gateway->posts===0&&$f->status($id)==='failed',"Onaydan sonra $change değişikliği gönderim öncesinde engellendi");
}

$f=new MarketplaceStockPilotFixture();
$id=$f->submit('n11')['requestId'];
$gateway=$f->gateways['n11'];
$gateway->uncertain=true;
$f->tick();
pilotCheck($f->status($id)==='sending'&&$gateway->posts===1,'Kesinleşmeyen POST için yalnız uzlaştırma başlatılır');
$f->tick();
pilotCheck($gateway->posts===1&&$f->status($id)==='uncertain','Eski adet görülünce tekrar yazılmaz');
pilotRejects(fn()=>$f->queue->submit(2,array_replace($f->inputs['n11'],['requestId'=>'request-pilot-second-00002']),$f->actor),'Belirsiz gönderim aynı ilanın yeni işlemini engeller');
$gateway->quantity=0;
$gateway->saleActive=false;
$f->queue->retry(2,$id,$f->actor);
$f->tick();
pilotCheck($gateway->posts===1&&$f->status($id)==='succeeded','Tekrar kontrol daha sonra ulaşılan sıfırı ikinci POST olmadan doğrular');
pilotRejects(fn()=>$f->queue->get(1,$id,$f->actor),'Stok gönderim durumu başka mağazadan okunamaz');
pilotRejects(fn()=>$f->queue->get(2,$id,['role'=>'client','id'=>20]),'Stok gönderim durumu müşteri rolüne kapalı');

foreach(['kapalı ilan','aynı kanalda ikinci ilan','kaynak tarama eskidi'] as $change){
    $f=new MarketplaceStockPilotFixture();
    $id=$f->submit('trendyol')['requestId'];
    $gateway=$f->gateways['trendyol'];
    $gateway->afterRead=function()use($f,$change){
        match($change){
            'kapalı ilan'=>$f->db->run("UPDATE inventory_listings SET evidence='{\"saleActive\":false}' WHERE company_id=2 AND provider='Trendyol'"),
            'aynı kanalda ikinci ilan'=>$f->addListing('trendyol','another-trendyol-id','SKU-32'),
            'kaynak tarama eskidi'=>$f->db->run("UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=2 AND provider='trendyol'",[gmdate('c',time()-86400)]),
        };
    };
    $f->tick();
    pilotCheck($gateway->posts===0&&$f->status($id)==='failed',"Canlı okuma sırasında $change oluştuğunda güncel onay hedefi korunur");
}

$f=new MarketplaceStockPilotFixture();
$id=$f->submit('n11')['requestId'];
$other=array_replace($f->inputs['trendyol'],['requestId'=>$f->inputs['n11']['requestId']]);
pilotRejects(fn()=>$f->queue->submit(2,$other,$f->actor),'İşlem kimliği diğer pazaryerinde tekrar kullanılamaz');
pilotCheck((int)$f->db->one('SELECT COUNT(*) n FROM marketplace_stock_requests')['n']===1,'İkinci pazaryerinde işlem oluşturulmadı');

foreach([true,false] as $eventuallyConfirmed){
    $f=new MarketplaceStockPilotFixture();
    $id=$f->submit('hepsiburada')['requestId'];
    $gateway=$f->gateways['hepsiburada'];
    $f->tick();
    $gateway->receiptStatus='done';
    $gateway->quantity=5;
    $f->tick();
    pilotCheck($f->status($id)==='accepted'&&$gateway->posts===1,'İşlem tamamlandığı halde geciken katalog ikinci gönderim oluşturmaz');
    if($eventuallyConfirmed)$gateway->quantity=0;
    else $f->db->run('UPDATE marketplace_stock_requests SET created=?',[gmdate('c',time()-910)]);
    $f->tick();
    pilotCheck($f->status($id)===($eventuallyConfirmed?'succeeded':'uncertain')&&$gateway->posts===1,'Katalog sürede yetişirse başarı, aksi halde belirsizlik');
}

foreach(['kaynak ürün değişti','kaynak ilan değişti','katalog ürünü değişti'] as $change){
    $f=new MarketplaceStockPilotFixture();
    $id=$f->submit('hepsiburada')['requestId'];
    $gateway=$f->gateways['hepsiburada'];
    match($change){
        'kaynak ürün değişti'=>$gateway->catalogSku='HB-another-product',
        'kaynak ilan değişti'=>$gateway->remoteId='another-offer',
        'katalog ürünü değişti'=>$gateway->afterRead=fn()=>$f->db->run("UPDATE inventory_listings SET evidence=JSON_SET(evidence,'$.sourceCatalogId','HB-another-product') WHERE provider='Hepsiburada'"),
    };
    $f->tick();
    pilotCheck($gateway->posts===0&&$f->status($id)==='failed',"Hepsiburada iki ayrı kimlikten biri değişince gönderim durur: $change");
}
$f=new MarketplaceStockPilotFixture();
$f->db->run("UPDATE inventory_listings SET evidence=JSON_SET(evidence,'$.sourceCatalogId','HB-another-product') WHERE provider='Hepsiburada'");
pilotRejects(fn()=>$f->submit('hepsiburada'),'Onay penceresi açıkken değişen Hepsiburada ürün eşleşmesi gönderilemez');
$f->db->run("UPDATE inventory_listings SET evidence='{\"saleActive\":1}' WHERE provider='Hepsiburada'");
$input=$f->inputs['hepsiburada'];
pilotCheck((new \Omnigo\ActiveMarketplaceListings($f->db))->writableTarget(2,$input['productKey'],$input['listingKey'])===null,'HB ürün kodu bilinmeyen ilan yalnız okunabilir');
$f=new MarketplaceStockPilotFixture();
$id=$f->submit('hepsiburada')['requestId'];
$gateway=$f->gateways['hepsiburada'];
$f->tick();
$gateway->receiptStatus='done';
$gateway->catalogSku='HB-changed-after-post';
$f->tick();
pilotCheck($f->status($id)==='uncertain'&&$gateway->posts===1,'Hepsiburada aynı stokta farklı ürünü başarı olarak doğrulamaz');
$f->db->run("UPDATE inventory_listings SET evidence=JSON_SET(evidence,'$.sourceCatalogId','HB-changed-after-post') WHERE provider='Hepsiburada'");
$input=$f->inputs['hepsiburada'];
pilotCheck((new \Omnigo\ActiveMarketplaceListings($f->db))->writableTarget(2,$input['productKey'],$input['listingKey'])['requestId']===$id,'İlanın ürün eşleşmesi değişse de bekleyen önceki işlem görünür');

$f=new MarketplaceStockPilotFixture();
$keyFile=tempnam(sys_get_temp_dir(),'qa-n11-');
try{
    file_put_contents($keyFile,random_bytes(32));
    $vault=new \Omnigo\IntegrationVault($f->db,$keyFile);
    $vault->save(2,['enabled'=>true],'n11');
    $f->db->run("INSERT INTO records(company_id,kind,payload,version,updated) VALUES(2,'sync_n11',?,1,?)",['{"preview":{"sellerId":"123"}}',gmdate('c')]);
    try{
        MarketplaceStockJob::configuration($f->db,$vault,2,'n11');
        throw new RuntimeException('n11 önizlemesindeki satıcı kimliğiyle stok işleme alındı');
    }catch(DomainException $error){pilotCheck(str_contains($error->getMessage(),'n11 satıcı kimliği'),'Doğrulanmamış n11 kimliği için yöneticiye gerekçe gösterilir');}
    $f->db->run("UPDATE records SET payload=? WHERE company_id=2 AND kind='sync_n11'",['{"sellerId":"123"}']);
    pilotCheck(MarketplaceStockJob::configuration($f->db,$vault,2,'n11')['sellerId']==='123','Doğrulanan n11 satıcı kimliği seçili mağazadan alınır');
}finally{unlink($keyFile);}
echo "Pilot stok kuyruğu için $checks kontrol geçti.\n";
