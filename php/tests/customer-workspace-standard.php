<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AccountDirectorySchema,AccountDirectoryWriter,AccountSettingsSchema,CustomerAccountRoles,CustomerInitialPolicy,
    CustomerOnboarding,CustomerPermissionCatalog,CustomerPermissionRoutes,CustomerPermissions,CustomerStandardPermissions,
    CustomerUserProvisioning,CustomerWorkspaceStandard,Database,DeliveryCipher,FileCenterRoutes,HttpError,InventoryPermissionPolicy,
    InventorySchema,NotificationSchema,OrderDocumentRoutes,Security,ShipmentDocuments,ShipmentRepository,ShipmentSchema,ShipmentWriter,SupportSchema};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $call):void{try{$call();}catch(HttpError $e){check($e->getCode()===403,$e->getMessage());return;}throw new RuntimeException('İzinsiz işlem kabul edildi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
AccountSettingsSchema::install($db);AccountDirectorySchema::ensure($db);
$db->run("INSERT INTO companies VALUES(1,'Vizyona'),(2,'Terzioğlu'),(4,'Fiesta')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'old@example.test','Mevcut','x','client',1)");
$admin=['id'=>1,'role'=>'admin'];$legacy=['workspacePolicy'=>['defaults'=>CustomerStandardPermissions::grants()]];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode($legacy)]);
$oldActor=['id'=>2,'role'=>'client','company_id'=>1];$oldAccess=(new CustomerPermissions($db,1))->access($oldActor);
$policy=CustomerInitialPolicy::template($db);
check($policy['defaults']===CustomerWorkspaceStandard::grants(),'Yeni mağaza formu aynı standart yetkileri kullanmalı.');
foreach([2,4] as $company)$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(CustomerWorkspaceStandard::profile(['workspacePolicy'=>$policy,'modules'=>['shipments'=>true,'deliveries'=>true]]))]);
$all=[];$directory=new AccountDirectoryWriter($db);$index=0;
foreach([2,4] as $company)foreach(array_keys(CustomerAccountRoles::LABELS) as $role)foreach(['store','directory'] as $route){
    $account=['name'=>'Örnek','email'=>'test-'.(++$index).'@example.test','username'=>'example.'.$index,'password'=>'Test-Omnigo-123456!','storeRole'=>$role];
    $id=$route==='store'?CustomerUserProvisioning::create($db,$admin,$company,$account):$directory->save($admin,null,[...$account,'role'=>'client','companyId'=>$company,'active'=>true])['id'];
    $actor=['id'=>$id,'role'=>'client','company_id'=>$company];$p=new CustomerPermissions($db,$company);$access=$p->access($actor);
    check(($access['interfacePreset']??null)===CustomerWorkspaceStandard::PRESET&&$access['overviewLayout']==='tabs','Ortak müşteri görünümü ve Genel Bakış sekmeleri etkin olmalı.');
    foreach(CustomerWorkspaceStandard::MENU as $page)check($p->allows($actor,$page),'Standart menü açık olmalı: '.$page);
    foreach(CustomerPermissionCatalog::pages() as $page=>$definition)foreach($definition['actions'] as $action){
        check($access['grants'][$page][$action]===CustomerWorkspaceStandard::grants()[$page][$action],'Rol veya ekleme yolu yetkiyi genişletemez: '.$page.'/'.$action);
    }
    foreach(['create','edit','delete','status','bulkStatus','replaceDocuments','deleteDocuments'] as $action)check(!$p->allows($actor,'shipments',$action),'Sevkiyat değişikliği kapalı olmalı.');
    foreach(['create','delete','update'] as $type)denied(fn()=>CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/commit','POST',['changes'=>[['type'=>$type]]]));
    denied(fn()=>CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/1','PUT',['field'=>'rowStatus','value'=>'shipped']));
    denied(fn()=>CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/1/documents/invoice','DELETE'));
    CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/1','PUT',['field'=>'invoiced','value'=>true]);
    CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/1','PUT',['field'=>'invoiced','value'=>false]);
    CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/1/documents/invoice','POST');
    CustomerPermissionRoutes::shipment($p,$actor,'/api/shipments/export','POST');
    check(!in_array(false,InventoryPermissionPolicy::capabilities($p,$actor),true),'Desteklenen stok işlemleri ve alış fiyatı açık olmalı.');
    $_GET=['company'=>(string)$company];$security=new Security($db,['origin'=>'http://localhost']);
    denied(fn()=>(new FileCenterRoutes($db,$security))->handle('/api/file-center/archive','POST',$actor));
    denied(fn()=>(new OrderDocumentRoutes($db,$security))->handle('/api/orders/1/documents/delivery','POST',$actor));
    CustomerPermissionRoutes::enforce($db,$security,$actor,'/api/support/tickets','POST');
    denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$actor,'/api/support/tickets/1/reply','POST'));
    denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$actor,'/api/support/tickets/1/status','POST'));
    $all[]=$actor;
}
check((new CustomerPermissions($db,1))->access($oldActor)===$oldAccess,'Vizyona erişimi değişmemeli.');
$created=CustomerOnboarding::create($db,$admin,'Yeni mağaza',date('Y-m-01'));
$profile=json_decode($db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$created])['payload'],true,64,JSON_THROW_ON_ERROR);
check(CustomerWorkspaceStandard::enabled($profile)&&$profile['overviewLayout']==='tabs','Yeni mağaza aynı varsayılanı almalı.');
$id=CustomerUserProvisioning::create($db,$admin,$created,['name'=>'Yeni','email'=>'new-store@example.test','password'=>'Test-Omnigo-123456!']);
check((new CustomerPermissions($db,$created))->access(['id'=>$id,'role'=>'client','company_id'=>$created])['grants']===CustomerWorkspaceStandard::grants(),'Yeni mağazanın ilk kullanıcısı standart yetkileri almalı.');
$actor=$all[0];$cipher=DeliveryCipher::withKey(str_repeat('p',32));$scope=['all','n11',date('Y-m')];
$draft=['orderedAt'=>date('Y-m-01').'T09:00','customerName'=>'Örnek','recipientName'=>'Alıcı','province'=>'Ankara','deliveryAddress'=>'Örnek adres','productName'=>'Ürün','quantity'=>'1','customerType'=>'Bireysel'];
$writer=new ShipmentWriter($db,$cipher);$writer->create($admin,2,$scope,['requestId'=>bin2hex(random_bytes(24)),'record'=>$draft]);
$row=$db->one('SELECT id,version FROM shipment_entries WHERE company_id=2 ORDER BY id DESC LIMIT 1');$repo=new ShipmentRepository($db,$cipher);
foreach([true,false] as $value){
    $repo->confirmInvoices($actor,2,(int)$row['id'],$scope,[(object)['id'=>(int)$row['id'],'version'=>(int)$row['version']]],$value);
    $row=$db->one('SELECT id,version,payload FROM shipment_entries WHERE id=?',[$row['id']]);
    check($cipher->open(2,'shipment:'.$row['id'],$row['payload'])['invoiceWorkDone']===$value,'Fatura onayı ve geri alma çalışmalı.');
}
$folder=sys_get_temp_dir().'/omnigo-standard-invoice-'.bin2hex(random_bytes(6));
try{
    $image=imagecreatetruecolor(12,12);ob_start();imagejpeg($image);$bytes=ob_get_clean();imagedestroy($image);
    $documents=new ShipmentDocuments($db,$cipher,$folder);
    $saved=$documents->save($actor,2,(int)$row['id'],'invoice',$scope,(int)$row['version'],bin2hex(random_bytes(24)),$bytes);
    check($documents->load($actor,2,(int)$row['id'],'invoice',$scope)['bytes']!=='','İlk fatura yüklemesi görüntülenebilmeli.');
    denied(fn()=>$documents->save($actor,2,(int)$row['id'],'invoice',$scope,(int)$saved['version'],bin2hex(random_bytes(24)),$bytes));
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}
if($fixture=getenv('OMNIGO_STANDARD_ACCESS_OUTPUT')){
    $states=[];foreach([...$all,$oldActor] as $user)$states[]=['company'=>$user['company_id'],'user'=>$user,
        'data'=>['workspaceAccess'=>(new CustomerPermissions($db,$user['company_id']))->access($user),'channels'=>[],'sales'=>[],'payments'=>[],'summary'=>[]]];
    foreach([1,2,4] as $company)$states[]=['company'=>$company,'user'=>$admin,
        'data'=>['workspaceAccess'=>(new CustomerPermissions($db,$company))->access($admin),'channels'=>[],'sales'=>[],'payments'=>[],'summary'=>[]]];
    file_put_contents($fixture,json_encode($states,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
$p=new CustomerPermissions($db,2);$cfg=$p->configuration();$grants=((array)$cfg['users'])[$actor['id']];$grants['shipments']['edit']=true;
$p->save($admin,['version'=>$cfg['version'],'userChanges'=>[$actor['id']=>$grants]]);
check((new CustomerPermissions($db,2))->allows($actor,'shipments','edit'),'Yetkili yönetici daha sonra kişisel izni değiştirebilmeli.');
$p=new CustomerPermissions($db,2);$cfg=$p->configuration();
foreach($cfg['newRoleDefaults'] as $role=>$default)check($default===CustomerWorkspaceStandard::grants(),'Mağazadaki rol önizlemesi standart yetkileri göstermeli.');
$p->save($admin,['version'=>$cfg['version'],'roleChanges'=>[$actor['id']=>CustomerAccountRoles::LOGISTICS],'roleResets'=>[$actor['id']],'userChanges'=>(object)[]]);
check((new CustomerPermissions($db,2))->access($actor)['grants']===CustomerWorkspaceStandard::grants(),'Rol değiştirme ve varsayılana dönme standart dışı yetki açmamalı.');
check((new CustomerPermissions($db,1))->access($oldActor)===$oldAccess,'Tüm işlemlerden sonra Vizyona aynı kalmalı.');
echo "Standart 11 menü, 13 yeni hesap, üç rol, iki ekleme yolu, sunucu işlem sınırları ve fatura onayı doğrulandı.\n";
