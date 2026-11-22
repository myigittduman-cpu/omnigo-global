<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{AccountDirectorySchema,AccountDirectoryWriter,AccountSettingsSchema,CustomerAccountRoles,CustomerPermissionCatalog,CustomerPermissions,CustomerRoleDefaults,
    CustomerStandardPermissions,CustomerTableVisibility,CustomerUserProvisioning,Database,HttpError,InventorySchema,ManagementRoutes,
    MarketplaceGate,NotificationSchema,Security,ShipmentSchema,SupportSchema};

function verify(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

function denied(callable $action,int $status): void
{
    try{$action();}catch(HttpError $error){verify($error->getCode()===$status,$error->getMessage());return;}
    throw new RuntimeException('Yetki veya sürüm denetimi uygulanmadı.');
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
AccountSettingsSchema::install($db);AccountDirectorySchema::ensure($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci Mağaza'),(2,'İkinci Mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','unused','admin',NULL),(2,'legacy@example.test','Mağaza','unused','client',1)");
$admin=['id'=>1,'role'=>'admin'];
$manager=CustomerAccountRoles::MANAGER;$logistics=CustomerAccountRoles::LOGISTICS;$storeAdmin=CustomerAccountRoles::STORE_ADMIN;
$store=new CustomerRoleDefaults($db);
$baseline=$store->roles();
verify(count($baseline)===3&&$baseline[$storeAdmin]['grants']===$baseline[$manager]['grants'],'Yönetici mağaza yetkilisiyle aynı varsayılanlardan başlar.');
verify($baseline[$manager]['grants']===CustomerStandardPermissions::grants()&&$baseline[$logistics]['grants']===CustomerStandardPermissions::logistics(),'Mağaza ve lojistik başlangıç yetkileri korunmalı.');
foreach($baseline as $preset)foreach(CustomerPermissionCatalog::pages() as $page=>$definition){
    foreach(CustomerTableVisibility::actions($page) as $permission){
        $statusOnly=$permission===CustomerTableVisibility::CANCELLED_STATUS
            &&in_array($page,['deliveries','shipments','cityProducts','shipmentInvoices'],true)
            &&$preset['grants'][$page]['view'];
        verify($preset['grants'][$page][$permission]===$statusOnly,$page.' '.$permission.' başlangıç izni uyuşmuyor.');
    }
}
denied(fn()=>$store->save($admin,['role'=>$manager,'version'=>0,'grants'=>$baseline[$manager]['grants']]),503);
CustomerRoleDefaults::install($db);
CustomerRoleDefaults::install($db);

function user(int $id,int $company=1):array{return ['id'=>$id,'role'=>'client','company_id'=>$company];}
function grants(Database $db,int $id,int $company=1):array{return (new CustomerPermissions($db,$company))->access(user($id,$company))['grants'];}
$account=['name'=>'Mağaza Yetkilisi','password'=>'Policy!Example123','storeRole'=>$manager];
$old=CustomerUserProvisioning::create($db,$admin,1,[...$account,'email'=>'old@example.test']);
$outside=CustomerUserProvisioning::create($db,$admin,2,[...$account,'email'=>'outside@example.test']);
$oldGrants=grants($db,$old);$legacy=grants($db,2);$outsideGrants=grants($db,$outside,2);
$profiles=$db->all('SELECT company_id,payload,version FROM company_profiles ORDER BY company_id');
$next=$baseline[$manager]['grants'];$next['analytics']['export']=false;
$next['analytics'][CustomerTableVisibility::TURNOVER]=true;
$next['shipments'][CustomerTableVisibility::CANCELLATIONS]=true;
$saved=$store->save($admin,['role'=>$manager,'version'=>0,'grants'=>$next]);
verify($saved['roles'][$manager]['version']===1&&$saved['roles'][$manager]['grants']['analytics']['export']===false,'Rol varsayılanı güncel sürümüyle kaydedilmeli.');
verify($saved['roles'][$storeAdmin]===$baseline[$storeAdmin]&&$saved['roles'][$logistics]===$baseline[$logistics],'Yalnız düzenlenen rol değişmeli.');
verify($profiles===$db->all('SELECT company_id,payload,version FROM company_profiles ORDER BY company_id'),'Global kayıt mağaza profillerine yazmamalı.');
verify($oldGrants===grants($db,$old)&&$legacy===grants($db,2)&&$outsideGrants===grants($db,$outside,2),'Açık sıfırlama olmadan mevcut hesapların izinleri korunmalı.');
denied(fn()=>$store->save($admin,['role'=>$manager,'version'=>0,'grants'=>$next]),409);
denied(fn()=>$store->save(user($old),['role'=>$manager,'version'=>1,'grants'=>$next]),403);

$new=CustomerUserProvisioning::create($db,$admin,1,[...$account,'email'=>'new@example.test']);
verify(grants($db,$new)['analytics']['export']===false,'Mağaza kurulumunda güncel şablon alınmalı.');
$access=(new CustomerPermissions($db,1))->access(user($new));
verify(CustomerTableVisibility::fromAccess($access,'analytics')===['cancellations'=>false,'returns'=>false,'afterCancellationTurnover'=>true],
    'İptal sonrası ciro izni yalnız seçilen satış tablosunda açılmalı.');
verify(CustomerTableVisibility::fromAccess($access,'shipments')===['cancellations'=>true,'returns'=>false,'afterCancellationTurnover'=>false],
    'İptal ve iade izinleri birbirinden bağımsız olmalı.');
verify(CustomerTableVisibility::fromAccess($access,'overview')===CustomerTableVisibility::NONE,'Başka tablo izni devralmamalı.');
$directory=new AccountDirectoryWriter($db);
$newId=$directory->save($admin,null,[...$account,'email'=>'directory@example.test','username'=>'directory.user','role'=>'client','companyId'=>2,'active'=>true])['id'];
verify(grants($db,$newId,2)['analytics']['export']===false,'Hesap dizininde aynı güncel şablon alınmalı.');
$clientAdmin=CustomerUserProvisioning::create($db,$admin,1,[...$account,'storeRole'=>$storeAdmin,'email'=>'store-admin@example.test']);
$clientAccess=(new CustomerPermissions($db,1))->access(user($clientAdmin));
verify($db->one('SELECT role FROM users WHERE id=?',[$clientAdmin])['role']==='client'&&$clientAccess['storeRole']===$storeAdmin,'Mağaza yöneticisi Omnigo yöneticisi olmamalı.');
verify($clientAccess['grants']===$oldGrants,'Yönetici başlangıç yetkileri Mağaza Yetkilisi ile aynı kalmalı.');
denied(fn()=>(new CustomerPermissions($db,2))->access(user($clientAdmin)),403);
denied(fn()=>(new ManagementRoutes($db,[],new Security($db,[])))->handle('/api/management/role-defaults','GET',user($clientAdmin)),403);
verify(MarketplaceGate::protects('/api/management/role-defaults','PUT')&&MarketplaceGate::globalScope('/api/management/role-defaults'),'Düzenleme global yönetim iznine bağlı olmalı.');

$policy=new CustomerPermissions($db,1);
$version=$policy->configuration()['version'];
denied(fn()=>$policy->save($admin,['version'=>$version,'roleResets'=>[$outside],'userChanges'=>(object)[]]),403);
$policy->save($admin,['version'=>$version,'roleResets'=>[$old],'userChanges'=>(object)[]]);
verify(grants($db,$old)['analytics']['export']===false&&grants($db,2)===$legacy&&grants($db,$outside,2)===$outsideGrants,'Yalnız seçili kullanıcı en yeni rol şablonuna dönmeli.');
$policy=new CustomerPermissions($db,1);
$policy->save($admin,['version'=>$policy->configuration()['version'],'roleResets'=>[$clientAdmin],'roleChanges'=>[$clientAdmin=>$manager],'userChanges'=>(object)[]]);
verify((new CustomerPermissions($db,1))->access(user($clientAdmin))['storeRole']===$manager&&grants($db,$clientAdmin)['analytics']['export']===false,'Rol değişimi ve sıfırlama yeni role göre aynı kayıtta uygulanmalı.');

$invalid=$next;$invalid['visibility']=['view'=>true,'viewCancellations'=>true,'viewReturns'=>true];
denied(fn()=>$store->save($admin,['role'=>$storeAdmin,'version'=>0,'grants'=>$invalid]),400);
$policy=new CustomerPermissions($db,1);
$direct=grants($db,$new);
$direct['visibility']=$invalid['visibility'];
$direct['returns']=['view'=>true];
$config=$policy->configuration();
$config['pages']['returns']='all_roles';
$policy->save($admin,['version'=>$config['version'],'pages'=>$config['pages'],'userChanges'=>[$new=>$direct]]);
$policy=new CustomerPermissions($db,1);
verify(!$policy->allows(user($new),'returns'),'Eski iptal/iade yetkileri tabloyu açmamalı.');
$direct['returns'][CustomerTableVisibility::RETURNS]=true;
$policy->save($admin,['version'=>$policy->configuration()['version'],'userChanges'=>[$new=>$direct]]);
$policy=new CustomerPermissions($db,1);
verify($policy->allows(user($new),'returns')&&$policy->allows(user($new),'returns',CustomerTableVisibility::RETURNS)
    &&!$policy->allows(user($new),'returns',CustomerTableVisibility::CANCELLATIONS),'Seçilen kullanıcı yalnız izin verilen talep türünü görmeli.');
verify(!$policy->allows(user($clientAdmin),'returns'),'Aynı mağazanın başka kullanıcısının izni değişmemeli.');
denied(fn()=>$policy->save(user($new),['version'=>$policy->configuration()['version'],'userChanges'=>[$new=>$direct]]),403);
echo "customer role defaults OK\n";
