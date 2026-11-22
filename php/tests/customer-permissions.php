<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerPermissions,CustomerPermissionCatalog,CustomerPermissionRoutes,CustomerUserProvisioning,CustomerCities,CustomerOnboarding,CustomerManagement,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentOrderIngestor,HttpError,TurkeyCities,DeliveryMapper};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $action,int $code):void{try{$action();}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('İşlem reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));\Omnigo\InventorySchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'a@example.test','A','x','client',1),(3,'b@example.test','B','x','client',1),(4,'c@example.test','C','x','client',2)");
$admin=['id'=>1,'role'=>'admin'];$a=['id'=>2,'role'=>'client','company_id'=>1];$b=['id'=>3,'role'=>'client','company_id'=>1];
$policy=new CustomerPermissions($db,1);check($policy->allows($a,'shipments')&&!$policy->allows($a,'shipments','edit'),'Eski izinler korunur');
$config=$policy->configuration();$input=[...$config,'userChanges'=>['2'=>$config['defaults']]];unset($input['users']);
$input['userChanges']['2']['shipments']['edit']=true;$input['userChanges']['2']['shipments']['status']=true;$input['userChanges']['2']['deliveries']['export']=true;
$saved=$policy->save($admin,$input);$policy=new CustomerPermissions($db,1);
check($policy->allows($a,'shipments','edit')&&!$policy->allows($b,'shipments','edit'),'Kullanıcılar birbirinden bağımsız');
CustomerPermissionRoutes::shipment($policy,$a,'/api/shipments/commit','POST',['changes'=>[(object)['type'=>'update']]]);
denied(fn()=>CustomerPermissionRoutes::shipment($policy,$a,'/api/shipments/commit','POST',['changes'=>[(object)['type'=>'update'],(object)['type'=>'delete']]]),403);
denied(fn()=>$policy->save($a,$input),403);denied(fn()=>$policy->save($admin,$input),409);
$input=[...$saved,'userChanges'=>['4'=>$saved['defaults']]];unset($input['users']);denied(fn()=>$policy->save($admin,$input),403);
$input=[...$saved,'userChanges'=>(object)[]];unset($input['users']);$input['pages']['deliveries']='hidden';
$saved=$policy->save($admin,$input);$policy=new CustomerPermissions($db,1);
check(!$policy->allows($a,'deliveries'),'Gizli bölüm erişimi kapalı');
$new=CustomerUserProvisioning::create($db,$admin,1,['email'=>'new@example.test','name'=>'Yeni','password'=>'Example!Only1234']);
check(isset(((array)(new CustomerPermissions($db,1))->configuration()['users'])[$new]),'Yeni kullanıcıya açık yetki kaydı atanır');
$config=(new CustomerPermissions($db,1))->configuration();(new CustomerManagement($db))->saveProfile($admin,1,['name'=>'Örnek Güncel','version'=>$config['version']]);
check((new CustomerPermissions($db,1))->allows($a,'shipments','edit'),'Firma kaydı yetki ve şehirleri korur');
$restricted=(new CustomerPermissions($db,1))->configuration();$restricted['defaults']['shipments']['edit']=false;$restricted['defaults']['shipments']['create']=false;$restricted['applyDefaultsToNewAccounts']=true;$restricted['userChanges']=(object)[];unset($restricted['users']);
(new CustomerPermissions($db,1))->save($admin,$restricted);
$limited=CustomerUserProvisioning::create($db,$admin,1,['email'=>'limited@example.test','name'=>'Sınırlı Kullanıcı','password'=>'Example!Only1234']);
$limitedActor=['id'=>$limited,'role'=>'client','company_id'=>1];$currentPolicy=new CustomerPermissions($db,1);
check(!$currentPolicy->allows($limitedActor,'shipments','edit')&&!$currentPolicy->allows($limitedActor,'shipments','create'),'Yeni kullanıcı müşterinin seçilen varsayılan işlem kısıtlarını alır');
check($currentPolicy->allows($a,'shipments','edit'),'Yeni hesap oluşturmak mevcut özel kullanıcı yetkisini değiştirmez');
$company=CustomerOnboarding::create($db,$admin,'Satış odaklı',null,['shipments'=>false,'deliveries'=>false],[],['overview','orders','channels','analytics']);
$newPolicy=(new CustomerPermissions($db,$company))->configuration();
check($newPolicy['pages']['orders']==='shown'&&!$newPolicy['defaults']['orders']['view']&&$newPolicy['pages']['deliveries']==='removed','Başlangıç sayfaları ve kullanıcı işlem yetkileri bağımsız');
echo "Müşteri/kullanıcı izolasyonu, sunucu yetkileri, sürüm çakışması, yeni hesap ve başlangıç bölümleri geçti.\n";
