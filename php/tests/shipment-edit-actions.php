<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,ShipmentSchema,NotificationSchema,SupportSchema,ShipmentWriter,ShipmentDeletion,ShipmentRoutes,ShipmentRepository,DeliveryCipher,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function fails(callable $fn,int $code):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$code,'HTTP '.$code.' bekleniyordu: '.$error->getMessage());return;}throw new RuntimeException('Hata bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Yönetici','x','admin',NULL),(2,'c@test.invalid','Müşteri','x','client',1)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1,'name'=>'Örnek Çalışan','operatorName'=>'Yanlış İsim'];
$cipher=DeliveryCipher::withKey(str_repeat('s',32));$writer=new ShipmentWriter($db,$cipher);$deletion=new ShipmentDeletion($db,$cipher);$scope=['all','n11','2026-10'];
$draft=['orderedAt'=>'2026-10-11T09:00','customerName'=>'Örnek Müşteri','province'=>'Ankara','deliveryAddress'=>'Örnek adres','productName'=>'Örnek ürün','quantity'=>'1','customerType'=>'Bireysel'];
$routes=new ShipmentRoutes($db,new Security($db,[]),$cipher);$_GET=['company'=>1,'city'=>'all','provider'=>'n11','month'=>'2026-10'];
fails(fn()=>$routes->handle('/api/admin/shipments','POST',$client),403);
$_GET['company']=2;
fails(fn()=>$routes->handle('/api/shipments','POST',$client),403);
$_GET['company']=1;
fails(fn()=>$writer->create($client,2,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)]),403);
foreach([$admin,$client] as $user)fails(fn()=>$writer->create($user,1,['all','all','2026-10'],['record'=>$draft,'requestId'=>str_repeat('a',48)]),400);
check((int)$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===0,'Yetkisiz veya pazaryeri seçilmemiş istekler kayıt oluşturmamalı.');
$first=$writer->create($client,1,$scope,['record'=>$draft,'requestId'=>str_repeat('a',48)])['id'];
check((int)$db->one('SELECT manual_user_id FROM shipment_entries WHERE id=?',[$first])['manual_user_id']===$client['id'],'Müşteri kendi firmasında kayıt ekleyebilmeli ve işlemi yapan kişi korunmalı.');
$second=$writer->create($admin,1,$scope,['record'=>$draft,'requestId'=>str_repeat('b',48)])['id'];
$deletion->save($client,1,$first,$scope,['version'=>1]);
check($deletion->latest($client,1,$scope)['id']===$first,'Gerçek silme geri alınabilir.');
fails(fn()=>$writer->update($client,1,$second,$scope,['record'=>$draft,'version'=>99]),409);
check($deletion->latest($client,1,$scope)['id']===$first,'Başarısız kaydetme geri alma seçeneğini kaldırmamalı.');
$writer->update($admin,1,$second,$scope,['record'=>[...$draft,'district'=>'Çankaya'],'version'=>1]);
check($deletion->latest($client,1,$scope)['id']===$first,'Başka kişinin düzenlemesi geri alma seçeneğini etkilememeli.');
$writer->update($client,1,$second,$scope,['record'=>[...$draft,'district'=>'Keçiören'],'version'=>2]);
check((new ShipmentDeletion($db,$cipher))->latest($client,1,$scope)===null,'Kaydetten ve yeniden yüklemeden sonra eski silmeyi geri al görünmemeli.');
check($db->one('SELECT removed_at FROM shipment_entries WHERE id=?',[$first])['removed_at']!==null,'Düzenleme silinen kaydı geri getirmemeli.');
check((new ShipmentRepository($db,$cipher))->listing(1,$scope)['items'][0]['district']==='Keçiören','Müşterinin mevcut kayıt düzenlemesi korunmalı.');
$deletion->save($client,1,$second,$scope,['version'=>3]);
check($deletion->latest($client,1,$scope)['id']===$second,'Yeni bir gerçek silme tekrar geri alınabilir.');
$deletion->save($client,1,$second,$scope,['version'=>4],true);
check($deletion->latest($client,1,$scope)===null,'Yeni silme geri alınınca eski silme yeniden gösterilmemeli.');
echo "Satır işlemleri: yönetici ve müşterinin kendi firmasına eklemesi, firma/rol sınırları, pazaryeri seçimi, düzenleme ve dahili silme geçmişi testleri geçti.\n";
