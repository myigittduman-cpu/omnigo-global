<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AccountDirectorySchema,AccountDirectoryWriter,AccountSettingsSchema,CustomerAccountRoles,CustomerPermissions,
    CustomerRoleDefaults,CustomerStandardPermissions,CustomerUserProvisioning,Database,InventorySchema,InventoryModelPermissions,NotificationSchema,ShipmentSchema,SupportSchema};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
AccountSettingsSchema::install($db);AccountDirectorySchema::ensure($db);
CustomerRoleDefaults::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci'),(4,'Dördüncü'),(8,'Başka mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','test','admin',NULL)");
$overview=['overview','overviewSales','overviewTop','overviewWork'];
$original=[];
foreach([1,2,4,8] as $company){
    $profile=['workspacePolicy'=>['pages'=>array_fill_keys($overview,'all_roles'),'defaults'=>CustomerStandardPermissions::grants(),
        'users'=>[900=>['overviewSales'=>['view'=>false]]]]];
    if($company!==1)$profile['overviewLayout']='tabs';
    if(in_array($company,[2,4],true))$profile['newAccountOverviewTabs']=true;
    $original[$company]=$profile;
    $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode($profile,JSON_THROW_ON_ERROR)]);
}
$admin=['id'=>1,'role'=>'admin'];$directory=new AccountDirectoryWriter($db);$created=[];
foreach([1,2,4,8] as $company)foreach(array_keys(CustomerAccountRoles::LABELS) as $role)foreach(['store','directory'] as $entry){
    $key=$company.'-'.$role.'-'.$entry;
    $account=['name'=>'Örnek hesap','email'=>$key.'@example.test','username'=>'test.'.$company.'.'.count($created),
        'password'=>'Test-Omnigo-123456!','storeRole'=>$role];
    $id=$entry==='store'?CustomerUserProvisioning::create($db,$admin,$company,$account):
        $directory->save($admin,null,[...$account,'role'=>'client','companyId'=>$company,'active'=>true])['id'];
    $policy=new CustomerPermissions($db,$company);$access=$policy->access(['id'=>$id,'role'=>'client','company_id'=>$company]);
    if(in_array($company,[2,4],true)){
        check($access['overviewLayout']==='tabs','Yeni hesap mağazanın sekmeli görünümünü devralmalı.');
        foreach($overview as $page)check($access['pages'][$page]==='shown'&&$access['grants'][$page]['view'],'Üç bölüm ve Genel Bakış tüm yeni rollerde açık başlamalı.');
        if($role===CustomerAccountRoles::LOGISTICS)check($access['overviewSections']===['summary','sales','top','work'],'Lojistik rolünde bölümler Genel Bakış içinde bulunmalı.');
    }else{
        $expected=$role===CustomerAccountRoles::LOGISTICS?CustomerStandardPermissions::logistics():CustomerStandardPermissions::grants();
        $expected=InventoryModelPermissions::apply($expected,$policy->configuration()['inventory']['mode']);
        $stored=(array)$policy->configuration()['users'];
        check($stored[$id]===$expected,'Vizyona ve tercih vermeyen mağazalarda başlangıç yetkileri değişmemeli.');
        check($access['overviewLayout']===($company===1?'separate':'tabs'),'Mevcut mağaza görünümü korunmalı.');
    }
    foreach(['payments','returns'] as $page)foreach(['create','edit','delete','status'] as $action)
        check(!$policy->allows(['id'=>$id,'role'=>'client','company_id'=>$company],$page,$action),'Bölüm görünümü işlem yetkisi açamaz.');
    $profile=json_decode($db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company])['payload'],true,64,JSON_THROW_ON_ERROR);
    check($profile['workspacePolicy']['users'][900]===$original[$company]['workspacePolicy']['users'][900],'Mevcut kişisel ayarlar korunmalı.');
    $created[]=$id;
}
$cfg=(new CustomerPermissions($db,2))->configuration();
$profile=$original[2];$profile['workspacePolicy']['users']=[];
$moved=CustomerAccountRoles::initialize($profile,$cfg,901,CustomerAccountRoles::LOGISTICS,false);
check(!$moved['workspacePolicy']['users'][901]['overviewSales']['view'],'Mağaza taşıma, yeni hesap kuralıyla yetki genişletemez.');
$roles=new CustomerRoleDefaults($db);$role=$roles->roles()[CustomerAccountRoles::MANAGER];$grants=$role['grants'];
foreach($overview as $page)$grants[$page]['view']=false;
$roles->save($admin,['role'=>CustomerAccountRoles::MANAGER,'version'=>$role['version'],'grants'=>$grants]);
foreach([1,2] as $company){
    $id=CustomerUserProvisioning::create($db,$admin,$company,['name'=>'Güncel şablon','email'=>'new-template-'.$company.'@example.test','password'=>'Test-Omnigo-123456!']);
    $access=(new CustomerPermissions($db,$company))->access(['id'=>$id,'role'=>'client','company_id'=>$company]);
    foreach($overview as $page)check($access['grants'][$page]['view']===($company===2),'Mağazaya ait yeni hesap kuralı global şablondan bağımsız, diğer mağazalar aynı kalmalı.');
}
echo "26 yeni hesap: iki oluşturma yolu, üç rol, güncel şablonlar, mağaza ayrımı ve kişisel yetkiler doğrulandı.\n";
