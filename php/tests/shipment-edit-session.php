<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,NotificationSchema,SupportSchema,ShipmentWriter,ShipmentEditSession,ShipmentRepository,DeliveryCipher,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function fails(callable $fn,int $code):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$code,'HTTP '.$code.': '.$error->getMessage());return;}throw new RuntimeException('Hata bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Yönetici','x','admin',NULL),(2,'c@test.invalid','Müşteri','x','client',1)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Müşteri','operatorName'=>'Başka Kişi'];
$cipher=DeliveryCipher::withKey(str_repeat('s',32));$writer=new ShipmentWriter($db,$cipher);$session=new ShipmentEditSession($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek Müşteri','province'=>'Ankara','deliveryAddress'=>'Örnek adres','productName'=>'Örnek ürün','quantity'=>'1','customerType'=>'Bireysel'];
$first=$writer->create($admin,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)])['id'];
$second=$writer->create($admin,1,$scope,['record'=>$draft,'requestId'=>str_repeat('b',48)])['id'];
$before=$db->all('SELECT * FROM shipment_entries');
$bad=['requestId'=>str_repeat('c',48),'changes'=>[['type'=>'update','id'=>$first,'version'=>1,'record'=>[...$draft,'district'=>'Çankaya']],['type'=>'delete','id'=>$second,'version'=>999]]];
fails(fn()=>$session->save($admin,1,$scope,$bad),409);
check($db->all('SELECT * FROM shipment_entries')===$before,'Bir çakışma tüm işlemleri geri almalı.');
$valid=$bad;$valid['changes'][1]['version']=1;
$result=$session->save($admin,1,$scope,$valid);check($result['saved']===2&&!$result['replayed'],'İki işlem tek kayıtta tamamlanmalı.');
check($db->one('SELECT version FROM shipment_entries WHERE id=?',[$first])['version']===2,'Düzenleme bir kez uygulanmalı.');
check($db->one('SELECT removed_at FROM shipment_entries WHERE id=?',[$second])['removed_at']!==null,'Silme kayıtta uygulanmalı.');
check($session->save($admin,1,$scope,$valid)['replayed'],'Tekrarlanan istek yeniden uygulamamalı.');
$valid['changes'][0]['record']['district']='Keçiören';fails(fn()=>$session->save($admin,1,$scope,$valid),409);
$create=['requestId'=>str_repeat('d',48),'changes'=>[['type'=>'create','requestId'=>str_repeat('e',48),'record'=>$draft]]];
$clientCreate=['requestId'=>str_repeat('f',48),'changes'=>[['type'=>'create','requestId'=>str_repeat('1',48),'record'=>$draft]]];
$clientResult=$session->save($client,1,$scope,$clientCreate);check($clientResult['saved']===1,'Müşteri kendi firmasında yeni kayıt oluşturmalı.');
check($session->save($client,1,$scope,$clientCreate)['replayed'],'Müşteri yeniden denemesinde mükerrer satır oluşmamalı.');
$clientEvent=$db->one("SELECT id FROM audit WHERE user_id=2 AND action='shipment.client_change' ORDER BY id DESC LIMIT 1");
$event=(new \Omnigo\ShipmentChangeNotifications($db,$cipher))->detail(1,(int)$clientEvent['id']);
check($event['actorName']===$client['name']&&$event['operation']==='create'&&count($event['changes'])>0,'Yeni satır kişi ve alan ayrıntılarıyla yöneticiye bildirilmeli.');
fails(fn()=>$session->save($client,1,['all','all','2026-10'],[...$clientCreate,'requestId'=>str_repeat('2',48)]),400);
fails(fn()=>$session->save($admin,1,['all','all','2026-10'],$create),400);
fails(fn()=>$session->save($client,2,$scope,$create),403);
$result=$session->save($admin,1,$scope,$create);check($result['saved']===1,'Yönetici belirli pazaryerinde yeni kayıt oluşturmalı.');
check($session->save($admin,1,$scope,$create)['replayed'],'Yeni kayıt tekrar istekte mükerrer olmamalı.');
echo "Taslak kaydetme: atomiklik, sürüm çakışması, tekrar isteği, firma ve rol sınırları geçti.\n";
