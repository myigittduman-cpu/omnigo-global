<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryProgress,InventoryMarketplaceAvailability};
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$fresh=['last_success'=>gmdate('c'),'error'=>null];
$source=['observedAt'=>gmdate('c'),'health'=>'current','quantity'=>0,'saleActive'=>true];
verify(InventoryMarketplaceAvailability::state('n11',[$source],$fresh)['status']==='active','Sıfır stok satışa kapalı varsayılmaz.');
verify(InventoryMarketplaceAvailability::state('n11',[[...$source,'saleActive'=>false]],$fresh)['status']==='closed','Açıkça kapalı bildirilen tanımlı ürün ayrılır.');
verify(InventoryMarketplaceAvailability::state('n11',[[...$source,'saleActive'=>null]],$fresh)['status']==='unknown','Kaynak doğrulamıyorsa satış durumu tahmin edilmez.');
verify(InventoryMarketplaceAvailability::state('n11',[$source,[...$source,'saleActive'=>false]],$fresh)['status']==='active','Birden fazla teklifte açık teklif korunur.');
verify(InventoryMarketplaceAvailability::state('n11',[], $fresh)['status']==='not_found','Tamamlanmış katalogda olmayan ürün ayrılır.');
verify(InventoryMarketplaceAvailability::state('n11',[], $fresh,true)['status']==='unmatched','Aynı koddaki eşleşme sorunu yok sayılmaz.');
foreach([[],[...$fresh,'error'=>'Kesinti'],[...$fresh,'last_success'=>gmdate('c',time()-3600)]] as $stale){
 verify(InventoryMarketplaceAvailability::state('n11',[],$stale)['status']==='unknown','Eksik veya eski katalog ürünün olmadığına kanıt değildir.');
}
verify(InventoryMarketplaceAvailability::state('n11',[[...$source,'saleActive'=>false,'observedAt'=>gmdate('c',time()-3600)]],$fresh)['status']==='unknown','Eski teklif kapalı sayılmaz.');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
foreach([1,2] as $company){foreach(['hepsiburada','n11','idefix'] as $provider)(new InventoryProgress($db))->ensure($company,$provider);}
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
$catalog=new InventoryCatalog($db);$key=$catalog->ingest(1,'Hepsiburada',['id'=>'first','sku'=>'ABC','title'=>'Ürün','quantity'=>1]);
// Same code in another tenant must not turn this tenant's missing product into a match.
$catalog->ingest(2,'n11',['id'=>'other-tenant','sku'=>'ABC','title'=>'Diğer','quantity'=>1]);
$products=$db->all('SELECT * FROM inventory_products WHERE company_id=1');
$service=new InventoryMarketplaceAvailability($db);
$map=array_column($service->forProducts(1,$products,[],[])[$key],null,'provider');
verify($map['n11']['status']==='not_found','Diğer müşterinin kataloğu bu müşterinin eşleşmesine karışmaz.');
// Explicit alias for a distinct identity simulates a source matching issue without merging it.
$other=$catalog->ingest(1,'n11',['id'=>'different','sku'=>'XYZ','title'=>'Farklı ürün','quantity'=>2]);
$db->run('INSERT INTO inventory_aliases(company_id,provider,alias_key,product_key) VALUES(?,?,?,?)',[1,'n11',hash('sha256','ABC'),$other]);
$map=array_column($service->forProducts(1,$products,[],[$key=>[['provider'=>'Pazarama']]])[$key],null,'provider');
verify($map['n11']['status']==='unmatched','Aynı kodun başka ürün kimliğinde olduğu gösterilir.');
verify(isset($map['idefix'])&&$map['Pazarama']['status']==='unknown','Etkin kataloglar ve geçmiş satış pazaryerleri eksiksiz görünür.');
verify((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=1')['n']===2,'Görünüm okuması ürünleri birleştirmez veya stok değiştirmez.');
echo "Marketplace availability: $checks checks passed\n";
