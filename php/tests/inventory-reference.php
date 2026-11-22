<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryProductState,InventoryTotals};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$sources=[['provider'=>'Hepsiburada','quantity'=>0,'saleActive'=>false,'health'=>'current'],['provider'=>'n11','quantity'=>8,'saleActive'=>true,'health'=>'current'],['provider'=>'Pazarama','quantity'=>20,'saleActive'=>true,'health'=>'stale']];
$result=InventoryTotals::calculate('both',null,$sources);
check($result['marketplaceQuantity']===8&&$result['marketplaceReference']['provider']==='n11','Satışa açık güncel kaynak seçilmeli.');
check($result['marketplaceStockDifferent']&&$result['storeQuantity']===null&&$result['totalQuantity']===null&&!$result['critical'],'Sayılmamış mağaza stoğu sıfır kabul edilmemeli.');
$result=InventoryTotals::calculate('both',5,$sources);
check($result['totalQuantity']===13&&$result['verifiedTotalQuantity']===null,'Gösterim 8+5; eski ve farklı kaynaklar bildirim toplamını doğrulamaz.');
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$catalog=new InventoryCatalog($db);
foreach([1,2] as $company){
 $catalog->ingest($company,'n11',['id'=>$company===1?'real-id':'order-sku:SKU','sku'=>'SKU','title'=>'Ürün','quantity'=>2,'saleActive'=>true]);
 $key=$db->one('SELECT product_key FROM inventory_products WHERE company_id=?',[$company])['product_key'];
 $db->run('INSERT INTO inventory_pending_lines VALUES(?,?,?,?,?)',[$company,'n11','line',$key,1]);
}
$state=new InventoryProductState($db);
check(count($state->pending(1))===0,'Gerçek kataloğa bağlı ürün, mağaza sayımı beklese de eşleştirme uyarısı vermez.');
check(count($state->pending(2))===1,'Başka müşterinin eşleşmesi eksik eşleşmeyi kapatmaz.');
echo "Stok kaynağı, sayım ve müşteri ayrımı kontrolleri geçti.\n";
