<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,PaymentApproval,PaymentDeletion,PaymentCalendar,NotificationSchema,PushSchema,HttpError,CustomerServiceNotifications};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $call,int $code):void{try{$call();throw new RuntimeException('İşlem reddedilmedi');}catch(HttpError $e){check($e->getCode()===$code,'Yanlış hata kodu');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);PushSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek A');$other=$repo->createCompany('Örnek B');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',?)",[$company]);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client'];
$base=['channel'=>'Hepsiburada','expectedDate'=>'2026-11-03','expectedCents'=>100000,'status'=>'pending','source'=>'Elle giriş'];
$saved=$repo->save($admin,$company,'payments',$base,null);$otherPayment=$repo->save($admin,$other,'payments',$base,null);$delete=new PaymentDeletion($db);
$before=count($db->all('SELECT * FROM audit'));denied(fn()=>$delete->delete($client,$company,$saved['id'],['version'=>1]),403);denied(fn()=>$delete->delete($admin,$other,$saved['id'],['version'=>1]),404);denied(fn()=>$delete->delete($admin,$company,$saved['id'],['version'=>2]),409);check(count($db->all('SELECT * FROM audit'))===$before,'Reddedilen işlem iz bırakmamalı');
$result=$delete->delete($admin,$company,$saved['id'],['version'=>1]);check($result['deleted']&&!$repo->records($company,'payments',$admin),'Hakediş aktif tablodan kalkmalı');check(count($repo->records($other,'payments',$admin))===1,'Başka mağaza etkilenmemeli');
$audit=$db->one("SELECT * FROM audit WHERE action='Hakediş silindi'");check((int)$audit['user_id']===1&&(int)$audit['record_id']===$saved['id']&&json_decode($audit['before_value'],true)['expectedCents']===100000,'Tutar, kullanıcı ve önceki kayıt denetim için kalmalı');check((new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary']['estimatedCents']===0,'Silinen hakediş toplamdan çıkmalı');
$events=array_map(fn($r)=>json_decode($r['payload'],true),$db->all('SELECT payload FROM order_notifications'));
$deleted=array_filter($events,fn($e)=>($e['operation']??'')==='deleted');
$audiences=array_column($deleted,'audience');sort($audiences);
check($audiences===['admin','client'],'Her alıcı kapsamına bir silme bildirimi üretilmeli');
check(str_contains(CustomerServiceNotifications::paymentMessage(['operation'=>'deleted']),'kaldırdı'),'Silme doğru ifadeyle bildirilmeli');
denied(fn()=>$delete->delete($admin,$company,$saved['id'],['version'=>1]),404);
$paid=$repo->save($admin,$company,'payments',[...$base,'status'=>'paid','paidDate'=>'2026-11-03','actualCents'=>90000,'confirmation'=>'Banka teyidi'],null);$delete->delete($admin,$company,$paid['id'],['version'=>1]);check((new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary']['receivedCents']===0,'Ödenmiş kayıt da silindiğinde hesaba geçen toplam doğru olmalı');
$pending=$repo->save($admin,$company,'payments',$base,null);
$received=$repo->save($admin,$company,'payments',[...$base,'status'=>'paid','paidDate'=>'2026-11-03','actualCents'=>90000],null);
$received=(new PaymentApproval($db))->approve([...$admin,'name'=>'Omnigo'],$company,$received['id'],['version'=>$received['version']]);
$selection=fn(...$items)=>['payments'=>array_map(fn($row)=>(object)array_intersect_key($row,array_flip(['id','version'])),$items)];
$auditBefore=count($db->all('SELECT * FROM audit'));
denied(fn()=>$delete->deleteSelection($client,$company,$selection($pending)),403);
denied(fn()=>$delete->deleteSelection($admin,$company,$selection($pending,$otherPayment)),404);
denied(fn()=>$delete->deleteSelection($admin,$company,$selection($pending,[...$received,'version'=>1])),409);
denied(fn()=>$delete->deleteSelection($admin,$company,$selection($pending,$pending)),400);
check(count($db->all('SELECT * FROM audit'))===$auditBefore&&count($repo->records($company,'payments',$admin))===2,'Bir kayıt hatalıysa seçimin tamamı geri alınmalı.');
check($delete->deleteSelection($admin,$company,$selection($pending,$received))===['deleted'=>2],'Beklenen ve onaylı hakedişler birlikte silinebilmeli.');
check(!(new PaymentCalendar($db))->report($client,$company,'2026-11-01','2026-11-30')['items']&&count($repo->records($other,'payments',$admin))===1,'İki ekran aynı silmeyi görmeli, diğer mağaza korunmalı.');
echo "Hakediş silme: yönetici yetkisi, mağaza/sürüm sınırı, önceki kayıt, tek bildirim ve beklenen/ödenmiş toplamları geçti.\n";
