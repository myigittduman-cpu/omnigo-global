<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerPermissions,CustomerPermissionRoutes,CustomerSensitiveVisibility,CustomerDashboardAccess,CustomerTableVisibility,Security,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $action):void{try{$action();}catch(HttpError $e){check($e->getCode()===403,'403 beklenir');return;}throw new RuntimeException('Erişim reddedilmedi');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','unused','admin',NULL),(2,'store@example.test','Mağaza','unused','client',1)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];
$grants=['visibility'=>['view'=>true,'viewCancellations'=>true,'viewReturns'=>true],'returns'=>['view'=>true],'shipments'=>['view'=>true,'status'=>true]];
$profile=['workspacePolicy'=>['pages'=>['visibility'=>'shown','returns'=>'shown'],'defaults'=>$grants,'users'=>['2'=>$grants]]];
foreach([1,2] as $company)$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode($profile)]);
$security=new Security($db,[]);$before=$db->all('SELECT * FROM company_profiles ORDER BY company_id');
$policy=new CustomerPermissions($db,1);
foreach([$client,[...$client,'id'=>3]] as $actor){
 check(CustomerSensitiveVisibility::flags($db,$actor,1)===CustomerTableVisibility::NONE,'Varsayılan ve özel izinler rol sınırını aşamaz');
 check(!$policy->allows($actor,'returns'),'İade sayfası müşteriye kapalı');
 check($policy->allows($actor,'shipments'),'Sevkiyat sayfası açık kalır');
}
check($policy->allows($client,'shipments','status'),'Mevcut sevkiyat işlem izni bu görünürlük değişikliğinde değişmez');
check(CustomerSensitiveVisibility::flags($db,$admin,1)===CustomerTableVisibility::ALL,'Omnigo iptal ve iadeyi görür');
$_GET=['company'=>'1','mode'=>'cancelled'];
denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/delivery/orders','GET'));
denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/returns','GET'));
CustomerPermissionRoutes::enforce($db,$security,$client,'/api/shipments','GET');
denied(fn()=>(new CustomerPermissions($db,2))->access($client));
$amounts=['grossCents'=>100000,'cancelCents'=>20000,'returnCents'=>10000,'netCents'=>70000,
    'turnoverCents'=>100000,'cancelledTurnoverCents'=>20000,'salesTurnoverCents'=>80000];
$source=['summary'=>$amounts,'channels'=>[],'sales'=>[],'salesToday'=>['channels'=>[]],'marketplaceSales'=>['channels'=>[]]];
$filtered=CustomerDashboardAccess::filter($db,$client,1,$source);
check(array_intersect_key($filtered['summary'],array_flip(['cancelCents','returnCents','cancelledTurnoverCents']))===[],'Müşteri API yanıtında iptal/iade tutarı yok');
check($filtered['summary']['turnoverCents']===100000&&!isset($filtered['summary']['salesTurnoverCents'],$filtered['summary']['netCents']),'Müşteri ham ciroyu görür; iptal sonrası ve net tutar açılmaz');
check(CustomerDashboardAccess::filter($db,$admin,1,$source)['summary']===$source['summary'],'Yönetici finansal kırılımı korunur');
check($db->all('SELECT * FROM company_profiles ORDER BY company_id')===$before,'Profil, stok modeli ve diğer mağaza ayarları yazılmaz');

$configuration=$policy->configuration();
$configuration['pages']['analytics']='all_roles';
$custom=$configuration['defaults'];
$custom['analytics']['view']=true;
$custom['analytics'][CustomerTableVisibility::TURNOVER]=true;
$configuration['userChanges']=['2'=>$custom];
unset($configuration['users']);
$policy->save($admin,$configuration);
$analytics=CustomerSensitiveVisibility::financial($db,$client,1,['totals'=>$amounts],true,'analytics');
check($analytics['totals']['salesTurnoverCents']===80000&&!isset($analytics['totals']['cancelCents'],$analytics['totals']['returnCents'],$analytics['totals']['netCents']),
    'Açık analiz yetkisi yalnız iptaller sonrası ciroyu verir');
$overview=CustomerSensitiveVisibility::financial($db,$client,1,['totals'=>$amounts],true,'overviewSales');
check(!isset($overview['totals']['salesTurnoverCents']),'İzin diğer tabloya taşınmaz');
denied(fn()=>CustomerSensitiveVisibility::financial($db,$client,2,['totals'=>$amounts],true,'analytics'));
echo "Omnigo rol sınırı, eski özel izin, doğrudan API reddi, sevkiyat istisnası ve değişmeyen hesaplar geçti.\n";
