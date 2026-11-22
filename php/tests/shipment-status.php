<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentRepository,DeliveryCipher,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen hata kodu.');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);\Omnigo\NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('s',32));$repository=new ShipmentRepository($db,$cipher);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client'];
$db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,invoiced,shipped,version,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[1,1,'Ankara','Hepsiburada','2026-06',$cipher->seal(1,'shipment:1',['customerName'=>'Örnek Müşteri']),1,1,9,gmdate('c'),gmdate('c')]);
// The fixture repository uses an in-memory database; no live records are changed.
$all=['all','Hepsiburada','2026-06'];$version=9;
foreach(['cancelled','delivered','shipped','invoiced','completed',''] as $status){
 $saved=$repository->toggle($admin,1,1,$all,['field'=>'rowStatus','value'=>$status,'version'=>$version++]);
 $expected=$status==='delivered'?'completed':$status;
 check($saved['rowStatus']===$expected&&$saved['invoiced'],'Renk seçimi fatura işaretini korumalı.');
 check($saved['cancelled']===($status==='cancelled')&&$saved['shipped']===($status==='shipped'),'Durumlar tekli olmalı.');
 $filter=$status===''?'none':$status;
 if($status==='invoiced'){check($repository->listing(1,$all,0,'',['rowStatus'=>$filter])['total']===0,'Yüklenmiş fatura olmadan eski renk işareti sarı gösterilmez.');continue;}
 check($repository->listing(1,$all,0,'',['rowStatus'=>$filter])['total']===1,'Seçili durum yenilendiğinde korunmalı ve filtrelenmeli.');
 $export=$repository->export(1,$all,'',['rowStatus'=>$filter])['items'];
 check($expected==='cancelled'?$export===[]:$export[0]['rowStatus']===$expected,'Seçili durum korunmalı, iptal belgeye girmemeli.');
}
fails(fn()=>$repository->toggle($admin,1,1,$all,['field'=>'rowStatus','value'=>'red','version'=>$version]),400);
fails(fn()=>$repository->toggle($admin,1,1,$all,['field'=>'rowStatus','value'=>true,'version'=>$version]),400);
fails(fn()=>$repository->toggle($client,1,1,$all,['field'=>'rowStatus','value'=>'delivered','version'=>$version]),403);
fails(fn()=>$repository->toggle($admin,2,1,$all,['field'=>'rowStatus','value'=>'delivered','version'=>$version]),404);
fails(fn()=>$repository->toggle($admin,1,1,['all','n11','2026-06'],['field'=>'rowStatus','value'=>'delivered','version'=>$version]),404);
fails(fn()=>$repository->toggle($admin,1,1,$all,['field'=>'rowStatus','value'=>'delivered','version'=>$version-1]),409);
check($db->one("SELECT COUNT(*) AS total FROM audit WHERE action='shipment.rowStatus'")['total']==6,'Yalnızca başarılı seçimler denetim izine yazılmalı.');
check(\Omnigo\ShipmentStatus::resolve(['row_status'=>null,'cancelled'=>1,'shipped'=>1])==='cancelled','Eski iptal işareti öncelikli olmalı.');
check(\Omnigo\ShipmentStatus::resolve(['row_status'=>null,'cancelled'=>0,'shipped'=>1])==='shipped','Eski sevk işareti korunmalı.');
check(\Omnigo\ShipmentStatus::resolve(['row_status'=>'','cancelled'=>1,'shipped'=>1])==='','Açıkça kaldırılmış renk yeniden oluşmamalı.');
echo "Renkli satırlar: tekli seçim/kaldırma, fatura bağımsızlığı, kalıcılık, filtre/dışa aktarma, yetki ve çakışma koruması geçti.\n";
