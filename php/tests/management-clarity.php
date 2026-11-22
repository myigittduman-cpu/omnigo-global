<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerOnboarding,CustomerManagement,CustomerMarketplaceBatch,CustomerMarketplaces,StoreAccountCredentials,CustomerInitialPassword,CustomerUserProvisioning,CustomerPermissionCatalog,CustomerPermissions,CustomerPermissionRoutes,AccountSettingsSchema,HttpError,Security};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $code):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Beklenen hata oluşmadı: '.$code);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountSettingsSchema::install($db);\Omnigo\InventorySchema::install($db);
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','x','admin',NULL)");$admin=['id'=>1,'role'=>'admin'];
$a=CustomerOnboarding::create($db,$admin,'VİZYONA',null,null,['PTT AVM','n11']);$b=CustomerOnboarding::create($db,$admin,'Terzioğlu Shop',null,null,['PTT AVM']);
$store=new CustomerMarketplaces($db);$find=fn($id,$provider)=>array_values(array_filter($store->listing($id),fn($v)=>$v['provider']===$provider))[0];
$batch=new CustomerMarketplaceBatch($db);$items=fn()=>array_map(fn($p)=>['provider'=>$p,'version'=>$find($a,$p)['version']],['pttavm','n11']);
$protected=fn()=>[$db->all('SELECT * FROM jobs ORDER BY id'),$db->all("SELECT * FROM records WHERE kind<>'channels' ORDER BY id"),$store->listing($b)];$before=$protected();
$httpBody=(array)json_decode(json_encode(['action'=>'hide','items'=>$items()]));
$batch->apply($admin,$a,$httpBody);check($find($a,'pttavm')['hidden']&&$find($a,'n11')['hidden'],'HTTP JSON nesneleri ile toplu gizle');check($before===$protected(),'Aktarım, geçmiş ve diğer müşteri korunur');
$stale=$items();$stale[1]['version']--;fails(fn()=>$batch->apply($admin,$a,['action'=>'show','items'=>$stale]),409);check($find($a,'pttavm')['hidden']&&$find($a,'n11')['hidden'],'Çakışan toplu işlem atomik geri alınır');
fails(fn()=>$batch->apply(['id'=>1,'role'=>'client'],$a,['action'=>'show','items'=>$items()]),403);fails(fn()=>$batch->apply($admin,$a,['action'=>'delete','items'=>$items()]),400);
$batch->apply($admin,$a,['action'=>'show','items'=>$items()]);check(!$find($a,'pttavm')['hidden']&&!$find($a,'n11')['hidden'],'Toplu göster');
$first=StoreAccountCredentials::suggestion($db,$a);$second=StoreAccountCredentials::suggestion($db,$a);
check($first['sequence']===1&&$second['sequence']===1&&$first['password']!==$second['password'],'Öneriler rastgele ve hesaba kaydedilmeden üretilir');
fails(fn()=>CustomerUserProvisioning::create($db,$admin,$a,StoreAccountCredentials::account(['name'=>'Eski Form','email'=>'old-form@example.invalid','temporaryPassword'=>true,'passwordSequence'=>1,'password'=>'Vizyona.1*'])),409);
$account=fn($n,$sequence)=>StoreAccountCredentials::account(['name'=>'Örnek Kullanıcı '.$n,'email'=>'test'.$n.'@example.invalid','password'=>'Vizyona.'.$sequence.'*','passwordSequence'=>$sequence]);
$fourDigits='0123';
$id=CustomerUserProvisioning::create($db,$admin,$a,[...$account(1,1),'password'=>$fourDigits]);$u=$db->one('SELECT * FROM users WHERE id=?',[$id]);
check(Security::verifyPassword($fourDigits,$u['password'])&&!CustomerInitialPassword::required($db,$u),'Dört rakamlı şifreyle ilk girişte çalışma alanı açılır');
foreach(['', '   ', str_repeat('ş',65),"ab\0cd"] as $invalid)fails(fn()=>StoreAccountCredentials::validate($invalid),400);
check(StoreAccountCredentials::validate(' 0123 ')===' 0123 '&&StoreAccountCredentials::validate(str_repeat('ş',64))===str_repeat('ş',64),'Girilen karakterler korunur, UTF-8 sınırı doğrulanır');
check(!str_contains(json_encode($db->all('SELECT * FROM user_preferences')),'Vizyona.1*'),'Düz metin şifre saklanmaz');
fails(fn()=>CustomerUserProvisioning::create($db,$admin,$a,$account(2,1)),409);check(StoreAccountCredentials::suggestion($db,$a)['sequence']===2,'Çakışma sayacı tüketmez');
check(StoreAccountCredentials::suggestion($db,$b)['sequence']===1,'Diğer müşteri sayacı değişmez');
$profile=(new CustomerManagement($db))->profile($a,true);(new CustomerManagement($db))->saveProfile($admin,$a,['name'=>'VİZYONA','version'=>$profile['version']]);check(StoreAccountCredentials::suggestion($db,$a)['sequence']===2,'Firma düzenlemesi sayacı korur');
$db->run("INSERT INTO users(email,name,password,role,company_id) VALUES('legacy@example.invalid','Mevcut','old-hash','client',?)",[$a]);$legacy=$db->one("SELECT * FROM users WHERE email='legacy@example.invalid'");check(!CustomerInitialPassword::required($db,$legacy),'Mevcut şifre zorlanmaz');
fails(fn()=>CustomerInitialPassword::complete($db,$u,['currentPassword'=>'yanlış','password'=>'Example!Secure123']),401);
// An older installation may still have an issued temporary credential.
CustomerInitialPassword::mark($db,$id,$u['password']);check(CustomerInitialPassword::required($db,$u),'Eski geçici şifre halen değiştirilir');
CustomerInitialPassword::complete($db,$u,['currentPassword'=>$fourDigits,'password'=>'Example!Secure123']);$changed=$db->one('SELECT * FROM users WHERE id=?',[$id]);check(!CustomerInitialPassword::required($db,$changed)&&Security::verifyPassword('Example!Secure123',$changed['password']),'Eski ilk şifre değişimi tamamlanır');
fails(fn()=>StoreAccountCredentials::set($db,$admin,$b,$id,['password'=>'Terzioglu.1*']),404);check($changed['password']===$db->one('SELECT password FROM users WHERE id=?',[$id])['password'],'Diğer müşteride şifre değiştirilmez');
for($n=2;$n<=22;$n++)CustomerUserProvisioning::create($db,$admin,$a,$account($n,$n));check(count((new CustomerManagement($db))->users($a))===23,'Kullanıcı limiti yok');check(StoreAccountCredentials::suggestion($db,$a)['sequence']===23,'Sayaç başarılı hesaplarla ilerler');
$old=CustomerPermissionCatalog::defaults();unset($old['shipments']['selectRows'],$old['shipments']['bulkStatus']);$old['shipments']['status']=true;$normalized=CustomerPermissionCatalog::validateGrants($old);check($normalized['shipments']['selectRows']&&$normalized['shipments']['bulkStatus'],'Eski toplu yetkiler korunur');
$p=new CustomerPermissions($db,$a);$c=$p->configuration();$c['pages']['shipments']='shown';$g=$c['defaults'];$g['shipments']['view']=true;$g['shipments']['status']=false;$g['shipments']['selectRows']=true;$g['shipments']['bulkStatus']=true;
$p->save($admin,[...$c,'userChanges'=>[(string)$id=>$g]]);$p=new CustomerPermissions($db,$a);fails(fn()=>CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/status','POST'),403);fails(fn()=>CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/status-preview','POST',['ids'=>[1,2]]),403);
fails(fn()=>CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/1','PUT',['field'=>'status']),403);fails(fn()=>CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/status-preview','POST',['ids'=>'invalid']),400);
$c=$p->configuration();$g['shipments']['bulkStatus']=false;$g['shipments']['status']=true;$p->save($admin,[...$c,'userChanges'=>[(string)$id=>$g]]);$p=new CustomerPermissions($db,$a);fails(fn()=>CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/status','POST'),403);CustomerPermissionRoutes::shipment($p,$u,'/api/shipments/1','PUT',['field'=>'status']);
echo "Toplu işlemler, atomik kayıt, müşteri izolasyonu, şifre sırası, ilk giriş, sınırsız hesap ve ayrı toplu/tekil yetki testleri geçti.\n";
