<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,CustomerMarketplaces,PaymentCalendar,DeliverySchema,ShipmentSchema,RecentOrderSchema,NotificationSchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);NotificationSchema::migrate($db);
$repo=new Repository($db);$legacy=$repo->createCompany('Eski katalog');$company=$repo->createCompany('Manuel hakediş',['PTT AVM']);
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','x','admin',NULL)");
$admin=['id'=>1,'role'=>'admin'];$client=['role'=>'client'];$month=gmdate('Y-m');$day=gmdate('Y-m-d');
$insert=function(int $id,string $kind,array $data)use($db){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$id,$kind,json_encode($data),gmdate('c')]);};
$insert($legacy,'sales',['channel'=>'Trendyol','month'=>$month,'grossCents'=>99999,'cancelCents'=>0,'returnCents'=>0,'orders'=>1]);
$payment=['channel'=>'PTT AVM','expectedDate'=>$day,'paidDate'=>'','status'=>'pending','expectedCents'=>12345,'actualCents'=>0,'verification'=>'confirmed','verificationSource'=>'Yönetici','source'=>'manual'];
$insert($company,'payments',$payment);
$service=new CustomerMarketplaces($db);$baseline=[];
check(!in_array('trendyol',array_column((new \Omnigo\ReturnRequestList($db))->listing($admin,$legacy,[])['sources'],'key'),true),'Seçilmemiş Trendyol bağlantı uyarısı üretmez');
foreach(['admin'=>$admin,'client'=>$client] as $role=>$user){
 check($repo->dashboard([...$user,'company_id'=>$legacy],$legacy,$month)['summary']['grossCents']===0,'Seçilmemiş eski Trendyol toplamı değiştirmez');
 check(!in_array('Trendyol',array_column($repo->dashboard([...$user,'company_id'=>$legacy],$legacy,$month)['channels'],'name'),true),'Seçilmemiş eski Trendyol görünmez');
 $baseline[$role]=(new PaymentCalendar($db))->report([...$user,'company_id'=>$company],$company,$day,$day);
 check(count($baseline[$role]['items'])===1,'Manuel hakediş başlangıçta görünür');
}
$item=array_values(array_filter($service->listing($company),fn($r)=>$r['provider']==='pttavm'))[0];
$service->change($admin,$company,'pttavm',['action'=>'delete','version'=>$item['version']]);
foreach(['admin'=>$admin,'client'=>$client] as $role=>$user){
 $calendar=(new PaymentCalendar($db))->report([...$user,'company_id'=>$company],$company,$day,$day);
 check($calendar['items']===$baseline[$role]['items']&&$calendar['summary']===$baseline[$role]['summary'],'Mağaza silinince manuel hakediş ve takvim toplamı korunur');
}
echo "Geçmiş kayıtlar: seçilmemiş Trendyol ve silinen mağazanın manuel hakedişi iki panelde doğrulandı.\n";
