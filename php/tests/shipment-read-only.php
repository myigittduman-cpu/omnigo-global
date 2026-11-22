<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,DeliveryCipher,ShipmentRoutes,ShipmentWriteAccess,Security,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $action,int $expected=403):void{try{$action();}catch(HttpError $e){check($e->getCode()===$expected,$e->getMessage());return;}throw new RuntimeException('Müşteri yazma işlemi reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Müşteri','operatorName'=>'Yanlış İsim'];$admin=['id'=>1,'role'=>'admin'];
$routes=new ShipmentRoutes($db,new Security($db,[]),DeliveryCipher::withKey(str_repeat('p',32)));
$writes=[['','POST'],['/commit','POST'],['/1/delete','POST'],['/1/documents/invoice','POST'],['/status-preview','POST'],['/status','POST'],['/1/details','PUT'],['/1','PUT'],['/editing','POST'],['/unknown','PUT']];
$grants=\Omnigo\CustomerPermissionCatalog::defaults();foreach($grants['shipments'] as $action=>&$allowed)if(!in_array($action,['view','documents','export'],true))$allowed=false;unset($allowed);
$grants['shipmentInvoices']['view']=true;$grants['shipmentInvoices']['documents']=true;$grants['shipmentInvoices']['upload']=false;
$db->run("INSERT INTO company_profiles(company_id,payload) VALUES(1,?)",[json_encode(['workspacePolicy'=>['defaults'=>$grants]])]);
$before=$db->all('SELECT * FROM shipment_entries');
foreach([1,2] as $company){$_GET=['company'=>(string)$company];foreach($writes as [$path,$method]){
 denied(fn()=>\Omnigo\CustomerPermissionRoutes::shipment(new \Omnigo\CustomerPermissions($db,$company),$client,'/api/shipments'.$path,$method,[]));
 denied(fn()=>$routes->handle('/api/admin/shipments'.$path,$method,$client));
 ShipmentWriteAccess::requireAllowed($admin,'/api/shipments'.$path,$method);
}}
$policy=new \Omnigo\CustomerPermissions($db,1);
foreach([$client,$admin] as $actor){
 denied(fn()=>\Omnigo\CustomerPermissionRoutes::shipment($policy,$actor,'/api/shipments/1/documents/delivery','POST'),410);
}
\Omnigo\CustomerPermissionRoutes::shipment($policy,$client,'/api/shipments/1/documents/invoice','GET');
foreach([['','GET'],['/revision','GET'],['/editing','GET'],['/search','POST'],['/export','POST'],['/1/documents/invoice','GET']] as [$path,$method])ShipmentWriteAccess::requireAllowed($client,'/api/shipments'.$path,$method);
check($before===$db->all('SELECT * FROM shipment_entries'),'Reddedilen istekler kayıtlara dokunmaz');
echo "Müşterinin salt okuma yetkisi, fatura erişimi ve emekli teslimat belgesi kuralı doğrulandı.\n";
