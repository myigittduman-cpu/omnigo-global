<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerPermissions,CustomerPermissionCatalog,CustomerPermissionRoutes,InventorySchema,DeliverySchema,ShipmentSchema,Security,FileCenterRoutes,FinanceOverviewRoutes,ProfitabilityReport,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $run):void{try{$run();}catch(HttpError $e){check($e->getCode()===403,$e->getMessage());return;}throw new RuntimeException('İzinsiz işlem reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'VİZYONA'),(2,'İkinci Mağaza'),(4,'Dördüncü Mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'v@example.test','Bir','x','client',1),(5,'t@example.test','İki','x','client',2),(19,'f@example.test','Dört','x','client',4)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>5,'role'=>'client','company_id'=>2];
$baseline=(new CustomerPermissions($db,1))->access(['id'=>2,'role'=>'client','company_id'=>1]);
foreach([2,4] as $company){
 $policy=new CustomerPermissions($db,$company);$cfg=$policy->configuration();
 check(!$policy->allows(['id'=>$company===2?5:19,'role'=>'client','company_id'=>$company],'fileCenter'),'Dosya Merkezi kendiliğinden açılmaz.');
 $grants=$cfg['defaults'];$pages=$cfg['pages'];
 foreach(['overview','overviewSales','overviewTop','overviewWork','fileCenter','orders','returns','inventory','deliveries','shipments','financeProfitability','analytics','payments','support','shipmentInvoices'] as $page){
  $pages[$page]='all_roles';$grants[$page]=array_fill_keys(CustomerPermissionCatalog::pages()[$page]['actions'],true);
 }
 $grants['shipments']['create']=$grants['shipments']['delete']=false;
 $id=$company===2?5:19;
 $policy->save($admin,['version'=>$cfg['version'],'pages'=>$pages,'defaults'=>$grants,'inventory'=>['mode'=>'horoz','marketplaceManagement'=>false],'userChanges'=>[$id=>$grants]]);
 $current=new CustomerPermissions($db,$company);$actor=['id'=>$id,'role'=>'client','company_id'=>$company];
 foreach(['fileCenter','financeProfitability','overview','orders','returns','inventory','deliveries','shipments','analytics','payments','support'] as $page)check($current->allows($actor,$page),'Menü açık: '.$page);
 foreach(['create','delete'] as $action)check(!$current->allows($actor,'shipments',$action),'Sevkiyat sınırı: '.$action);
 foreach(['payments','returns'] as $page)foreach(['create','edit','delete','status'] as $action)check(!$current->allows($actor,$page,$action),'Salt görüntüleme: '.$page.'/'.$action);
 foreach(['viewCancelledRecords','viewReturnedRecords','viewAfterCancellationTurnover'] as $action)check($current->allows($actor,'overview',$action),'İptal ve ciro görünür: '.$action);
 denied(fn()=>CustomerPermissionRoutes::shipment($current,$actor,'/api/shipments/commit','POST',['changes'=>[['type'=>'create']]]));
 denied(fn()=>CustomerPermissionRoutes::shipment($current,$actor,'/api/shipments/commit','POST',['changes'=>[['type'=>'delete']]]));
 CustomerPermissionRoutes::shipment($current,$actor,'/api/shipments/commit','POST',['changes'=>[['type'=>'update']]]);
 check($current->allows($actor,'deliveries','selectDocuments')&&$current->allows($actor,'deliveries','export'),'Çıktı için seçim ve seçim bırakma açık.');
}
check($baseline===(new CustomerPermissions($db,1))->access(['id'=>2,'role'=>'client','company_id'=>1]),'VİZYONA erişimi değişmedi.');
check($db->one('SELECT payload FROM company_profiles WHERE company_id=1')===null,'VİZYONA profiline yazılmadı.');
$security=new Security($db,['origin'=>'http://localhost']);$_GET=['company'=>'1'];
denied(fn()=>(new FileCenterRoutes($db,$security))->handle('/api/file-center/products','GET',$client));
denied(fn()=>(new FinanceOverviewRoutes($db,$security))->handle('/api/finance/profitability','GET',$client));
denied(fn()=>(new ProfitabilityReport($db))->read($client,1,[]));
$_GET=['company'=>'2'];denied(fn()=>(new FinanceOverviewRoutes($db,$security))->handle('/api/finance/profitability/commission-rates','POST',$client));
$policy=new CustomerPermissions($db,2);$cfg=$policy->configuration();$grants=((array)$cfg['users'])[5];
$grants['fileCenter']['create']=$grants['fileCenter']['delete']=$grants['fileCenter']['export']=$grants['financeProfitability']['export']=false;
$policy->save($admin,['version'=>$cfg['version'],'userChanges'=>[5=>$grants]]);
$fileRoutes=new FileCenterRoutes($db,$security);
denied(fn()=>$fileRoutes->handle('/api/file-center/archive','POST',$client));
denied(fn()=>$fileRoutes->handle('/api/file-center/archive/selection','DELETE',$client));
denied(fn()=>$fileRoutes->handle('/api/file-center/archive/1','GET',$client));
$_GET['export']='1';denied(fn()=>(new FinanceOverviewRoutes($db,$security))->handle('/api/finance/profitability','GET',$client));
echo "Mağaza izolasyonu, düzenlenebilir menüler, salt görüntüleme, çıktı seçimi ve sevkiyat sınırları doğrulandı.\n";
