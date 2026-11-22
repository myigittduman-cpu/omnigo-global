<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryPermissionPolicy,CustomerPermissions,CustomerPermissionCatalog,InventoryRoutes,Security,HttpError};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL),(2,'a@example.test','A','hash','client',1),(3,'b@example.test','B','hash','client',1),(4,'c@example.test','C','hash','client',2)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$other=['id'=>4,'role'=>'client','company_id'=>2];$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $run,int $code=403):void{try{$run();}catch(HttpError $error){verify($error->getCode()===$code,$error->getMessage());return;}throw new RuntimeException('İzinsiz işlem reddedilmedi.');}
function configure(array $grants,array $users=[]):CustomerPermissions{global $db;$profile=['workspacePolicy'=>['pages'=>['inventory'=>'shown'],'inventory'=>['mode'=>'both'],'defaults'=>['inventory'=>$grants],'users'=>$users]];$db->run('DELETE FROM company_profiles WHERE company_id=1');$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode($profile,JSON_THROW_ON_ERROR)]);return new CustomerPermissions($db,1);}
$policy=configure(['view'=>true]);$caps=InventoryPermissionPolicy::capabilities($policy,$client);
verify($caps['viewPurchasePrice']&&$caps['editPurchasePrice']&&$caps['viewMovements']&&$caps['viewSales']&&$caps['viewMarketplaceStocks'],'Eski okuma ve alış fiyatı davranışı korunur.');
verify(!$caps['editStock']&&!$caps['recordStoreSale'],'Mevcut müşteriye yeni stok yazma yetkisi kendiliğinden verilmez.');
denied(fn()=>$policy->access($other));
$all=CustomerPermissionCatalog::defaults()['inventory'];$all=array_fill_keys(array_keys($all),true);
foreach(array_keys($all) as $only){
 $rights=array_fill_keys(array_keys($all),false);$rights['view']=true;$rights[$only]=true;if($only==='editPurchasePrice')$rights['viewPurchasePrice']=true;
 $policy=configure($rights);foreach(InventoryPermissionPolicy::capabilities($policy,$client) as $action=>$allowed)verify($allowed===$rights[$action],'Bağımsız işlem: '.$only.'/'.$action);
}
$readOnly=array_fill_keys(array_keys($all),false);$readOnly['view']=true;$policy=configure($all,['2'=>['inventory'=>$readOnly]]);
verify(!$policy->allows($client,'inventory','editStock')&&$policy->allows(['id'=>3,'role'=>'client','company_id'=>1],'inventory','editStock'),'Bir kullanıcının kısıtı diğer kullanıcılara taşınmaz.');
verify(array_filter(InventoryPermissionPolicy::capabilities($policy,$admin))===$all,'Omnigo tüm işlemlere erişir.');
$data=['items'=>[['key'=>'product','quantity'=>10,'purchasePriceCents'=>12345,'purchasePriceVersion'=>2,'purchasePriceUpdated'=>'today','markets'=>[['net'=>4]],'storeSales'=>2,'marketplaceAvailability'=>[['status'=>'active']],'marketplaceStocks'=>[['quantity'=>10]],'marketplaceReference'=>['quantity'=>10]]]];
$safe=InventoryPermissionPolicy::listing($data,InventoryPermissionPolicy::capabilities($policy,$client));$row=$safe['items'][0];verify($row['key']==='product'&&$row['quantity']===10,'Ana stok miktarı ve ürün kimliği korunur.');foreach(['purchasePriceCents','purchasePriceVersion','purchasePriceUpdated','markets','storeSales','marketplaceAvailability','marketplaceStocks','marketplaceReference'] as $field)verify(!array_key_exists($field,$row),'Kısıtlı alan API yanıtında yok: '.$field);
verify(isset($data['items'][0]['purchasePriceCents']),'Yanıt ayıklama kaynak veriyi değiştirmez.');
$routes=new InventoryRoutes($db,new Security($db,['origin'=>'http://localhost']));$_GET=['company'=>'1'];
denied(fn()=>$routes->handle('/api/inventory/purchase-price','POST',$client));denied(fn()=>$routes->handle('/api/inventory/history','GET',$client));
$_GET=['company'=>'2'];denied(fn()=>$routes->handle('/api/inventory/history','GET',$client));
foreach(['store_sale','store_return'] as $mode)verify(InventoryPermissionPolicy::commandAction(['mode'=>$mode])==='recordStoreSale','Satış ve iade ortak satış iznine bağlı.');
foreach(['set','initialize','add','subtract','invalid'] as $mode)verify(InventoryPermissionPolicy::commandAction(['mode'=>$mode])==='editStock','Sayım/giriş/düzeltme stok düzenleme iznine bağlı.');
$dependent=CustomerPermissionCatalog::validateGrants(array_replace(CustomerPermissionCatalog::defaults(),['inventory'=>[...$all,'viewPurchasePrice'=>false]]));verify(!$dependent['inventory']['editPurchasePrice'],'Görünmeyen alış fiyatı düzenlenemez.');
$hidden=configure(['view'=>false]);verify(!array_filter(InventoryPermissionPolicy::capabilities($hidden,$client)),'Bölüm görüntüleme kapalıyken alt izinler etkisiz.');
echo "Stok yetkileri: $checks kontrol geçti.\n";
