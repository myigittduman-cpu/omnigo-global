<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryProductPosition,InventoryRead,InventoryCatalog,CustomerPermissions,CustomerPermissionCatalog,InventoryPermissionPolicy,ReturnRequestOrder};
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL),(2,'logistics@example.test','Lojistik','hash','client',1),(3,'manager@example.test','Mağaza','hash','client',1)");
$all=CustomerPermissionCatalog::defaults();foreach($all as &$actions)$actions=array_fill_keys(array_keys($actions),true);unset($actions);
$profile=['workspacePolicy'=>['roles'=>['2'=>'accounting_logistics','3'=>'store_manager'],
 'pages'=>['inventory'=>'shown'],'inventory'=>['mode'=>'both'],'users'=>['2'=>$all,'3'=>$all]]];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode($profile,JSON_THROW_ON_ERROR)]);
$policy=new CustomerPermissions($db,1);
foreach([2,3,1] as $id){
 $user=['id'=>$id,'role'=>$id===1?'admin':'client','company_id'=>$id===1?null:1];
 $caps=InventoryPermissionPolicy::capabilities($policy,$user);
 verify($caps['viewMarketplaceStocks']===($id!==2),'Pazaryeri stok ayrıntısı yalnız lojistik rolünde kapalı.');
 if($id===2){$safe=InventoryPermissionPolicy::listing(['items'=>[['key'=>'a','quantity'=>3,'marketplaceStocks'=>[['quantity'=>4]],'marketplaceReference'=>['quantity'=>4]]]],$caps);
 verify(!isset($safe['items'][0]['marketplaceStocks'],$safe['items'][0]['marketplaceReference'])&&$safe['items'][0]['quantity']===3,'Stok ayrıntısı gizlenirken miktar korunmalı.');}
}
foreach([[1,'a','B'],[1,'b','A'],[2,'a','C']] as [$company,$key,$sku])
 $db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,quantity,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$key,'Ürün '.$sku,$sku,'','',5,'2026-11-01T00:00:00Z']);
InventoryProductPosition::install($db);
$read=fn()=>array_column((new InventoryRead($db))->productPage(1,filter:'all',sort:'stable')['rows'],'product_key');
verify($read()===['b','a'],'Başlangıçtaki stok kodu sırası korunmalı.');
$db->run("UPDATE inventory_products SET sku='Z',title='Güncel ürün',updated='2026-11-17T12:00:00Z' WHERE company_id=1 AND product_key='b'");
verify($read()===['b','a'],'Canlı bilgi değişikliği ürünün sırasını değiştirmemeli.');
(new InventoryCatalog($db))->saveProduct(1,'c','Yeni ürün','0','','2026-11-17T12:00:00Z');
verify($read()===['b','a','c'],'Yeni ürün eski ürünlerin sonuna eklenmeli.');
$db->run("INSERT INTO inventory_removed_products VALUES(1,'b','2026-11-17T12:00:00Z',1)");
verify($read()===['a','c'],'Gizlenen ürün listede görünmemeli.');
$db->run("DELETE FROM inventory_removed_products WHERE company_id=1 AND product_key='b'");
InventoryProductPosition::remember($db,1,'b');
verify($read()===['b','a','c'],'Geri dönen ürün önceki sırasına dönmeli.');
InventoryProductPosition::install($db);
verify($read()===['b','a','c'],'Yeniden kurulum sıra numaralarını değiştirmemeli.');
verify(count($db->all("SELECT * FROM inventory_product_positions WHERE company_id=2"))===1,'Mağazaların sıraları karışmamalı.');
$requests=[
 ['id'=>'unknown','createdAt'=>null,'checkedAt'=>'2027-01-01T00:00:00Z'],
 ['id'=>'older','createdAt'=>'2026-11-17T15:30:00+03:00'],
 ['id'=>'newer','createdAt'=>'2026-11-17T13:00:00Z'],
 ['id'=>'invalid','createdAt'=>'bad'],
];
usort($requests,ReturnRequestOrder::compare(...));
verify(array_column($requests,'id')===['newer','older','invalid','unknown'],'Talep sırası saat dilimini dikkate almalı; aktarım zamanı eski talebi üste taşımamalı.');
echo "Rol ayrımı, kalıcı ürün sırası ve talep tarihleri doğrulandı.\n";
