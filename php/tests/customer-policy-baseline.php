<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,CustomerOnboarding,CustomerInitialPolicy,CustomerPermissions,CustomerUserProvisioning,CustomerSensitiveVisibility,CustomerPermissionRoutes,OrderList,Security,HttpError};
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $run):void{try{$run();}catch(HttpError $error){verify($error->getCode()===403,'Yetki hatası 403 olmalı.');return;}throw new RuntimeException('Yetkisiz erişim kabul edildi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL)");$admin=['id'=>1,'role'=>'admin'];$clients=[];
$date=(new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul')))->format('Y-m-01');
foreach(['horoz','both','store','marketplace'] as $mode){
 $initial=CustomerInitialPolicy::template();$initial['inventory']['mode']=$mode;
 $company=CustomerOnboarding::create($db,$admin,'Model '.$mode,$date,null,null,null,$initial,['mode'=>'nationwide','regionalCities'=>[]]);
 $policy=new CustomerPermissions($db,$company);$config=$policy->configuration();
 verify($config['pages']['orders']==='all_roles'&&$config['pages']['deliveries']==='all_roles'&&$config['pages']['tasks']==='hidden','Standart mağazada istenen görüntüleme sayfaları tüm mağaza rollerine açıktır.');
 $accounts=[];foreach([1,2] as $number){$id=CustomerUserProvisioning::create($db,$admin,$company,['email'=>$mode.$number.'@example.test','name'=>'Müşteri '.$number,'password'=>'Policy!Example123']);$accounts[]=['id'=>$id,'role'=>'client','company_id'=>$company];}
 $policy=new CustomerPermissions($db,$company);$one=$policy->access($accounts[0]);$two=$policy->access($accounts[1]);
 verify($one===$two,'Yeni kullanıcılar aynı mağaza/model yetkileriyle başlar.');
 verify($one['grants']['orders']['view']&&$one['grants']['deliveries']['view'],'Standart Siparişler ve Teslimatlar görüntüleme izinleri açıktır.');
 verify(!$one['grants']['visibility']['viewCancellations']&&!$one['grants']['visibility']['viewReturns'],'İptal ve iade varsayılan olarak kapalı.');
 verify($one['grants']['inventory']['editStock']===($mode!=='marketplace')&&$one['grants']['inventory']['viewMarketplaceStocks']===($mode!=='store'),'İstenen stok işlemleri desteklenen model sınırlarını korur.');
 verify($one['grants']['inventory']['recordStoreSale']===($mode!=='marketplace'),'Mağaza Yetkilisi yalnız canlı stok modelinde mağaza satışı kaydedemez.');
 $logisticsId=CustomerUserProvisioning::create($db,$admin,$company,['email'=>$mode.'.logistics@example.test','name'=>'Muhasebe ve Lojistik','password'=>'Policy!Example123','storeRole'=>'accounting_logistics']);
 $logistics=(new CustomerPermissions($db,$company))->access(['id'=>$logisticsId,'role'=>'client','company_id'=>$company])['grants']['inventory'];
 verify($logistics['editStock']===($mode!=='marketplace')&&$logistics['recordStoreSale']===($mode!=='marketplace'),'Stok modeli lojistik rolünün manuel işlemlerini sınırlar.');
 verify($logistics['viewMarketplaceStocks']===($mode!=='store'),'Standart mağazada rollerin stok ayrıntısı aynı model sınırına bağlıdır.');
 verify($one['grants']['shipments']['view']&&!$one['grants']['shipments']['status'],'Sevkiyat görünür; durum değiştirme varsayılanı kapalı.');
 $clients[$company]=$accounts;
}
$companies=array_keys($clients);$company=$companies[0];$client=$clients[$company][0];$other=$clients[$companies[1]][0];
$payload=['grossCents'=>10000,'cancelCents'=>1000,'returnCents'=>500,'nested'=>['operationalCancelCents'=>1000,'cancelledProducts'=>[['title'=>'Gizli iptal']],'returnRequests'=>[1]],'products'=>[['grossCents'=>10000,'cancelCents'=>1000,'returnCents'=>500]],'payments'=>[['notes'=>'İade tutarı kontrol edildi.','details'=>[['section'=>'deductions','label'=>'İade kesintisi','amountCents'=>500],['section'=>'deductions','label'=>'Komisyon','amountCents'=>100]]]]];$original=$payload;
$safe=CustomerSensitiveVisibility::financial($db,$client,$company,$payload);
verify(!isset($safe['cancelCents'],$safe['returnCents'],$safe['nested']['cancelledProducts'],$safe['nested']['returnRequests'],$safe['nested']['operationalCancelCents']),'Hassas alanlar iç içe API yanıtlarında gizlenir.');
verify(!array_key_exists('netCents',$safe)&&!array_key_exists('panelSalesCents',$safe)
    &&!array_key_exists('netCents',$safe['products'][0])&&$safe['grossCents']===10000,
    'Hassas tutarlar gizlenir ve orijinal satış bedeli korunur.');
verify($payload===$original,'Kaynak veri mutasyona uğramaz.');
verify($safe['payments'][0]['notes']===''&&array_column($safe['payments'][0]['details'],'label')===['Komisyon'],'Hakediş ayrıntılarındaki gizli iade kalemleri ve notları gösterilmez.');
denied(fn()=>CustomerSensitiveVisibility::flags($db,$other,$company));
verify(CustomerSensitiveVisibility::redact($payload,['cancellations'=>true,'returns'=>true])===$payload,'Tam izinli ve yönetici yanıtı aynı alanları korur.');
$policy=new CustomerPermissions($db,$company);$config=$policy->configuration();$config['pages']['orders']='shown';$config['pages']['returns']='shown';$config['pages']['deliveries']='shown';$config['defaults']['orders']['view']=true;
$grants=$config['defaults'];foreach(['orders','returns','deliveries'] as $page)foreach(['viewCancelledRecords','viewReturnedRecords','viewAfterCancellationTurnover','viewCancelledStatus'] as $action)if(array_key_exists($action,$grants[$page]))$grants[$page][$action]=false;
$config['userChanges']=[(string)$client['id']=>$grants];unset($config['users']);$policy->save($admin,$config);
$security=new Security($db,['origin'=>'http://localhost']);$_GET=['company'=>(string)$company];
denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/returns','GET'));
$_GET['mode']='cancelled';denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/delivery/orders','GET'));
CustomerPermissionRoutes::enforce($db,$security,$client,'/api/shipments','GET');
foreach(['OPEN','CANCELLED','RETURNED'] as $state)$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_n11',json_encode(['externalId'=>$state,'createdAt'=>$date.'T12:00:00+03:00','state'=>$state,'orders'=>1,'grossCents'=>10000,'cancelCents'=>$state==='CANCELLED'?10000:0,'returnCents'=>$state==='RETURNED'?10000:0,'products'=>[]]),gmdate('c')]);
$query=['provider'=>'n11','from'=>$date,'to'=>$date];$hidden=(new OrderList($db))->listing($client,$company,$query);verify($hidden['total']===1&&$hidden['items'][0]['externalId']==='OPEN','Gizli sipariş durumları sayfalama toplamına girmez.');
$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_n11',json_encode(['externalId'=>'PARTIAL','createdAt'=>$date.'T12:00:00+03:00','state'=>'OPEN','orders'=>1,'grossCents'=>'1000','cancelCents'=>'900','returnCents'=>'0','products'=>[]]),gmdate('c')]);
verify((new OrderList($db))->listing($client,$company,$query)['total']===2,'Metin olarak gelen tutarlar sayısal kıyaslanır; kısmi sipariş tamamen gizlenmez.');
$policy=new CustomerPermissions($db,$company);$config=$policy->configuration();$grants=((array)$config['users'])[(string)$client['id']];$grants['visibility']['viewCancellations']=true;$grants['visibility']['viewReturns']=true;$config['userChanges']=[(string)$client['id']=>$grants];unset($config['users']);$policy->save($admin,$config);
$shown=(new OrderList($db))->listing($client,$company,$query);verify($shown['total']===2&&!array_key_exists('cancelCents',$shown['items'][0])&&!array_key_exists('returnCents',$shown['items'][0]),'Eski açık özel izin iptal/iade rol sınırını aşamaz.');
denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/returns','GET'));denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$client,'/api/delivery/orders','GET'));
verify(!CustomerSensitiveVisibility::flags($db,$clients[$company][1],$company)['returns'],'Açıkça değiştirilen kullanıcı izni diğerine geçmez.');
verify((new OrderList($db))->listing($admin,$company,$query)['total']===4,'Yöneticinin tam görünümü korunur.');
echo "Dört stok modeli, bağımsız başlangıç şablonu, eşit yeni kullanıcı izinleri, iptal/iade API koruması, sevkiyat istisnası, sayfalama ve tenant sınırları geçti.\n";
