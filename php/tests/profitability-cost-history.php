<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliverySchema,InventoryCatalog,InventoryPurchasePrices,InventoryPurchaseTax,InventorySchema,
    ProfitabilityCostBinding,ProfitabilityCostHistory,ProfitabilityOrderCapture,ProfitabilitySchema};

function check(bool $ok,string $detail): void {if(!$ok)throw new RuntimeException($detail);}
function rejected(callable $action): bool
{
    try{$action();return false;}catch(DomainException|InvalidArgumentException|OutOfBoundsException){return true;}
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
InventoryPurchaseTax::install($db);
DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$catalog=new InventoryCatalog($db);
$source=['id'=>'LIST-1','sku'=>'SKU-1','title'=>'Aynı model ürün','quantity'=>10];
$key=$catalog->ingest(1,'Hepsiburada',$source);
$other=$catalog->ingest(2,'Hepsiburada',$source);
$separate=$catalog->ingest(1,'Hepsiburada',[...$source,'id'=>'LIST-2','sku'=>'SKU-2']);
$actor=['role'=>'admin','id'=>1];
$store=new InventoryPurchasePrices($db);
$first=['productKey'=>$key,'version'=>0,'amountCents'=>50,'requestId'=>'purchase-history-init'];
check(rejected(fn()=>$store->save(1,[...$first,'applyToPastOrders'=>false],$actor)),'Eksik şemada seçenek uygulanmış sayıldı.');
$store->save(1,$first,$actor);
$store->save(2,[...$first,'productKey'=>$other,'amountCents'=>1100],$actor);
$store->save(1,[...$first,'productKey'=>$separate,'amountCents'=>440,'requestId'=>'purchase-history-other'],$actor);
$clock=time();
$db->run('UPDATE inventory_purchase_prices SET updated=?',[gmdate('c',$clock-1000)]);
ProfitabilitySchema::install($db);
ProfitabilitySchema::installCostHistory($db);
$db->run('INSERT INTO profitability_activation VALUES(1,?)',[$clock-900]);
$line=['lineId'=>'1','sku'=>'SKU-1','sellerStockCode'=>'SKU-1','title'=>$source['title'],'quantity'=>2,'sourceProduct'=>['listingId'=>'LIST-1']];
$second=[...$line,'lineId'=>'2','sku'=>'SKU-2','sellerStockCode'=>'SKU-2','sourceProduct'=>['listingId'=>'LIST-2']];
$ambiguous=[...$line,'lineId'=>'3','sourceProduct'=>['listingId'=>'CONFLICTING']];
$sample=fn($id,$at,$lines)=>['provider'=>'Hepsiburada','externalId'=>$id,'orderNumber'=>$id,'createdAt'=>gmdate('c',$at),'details'=>['products'=>$lines]];
$capture=new ProfitabilityOrderCapture($db);
$observe=fn($company,$order)=>$db->transaction(fn()=>$capture->observe($company,$order));
$legacy=$sample('OLD',$clock-150,[$line,$second,$ambiguous]);
$observe(1,$legacy);
$observe(2,$sample('OLD',$clock-150,[$line]));
$history=new ProfitabilityCostHistory($db);
$cost=function($company,$id,$lineId='1')use($db,$history){
    $order=$db->one("SELECT * FROM profitability_orders WHERE company_id=? AND provider='hepsiburada' AND external_id=?",[$company,$id]);
    $items=array_column($history->lines($company,$order,true),null,'line_key');
    return $items[hash('sha256',$lineId)]['unit_purchase_cents'];
};
check($cost(1,'OLD')===50&&$cost(2,'OLD')===1100&&$cost(1,'OLD','2')===440&&$cost(1,'OLD','3')===null,'Başlangıç maliyetleri hatalı.');
$snapshot=$db->all('SELECT * FROM profitability_lines ORDER BY company_id,external_id,line_key');
// Simulate snapshots installed by the previous version, then recover solely from stored order evidence.
$db->run("DELETE FROM profitability_line_products WHERE company_id=1 AND external_id='OLD'");
$cipher=DeliveryCipher::withKey(str_repeat('Q',32));
$payload=$cipher->seal(1,'order:Hepsiburada:OLD',$legacy);
$db->run("INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(1,'Hepsiburada','OLD','Ankara',0,?,'source',?)",[$payload,gmdate('c')]);
$fill=new ProfitabilityCostBinding($db);
check($fill->batch($cipher,0,1)===['cursor'=>1,'bound'=>2]&&$fill->batch($cipher,0,1)['bound']===0,'Eski kaynak eşlemesi veya tekrar sorunu.');
$body=['productKey'=>$key,'version'=>1,'amountCents'=>500,'vatBasis'=>'excluded','vatRateBasisPoints'=>2000,'requestId'=>'purchase-history-future','applyToPastOrders'=>false];
$future=$store->save(1,$body,$actor);
check($future['purchaseGrossCents']===600&&$cost(1,'OLD')===50,'İleri tarihli kayıt geçmişi değiştirdi.');
$stamp=(int)$db->one('SELECT starts_at FROM profitability_cost_rules WHERE company_id=1 AND product_key=? AND version=2',[$key])['starts_at'];
$observe(1,$sample('BEFORE',$stamp-1,[$line]));
$observe(1,$sample('AT',$stamp,[$line]));
check($cost(1,'BEFORE')===50&&$cost(1,'AT')===600,'Kayıt saniyesi dahil olmalı ve bir önceki saniye korunmalı.');
$retro=[...$body,'version'=>2,'requestId'=>'purchase-history-retro','amountCents'=>700,'applyToPastOrders'=>true];
$applied=$store->save(1,$retro,$actor);
check($applied['purchasePriceVersion']===3&&$cost(1,'OLD')===840&&$cost(1,'BEFORE')===840&&$cost(1,'AT')===840,'Geçmişteki aynı ürünü %20 KDV dahil yeniden hesaplamadı.');
check($cost(1,'OLD','2')===440&&$cost(1,'OLD','3')===null&&$cost(2,'OLD')===1100,'Diğer ürün, belirsiz kaynak veya mağaza etkilendi.');
check($store->save(1,$retro,$actor)['replayed']&&rejected(fn()=>$store->save(1,[...$retro,'applyToPastOrders'=>false],$actor)),'Tekrar eden istek veya değiştirilmiş kapsam yanlış kabul edildi.');
check(rejected(fn()=>$store->save(1,[...$retro,'requestId'=>'purchase-history-stale'],$actor)),'Eski sürüme izin verildi.');
check(rejected(fn()=>$store->save(2,[...$retro,'productKey'=>$other],['id'=>3,'role'=>'client','company_id'=>1])),'Başka mağazadan sürüm değiştirilmesine izin verildi.');
$observe(1,$sample('LATE',$clock-180,[$line]));
check($cost(1,'LATE')===840,'Sonradan aktarılan eski sipariş kapsam dışı kaldı.');
check($db->all("SELECT * FROM profitability_lines WHERE external_id='OLD' ORDER BY company_id,external_id,line_key")===$snapshot,'Geçmiş sipariş satırlarının aslı değişti.');
$next=[...$first,'version'=>3,'amountCents'=>0,'requestId'=>'purchase-history-zero','applyToPastOrders'=>false];
$store->save(1,$next,$actor);
$zeroAt=(int)$db->one('SELECT starts_at FROM profitability_cost_rules WHERE company_id=1 AND product_key=? AND version=4',[$key])['starts_at'];
check($history->amountAt(1,$key,$zeroAt)===0&&$history->amountAt(1,$key,$stamp-1)===840,'Sıfır veya Evet ardından Hayır hatalı.');
$same=[...$next,'version'=>4,'requestId'=>'purchase-history-equal','applyToPastOrders'=>true];
check($store->save(1,$same,$actor)['purchasePriceVersion']===5&&$cost(1,'OLD')===0,'Aynı fiyatla geçmişe uygulama gerçekleşmedi.');
$legacyBody=[...$first,'version'=>5,'amountCents'=>300,'requestId'=>'purchase-history-legacy'];
$store->save(1,$legacyBody,$actor);
check($cost(1,'OLD')===0&&$store->save(1,[...$legacyBody,'applyToPastOrders'=>false],$actor)['replayed'],'Eski istemcinin varsayılanı ve isteğin tekrarı yanlış.');
foreach([null,0,'true',1,[]] as $value)check(rejected(fn()=>$store->save(1,[...$body,'applyToPastOrders'=>$value],$actor)),'Kapsam kesin boolean olmalı.');
check(rejected(fn()=>$store->save(1,[...$first,'amountCents'=>null,'version'=>6,'requestId'=>'purchase-history-empty'],$actor)),'Boş tutar geçmiş maliyeti silmemeli.');
check($db->one('SELECT COUNT(*) AS n FROM inventory_ledger')['n']===0,'Stok hareketleri değişti.');
echo "Geçmiş ve ileri tarihli maliyet, KDV, kayıt saniyesi, geç aktarım, aynı tutar, sıfır, sürüm, tenant ve kaynak eşleme doğrulandı.\n";
