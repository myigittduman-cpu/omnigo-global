<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ManagementSchema,CustomerManagement,CustomerBranding,CustomerStores,ShipmentRevision,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $run,int $code):void{try{$run();}catch(HttpError $e){check($e->getCode()===$code,'Hata kodu');return;}throw new RuntimeException('Ret bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ManagementSchema::install($db);
$db->run("INSERT INTO companies(id,name) VALUES(1,'İlk firma'),(2,'Başka firma')");
$db->run("INSERT INTO users(id,email,name,password,role) VALUES(1,'admin@example.test','Yönetici','hash','admin')");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client'];$manager=new CustomerManagement($db);$stores=new CustomerStores($db);
$before=ShipmentRevision::read($db,1);
$manager->saveProfile($admin,1,['name'=>'Yeni firma','notes'=>'Gizli not','phone'=>'Özel iletişim','version'=>0]);
$identity=CustomerBranding::read($db,1);
check($identity===['id'=>1,'name'=>'Yeni firma','hasLogo'=>false,'brandingVersion'=>1],'Güvenli kimlik alanları');
check(ShipmentRevision::read($db,1)!==$before,'Canlı değişiklik işareti');
check(CustomerBranding::read($db,2)['name']==='Başka firma','Firma izolasyonu');
$db->run('UPDATE company_profiles SET logo=?,version=version+1 WHERE company_id=1',['synthetic-logo']);
check(CustomerBranding::read($db,1)['hasLogo']&&CustomerBranding::read($db,1)['brandingVersion']===2,'Logo sürümü');
$manager->setLogo($admin,1,null);check(!CustomerBranding::read($db,1)['hasLogo']&&CustomerBranding::read($db,1)['brandingVersion']===3,'Logo kaldırma');
$payload=['name'=>'Hepsiburada','storeName'=>'Eski mağaza','status'=>'active','connection'=>'connected','integration'=>['cursor'=>'keep-me']];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode($payload),gmdate('c')]);$id=$db->insertId();
$revision=ShipmentRevision::read($db,1);
rejects(fn()=>$stores->save($client,1,$id,['storeName'=>'Yetkisiz','version'=>1]),403);
rejects(fn()=>$stores->save($admin,2,$id,['storeName'=>'Yanlış firma','version'=>1]),404);
$stores->save($admin,1,$id,['storeName'=>'Yeni mağaza','version'=>1]);
$saved=$db->one('SELECT * FROM records WHERE id=?',[$id]);$payload['storeName']='Yeni mağaza';
check(json_decode($saved['payload'],true)===$payload,'Bağlantı ve diğer alanlar korunur');check((int)$saved['version']===2,'Sürüm artışı');
check(ShipmentRevision::read($db,1)!==$revision,'Mağaza canlı aktarım işareti');
rejects(fn()=>$stores->save($admin,1,$id,['storeName'=>'Eski istek','version'=>1]),409);
check($stores->list(1)[0]===['name'=>'Hepsiburada','storeName'=>'Yeni mağaza','id'=>$id,'version'=>2],'Mağaza formu güvenli alanları');
echo "Müşteri ayarları: yetki, firma izolasyonu, sürüm çakışması, canlı değişiklik işareti, logo sürümü ve bağlantı bilgilerinin korunması geçti.\n";
