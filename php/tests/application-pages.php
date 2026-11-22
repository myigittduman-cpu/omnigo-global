<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountSettingsSchema,ApplicationPageStore,ApplicationPageUrl,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $action,int $code):void{try{$action();}catch(HttpError $e){check($e->getCode()===$code,'Yanlış HTTP kodu');return;}throw new RuntimeException('İşlem reddedilmeliydi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountSettingsSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Bir'),(2,'Örnek İki')");
$store=new ApplicationPageStore($db);$initial=$store->management();check(!$initial['canRollback'],'İlk kurulumda önceki yayın yok');
$page=fn($slug,$role='both',$scope='all',$ids=[])=>['slug'=>$slug,'title'=>'Örnek sayfa','content'=>'İçerik','role'=>$role,'scope'=>$scope,'companyIds'=>$ids];
$draft=$store->saveDraft(['version'=>1,'items'=>[$page('ortak'),$page('yonetici','admin'),$page('musteri','client','selected',[1])]]);
check($store->visible(['role'=>'client','company_id'=>1],1)['items']===[],'Taslak müşteriye görünmez');
$first=$store->publish(['version'=>$draft['version']]);check($first['canRollback'],'Boş önceki yayına geri dönülebilir');
check(count($store->visible(['role'=>'client','company_id'=>1],1)['items'])===2,'Müşteri yalnız uygun rol ve mağaza sayfalarını görür');
check(count($store->visible(['role'=>'client','company_id'=>2],2)['items'])===1,'Diğer mağaza seçili sayfayı göremez');
check(count($store->visible(['role'=>'admin'],1)['items'])===2,'Yönetici müşteri özel sayfasını göremez');
rejected(fn()=>$store->visible(['role'=>'client','company_id'=>1],2),403);
rejected(fn()=>$store->saveDraft(['version'=>$draft['version'],'items'=>[]]),409);
rejected(fn()=>$store->publish(['version'=>$draft['version']]),409);
$empty=$store->rollback(['version'=>$first['published']['version']]);check($empty['published']['items']===[],'İlk yayından boş duruma geri dönülür');
$restored=$store->rollback(['version'=>$empty['published']['version']]);check(count($restored['published']['items'])===3,'Geri alma da geri alınabilir');
rejected(fn()=>$store->rollback(['version'=>$first['published']['version']]),409);
$clear=$store->saveDraft(['version'=>$restored['draft']['version'],'items'=>[]]);$published=$store->publish(['version'=>$clear['version']]);
check((new ApplicationPageStore($db))->management()['published']['items']===[],'Son sayfayı silme kalıcıdır');
foreach([['items'=>['wrong'=>$page('yanlis')]],['items'=>[$page('ayni'),$page('ayni')]],['items'=>[$page('firma','both','selected',[999])]],['items'=>[$page('firma','both','selected',['1'])]],['items'=>[$page('firma','both','selected',[])]],['items'=>array_fill(0,25,$page('sinir'))]] as $bad)rejected(fn()=>$store->saveDraft(['version'=>$published['draft']['version'],...$bad]),400);
foreach(['//other.example/path','/\\other.example','javascript:alert(1)','https://user:pass@example.test','https://','/path'."\n".'next'] as $url)rejected(fn()=>ApplicationPageUrl::validate($url),400);
foreach(['/rehber?section=1','https://example.test/help'] as $url)check(ApplicationPageUrl::validate($url)===$url,'Güvenli bağlantı korunur');
$long=$page('uzun');$long['content']=str_repeat('🛒',8000);$large=$store->saveDraft(['version'=>$published['draft']['version'],'items'=>[$long]]);check($large['items'][0]['content']===$long['content'],'255 karakterden uzun UTF-8 içerik korunur');
echo "Özel sayfalar: taslak/yayın, boş geri alma, sürüm çakışması, rol/mağaza ayrımı, doğrulama ve uzun UTF-8 içerik geçti.\n";
