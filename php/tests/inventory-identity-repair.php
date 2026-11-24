<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,MarketplaceStockSchema,InventoryCatalog,InventorySkuIdentity,InventoryOrderProduct,InventoryIdentityRepair};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);MarketplaceStockSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");$db->run('INSERT INTO inventory_jobs(company_id) VALUES(1),(2)');$catalog=new InventoryCatalog($db);
$first=$catalog->ingest(1,'idefix',['id'=>'current','sku'=>'MODEL1','title'=>'Marka MODEL1','barcode'=>'4242006295608','quantity'=>5]);
$catalog->ingest(1,'PTT AVM',['id'=>'legacy','sku'=>'MODEL1','title'=>'Marka MODEL1','quantity'=>0]);
$legacy=hash('sha256','legacy');$db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[1,$legacy,'Marka MODEL1','MODEL1','','',gmdate('c')]);
$db->run("UPDATE inventory_listings SET product_key=? WHERE company_id=1 AND provider='PTT AVM'",[$legacy]);$db->run("UPDATE inventory_aliases SET product_key=? WHERE company_id=1 AND provider='PTT AVM'",[$legacy]);
$db->run('INSERT INTO inventory_balances(company_id,provider,line_key,product_key,order_number,sequence_no,source_hash,ordered,cancelled,returned,consumed,invoice_cents,placed_at,updated) VALUES(1,?,?,?,?,1,?,2,1,0,1,10000,?,?)',['PTT AVM',hash('sha256','line'),$legacy,'order-1',hash('sha256','source'),gmdate('c'),gmdate('c')]);
$catalog->ingest(2,'PTT AVM',['id'=>'other','sku'=>'MODEL1','title'=>'Diğer mağaza','quantity'=>8]);$other=$db->all('SELECT * FROM inventory_products WHERE company_id=2');
$expected=[$first=>['sku'=>'MODEL1','barcode'=>'4242006295608'],$legacy=>['sku'=>'MODEL1','barcode'=>'']];$backup=null;
$result=(new InventoryIdentityRepair($db))->apply(1,$expected,[$legacy=>$first],[],function($value)use(&$backup){$backup=$value;});
check($result['merged']===1&&count($backup['rows']['inventory_products'])===2,'Yazmadan önce iki kaynak yedeklenmeli');
check((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=1')['n']===1,'Tek ürün kalmalı');
check(count($db->all('SELECT * FROM inventory_listings WHERE company_id=1 AND product_key=?',[$first]))===2,'Kaynak ilanlar silinmemeli');
$balance=$db->one('SELECT * FROM inventory_balances WHERE company_id=1');check($balance['product_key']===$first&&(int)$balance['ordered']===2&&(int)$balance['cancelled']===1&&(int)$balance['invoice_cents']===10000,'Tarihsel adet ve tutarlar değişmemeli');
check($db->all('SELECT * FROM inventory_products WHERE company_id=2')===$other,'Başka müşteri değişmemeli');
check((new InventoryOrderProduct($db))->resolve(1,['provider'=>'PTT AVM','sku'=>'MODEL1','title'=>'Marka MODEL1'])===$first,'Eski sipariş aliası aynı ürünü izlemeli');
check($catalog->ingest(1,'PTT AVM',['id'=>'legacy','sku'=>'MODEL1','title'=>'Marka MODEL1','quantity'=>0])===$first,'Yeni kaynak taraması eski satırı yeniden açmamalı');
$second=$catalog->ingest(1,'idefix',['id'=>'model2','sku'=>'MODEL2','title'=>'Marka MODEL2','barcode'=>'4242006295615','quantity'=>3]);
check($catalog->ingest(1,'Hepsiburada',['id'=>'typo','sku'=>'MODEL1','title'=>'Marka MODEL2','barcode'=>'4242006295615','quantity'=>0])===$first,'Satıcı kodu ürün adı ve GTIN’den bağımsızdır');
check($catalog->ingest(1,'Hepsiburada',['id'=>'typo','sku'=>'MODEL1','title'=>'Marka MODEL2','quantity'=>0])===$first,'Barkodsuz sonraki aktarım aynı kartı bulur');
$identity=new InventorySkuIdentity($db);
check($identity->catalogKey(1,' model2 ')===$second&&$identity->catalogKey(1,'MODEL21')===null,'Tam stok kodu karşılaştırması');
$db->run('UPDATE inventory_products SET quantity=7 WHERE company_id=1 AND product_key=?',[$second]);
try{(new InventoryIdentityRepair($db))->apply(1,[$second=>['sku'=>'MODEL2','barcode'=>'4242006295615']],[],[$second=>'MODEL3'],fn()=>null);throw new RuntimeException('Elle işlenen stok değiştirildi');}catch(DomainException){}
check($db->one('SELECT sku FROM inventory_products WHERE company_id=1 AND product_key=?',[$second])['sku']==='MODEL2','Reddedilen işlem geri alınmalı');
echo "Kimlik düzeltme: kaynak/alias/geçmiş miktar-tutar korunması, tekrar aktarım, model ayrımı ve müşteri sınırı geçti.\n";
