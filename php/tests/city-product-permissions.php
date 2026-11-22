<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CustomerAccountRoles,CustomerPermissionCatalog,CustomerPermissions,CustomerRoleDefaults,
    CustomerSetupSelection,Database,HttpError,InventorySchema,NotificationSchema,ShipmentSchema};

function verify(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
ShipmentSchema::install($db);
NotificationSchema::migrate($db);
CustomerRoleDefaults::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'admin@x.test','Omnigo','x','admin',NULL),(2,'a@x.test','Yetkili','x','client',1),(3,'b@x.test','Diğer','x','client',2)");
$admin=['id'=>1,'role'=>'admin'];
$user=['id'=>2,'role'=>'client','company_id'=>1];
$legacy=['setupOptions'=>['live'=>['shipmentDeliveryDocuments','deliveries'],'manual'=>['deliveryDocumentUpload']],
    'workspacePolicy'=>['roles'=>[2=>CustomerAccountRoles::LOGISTICS],'users'=>[2=>[
        'shipmentDeliveryDocuments'=>['view'=>true,'documents'=>true,'upload'=>true]]]]];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode($legacy,JSON_THROW_ON_ERROR)]);
$policy=new CustomerPermissions($db,1);
$config=$policy->configuration();
verify($config['setupOptions']['live']===['deliveries','cityProducts']&&$policy->allows($admin,'cityProducts'),'Eski sevkiyat kapsamı aynı mağazadaki yönetici için yeni ekranı desteklemeli.');
verify(!$policy->allows($user,'cityProducts')&&!$policy->allows($admin,'shipmentDeliveryDocuments'),'Eski belge yetkisi yeni kişiler için erişim vermemeli.');
foreach($config['newRoleDefaults'] as $grants)verify(!$grants['cityProducts']['view'],'Yeni hesap şablonlarında adres ve telefon yetkisi başlangıçta kapalı olmalı.');
verify(count(array_diff(CustomerPermissionCatalog::pages()['cityProducts']['actions'],['view','export','viewCancelledRecords','viewReturnedRecords']))===0,'Yeni tablo yazma yetkisi içermemeli.');
$grants=$policy->access($user)['grants'];
$grants['cityProducts']['view']=true;
$config=$policy->save($admin,['version'=>$config['version'],'userChanges'=>[2=>$grants]]);
$policy=new CustomerPermissions($db,1);
verify($policy->allows($user,'cityProducts'),'Yalnızca yeni görüntüleme yetkisi açıkça verilen hesap görebilmeli.');
try{(new CustomerPermissions($db,2))->access($user);throw new RuntimeException('Mağaza sınırı aşıldı.');}
catch(HttpError $e){verify($e->getCode()===403,'Farklı mağazaya erişim reddedilmeli.');}
$selection=['live'=>['deliveries'],'manual'=>['deliveryDocumentUpload']];
$config=$policy->save($admin,['version'=>$config['version'],'userChanges'=>(object)[],'setupOptions'=>$selection]);
$policy=new CustomerPermissions($db,1);
verify(!$policy->allows($admin,'cityProducts')&&!$policy->allows($user,'cityProducts')&&CustomerSetupSelection::hasLive($db,1,'deliveries'),'Daha sonra kapatılan yeni ekran bağımsız kalmalı.');
$config=$policy->save($admin,['version'=>$config['version'],'userChanges'=>(object)[],'setupOptions'=>['live'=>['cityProducts'],'manual'=>[]]]);
$policy=new CustomerPermissions($db,1);
verify($policy->allows($user,'cityProducts')&&!$policy->allows($user,'deliveries')&&CustomerSetupSelection::operationalModules($config['setupOptions'])===['shipments'=>true,'deliveries'=>false],
    'Tek başına seçilen ekran sipariş kaynağını kullanmalı ve başka bir sayfayı açmamalı.');
verify((new CustomerPermissions($db,2))->allows(['id'=>3,'role'=>'client','company_id'=>2],'cityProducts')===false,'Aynı işlem başka mağazaya uygulanmamalı.');
$effectiveExport=static function(array $city,array $deliveries,string $deliveryAudience='shown',string $cityAudience='shown')use($db,$user):bool{
    $profile=['setupOptions'=>['live'=>['cityProducts','deliveries'],'manual'=>[]],'cityProductsConfigured'=>true,
        'workspacePolicy'=>['pages'=>['cityProducts'=>$cityAudience,'deliveries'=>$deliveryAudience],
            'users'=>[2=>['deliveries'=>$deliveries,'cityProducts'=>$city]]]];
    $db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($profile,JSON_THROW_ON_ERROR)]);
    return (new CustomerPermissions($db,1))->allows($user,'cityProducts','export');
};
$allow=['view'=>true,'export'=>true];
verify($effectiveExport(['view'=>true],$allow),'Eski profilde görüntülenen iki bölüm ve teslimat çıktısı izni devralınmalı.');
verify(!$effectiveExport(['view'=>true],$allow,'removed'),'Gizlenen teslimat bölümündeki eski izin devralınmamalı.');
$policy=new CustomerPermissions($db,1);
$config=$policy->configuration();
verify(!((array)$config['users'])[2]['cityProducts']['export'],'Yetki düzenleme formu kapalı bölümden izin devralmamalı.');
verify(!$effectiveExport(['view'=>true],['view'=>true,'export'=>false]),'Eski teslimat çıktısı reddi devralınmalı.');
verify(!$effectiveExport(['view'=>true,'export'=>false],$allow),'Açıkça kaldırılan çıktı iznini miras ezmemeli.');
verify(!$effectiveExport(['view'=>false,'export'=>true],$allow),'Görüntüleme olmadan dışa aktarım açılamamalı.');
verify($effectiveExport($allow,['view'=>false,'export'=>false],'removed'),'Yeni işlem izni bağımsız açılabilmeli.');
verify(!$effectiveExport($allow,$allow,'shown','removed')&&!(new CustomerPermissions($db,1))->allows($admin,'cityProducts','export'),
    'Kapalı ürünler ekranına mağaza veya yönetici için çıktı izni verilmemeli.');
echo "Şehir Dışı Ürünler için kurulum, roller, geriye uyumluluk ve mağaza sınırları doğrulandı.\n";

