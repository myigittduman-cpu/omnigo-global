<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentBulkStatus,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata kodu.');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);Omnigo\DeliverySchema::install($db);$cipher=Omnigo\DeliveryCipher::withKey(str_repeat('b',32));
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Başka firma')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
for($i=1;$i<=12;$i++)$db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(?,1,?,?,?,?,?,?)',[$i,'Ankara','Hepsiburada','2026-06',$cipher->seal(1,'shipment:'.$i,['customerName'=>'Test']),gmdate('c'),gmdate('c')]);
$bulk=new ShipmentBulkStatus($db,$cipher);$user=['id'=>1,'role'=>'admin'];$scope=['all','Hepsiburada','2026-06'];$items=array_map(fn($id)=>['id'=>$id,'version'=>1],range(1,10));
fails(fn()=>$bulk->save(['id'=>2,'role'=>'client'],1,$scope,['items'=>$items,'value'=>'invoiced']),403);
fails(fn()=>$bulk->save($user,2,$scope,['items'=>$items,'value'=>'invoiced']),404);
fails(fn()=>$bulk->save($user,1,['Eskişehir','Hepsiburada','2026-06'],['items'=>$items,'value'=>'invoiced']),404);
fails(fn()=>$bulk->save($user,1,$scope,['items'=>[...$items,['id'=>11,'version'=>1]],'value'=>'invoiced']),400);
fails(fn()=>$bulk->save($user,1,$scope,['items'=>[$items[0],$items[0]],'value'=>'invoiced']),400);
$stale=$items;$stale[9]['version']=2;
fails(fn()=>$bulk->save($user,1,$scope,['items'=>$stale,'value'=>'invoiced']),409);
check((int)$db->one('SELECT SUM(version) AS n FROM shipment_entries')['n']===12,'Bir çakışmada hiçbir satır değişmemeli.');
check((int)$db->one('SELECT COUNT(*) AS n FROM audit')['n']===0,'Başarısız toplu işlem iz bırakmamalı.');
$body=(array)json_decode(json_encode(['items'=>$items,'value'=>'invoiced']),false,32,JSON_THROW_ON_ERROR);
$result=$bulk->save($user,1,$scope,$body);check($result['updated']===10,'Açık sayfadaki 10 satır güncellenmeli.');
check((int)$db->one("SELECT COUNT(*) AS n FROM shipment_entries WHERE row_status='invoiced' AND invoiced=1")['n']===10,'Kırmızı durum ve fatura bilgisi birlikte kaydedilmeli.');
check((int)$db->one('SELECT SUM(version) AS n FROM shipment_entries WHERE id>10')['n']===2,'Diğer sayfadaki kayıtlar değişmemeli.');
$next=array_map(fn($row)=>['id'=>$row['id'],'version'=>$row['version']],$result['items']);
$result=$bulk->save($user,1,$scope,['items'=>$next,'value'=>'completed']);
check((int)$db->one("SELECT COUNT(*) AS n FROM shipment_entries WHERE row_status='completed' AND invoiced=1")['n']===10,'Turuncu durumda fatura bilgisi korunmalı.');
$next=array_map(fn($row)=>['id'=>$row['id'],'version'=>$row['version']],$result['items']);$bulk->save($user,1,$scope,['items'=>$next,'value'=>'']);
check((int)$db->one("SELECT COUNT(*) AS n FROM shipment_entries WHERE row_status='' AND invoiced=1")['n']===10,'Durum temizlenmeli, geçmiş fatura bilgisi korunmalı.');
echo "Toplu durum: 10 satır sınırı, firma/şehir yetkisi, sürüm çakışmasında atomik koruma, kırmızı/turuncu renkler ve diğer sayfaların korunması geçti.\n";
