<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryOrderProduct};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);$db->run("INSERT INTO companies(id,name) VALUES(1,'Bir'),(2,'İki')");
$catalog=new InventoryCatalog($db);$sku='43PA525EQG';
$first=$catalog->ingest(1,'Hepsiburada',['id'=>'HB','sku'=>$sku,'title'=>'TV','quantity'=>2,'barcode'=>'8684975450149']);
$db->run('UPDATE inventory_products SET quantity=2,version=3 WHERE company_id=1 AND product_key=?',[$first]);
$db->run('INSERT INTO inventory_purchase_prices VALUES(?,?,?,?,?,?)',[1,$first,2400000,1,1,gmdate('c')]);
$price=$db->one('SELECT * FROM inventory_purchase_prices WHERE company_id=1');
$second=$catalog->ingest(1,'n11',['id'=>'N11','sku'=>' 43pa525eqg ','title'=>'TV farklı başlık','quantity'=>2,'barcode'=>'4242006325732']);
$third=$catalog->ingest(1,'PTT AVM',['id'=>'PTT','sku'=>$sku,'title'=>'TV','quantity'=>2]);
check($first===$second&&$second===$third,'Farklı barkod ve başlık aynı mağazanın aynı kesin stok kodunu bölmemeli.');
check((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=1')['n']===1,'Üç pazaryerinde tek ürün olmalı.');
check($db->one('SELECT * FROM inventory_purchase_prices WHERE company_id=1')===$price,'Alış fiyatı ve sürümü korunmalı.');
check((int)$db->one('SELECT quantity FROM inventory_products WHERE company_id=1')['quantity']===2,'Kaynak stokları toplanmamalı.');
$sources=$db->all('SELECT provider,evidence FROM inventory_listings WHERE company_id=1');$n11=array_values(array_filter($sources,fn($s)=>$s['provider']==='n11'))[0];check(json_decode($n11['evidence'],true)['barcode']==='4242006325732','Kaynak barkodu kanıt olarak korunmalı.');
$catalog->ingest(2,'Hepsiburada',['id'=>'HB','sku'=>$sku,'title'=>'Diğer mağaza TV','quantity'=>7]);
check((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=2')['n']===1,'Diğer mağazanın kaydı ayrı kalmalı.');
$other=$catalog->ingest(1,'n11',['id'=>'OTHER','sku'=>'43PA526EQG','title'=>'TV','quantity'=>3]);check($other!==$first,'Benzer stok kodu veya aynı başlık eşleştirilmemeli.');
// A retained historic source points to the canonical row; new aliases and order statistics follow it.
$legacy=hash('sha256','legacy');$db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[1,$legacy,'TV',$sku,'','',gmdate('c')]);
$db->run("INSERT INTO inventory_links(company_id,product_key,provider,source_product,match_code,state,reason,requested_at,started_at) VALUES(?,?,?,?,?,'active','',?,?)",[1,$first,'PTT AVM',$legacy,$sku,gmdate('c'),gmdate('c')]);
$db->run('UPDATE inventory_listings SET product_key=? WHERE company_id=1 AND provider=?',[$legacy,'PTT AVM']);
$db->run('UPDATE inventory_aliases SET product_key=? WHERE company_id=1 AND provider=?',[$legacy,'PTT AVM']);
$again=$catalog->ingest(1,'PTT AVM',['id'=>'PTT-NEW','sku'=>$sku,'title'=>'TV','quantity'=>2]);check($again===$first,'Yeni katalog kimliği eski bağlı satırı yeniden açmamalı.');
check($catalog->matchSku(1,'PTT AVM',$sku)===$first,'Eski ve yeni alias aynı tek ürüne çözülmeli.');
check((new InventoryOrderProduct($db))->resolve(1,['provider'=>'PTT AVM','sku'=>$sku,'title'=>'TV'])===$first,'Sipariş satırı da aynı ürünü bulmalı.');
echo "Kesin stok kodu tekilliği, farklı barkod kanıtı, fiyat/miktar koruma, tarihsel bağlantı ve müşteri ayrımı geçti.\n";
