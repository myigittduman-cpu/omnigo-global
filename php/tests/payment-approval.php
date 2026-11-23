<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,PaymentApproval,PaymentDeletion,PaymentCalendar,NotificationSchema,PushSchema,HttpError,UpcomingPayoutReviewStore};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $call,int $code):void{try{$call();throw new RuntimeException('İşlem reddedilmedi');}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);PushSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek A');$other=$repo->createCompany('Örnek B');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',?)",[$company]);
$admin=['id'=>1,'name'=>'Omnigo','role'=>'admin'];$client=['id'=>2,'name'=>'Müşteri','role'=>'client'];$approval=new PaymentApproval($db);
$base=['channel'=>'Hepsiburada','expectedDate'=>'2026-11-03','expectedCents'=>100000,'status'=>'pending','source'=>'Elle giriş'];
$pending=$repo->save($admin,$company,'payments',$base,null);
denied(fn()=>$approval->approve($admin,$company,$pending['id'],['version'=>1]),409);
$paid=[...$base,'status'=>'paid','paidDate'=>'2026-11-03','actualCents'=>90000,'confirmation'=>'Banka teyidi','notes'=>'Tutar farkı korunacak'];
$saved=$repo->save($admin,$company,'payments',[...$paid,'paymentApproval'=>['at'=>'forged','userId'=>2]],null);
check(!PaymentApproval::isApproved($saved),'Genel kayıt isteği onay bilgisini üretememeli');
$otherPayment=$repo->save($admin,$other,'payments',$paid,null);
$before=(new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary'];
$notifications=(int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n'];
denied(fn()=>$approval->approve($client,$company,$saved['id'],['version'=>1]),403);
denied(fn()=>$approval->approve($admin,$other,$saved['id'],['version'=>1]),404);
denied(fn()=>$approval->approve($admin,$company,$saved['id'],['version'=>2]),409);
denied(fn()=>$approval->approve($admin,$company,$saved['id'],['version'=>'1']),400);
$done=$approval->approve($admin,$company,$saved['id'],['version'=>1]);
check(PaymentApproval::isApproved($done)&&$done['version']===2&&$done['paymentApproval']['userId']===1,'Onay kullanıcı ve sürümle kaydolmalı');
foreach($paid as $key=>$value)check($done[$key]===$value,'Finansal bilgi değişti: '.$key);
$after=(new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary'];
check($before['receivedCents']===0&&$before['sentCents']===90000&&$after['receivedCents']===90000&&$after['sentCents']===0,'Yalnız onay, aktarım bildirimini hesaba geçen toplamına taşımalı');
check(array_sum($before)===array_sum($after),'Onay aynı tutarı iki kez saymamalı');
check($approval->approve($admin,$company,$saved['id'],['version'=>1])===$done,'Tekrarlanan istek aynı sonucu vermeli');
check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='Ödeme onaylandı'")['n']===1,'Onay bir kez denetim kaydına girmeli');
check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===$notifications,'Onay yeni ödeme girişi gibi tekrar bildirilmemeli');
denied(fn()=>$repo->save($admin,$company,'payments',[...$paid,'version'=>2,'actualCents'=>1],$saved['id']),409);
denied(fn()=>(new PaymentDeletion($db))->delete($admin,$company,$saved['id'],['version'=>2]),409);
check(!PaymentApproval::isApproved($repo->records($other,'payments',$admin)[0]),'Diğer müşteri etkilenmemeli');
$withNote=$repo->save($admin,$company,'payments',[...$paid,'version'=>2,'correctApproved'=>true,'notes'=>'Ek açıklama'],$saved['id']);
check(PaymentApproval::isApproved($withNote)&&$withNote['version']===3,'Açıklama düzenlemesi banka onayını korumalı.');
$corrected=$repo->save($admin,$company,'payments',[...$paid,'version'=>3,'correctApproved'=>true,'actualCents'=>91000,'confirmation'=>''],$saved['id']);
check(!PaymentApproval::isApproved($corrected)&&$corrected['version']===4,'Tutar düzenlenince yeniden onay istenmeli.');
$reapproved=$approval->approve($admin,$company,$saved['id'],['version'=>4]);
check(PaymentApproval::isApproved($reapproved)&&$reapproved['actualCents']===91000,'Tutar ve tarihle ödeme yeniden onaylanabilmeli.');
$legacy=$repo->save($admin,$company,'payments',$paid,null);$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode([...$paid,'integration'=>'hepsiburada']),$legacy['id']]);
denied(fn()=>$approval->approve($admin,$company,$legacy['id'],['version'=>1]),409);

$periodCompany=$repo->createCompany('15 günlük ödeme örneği',['Hepsiburada','n11']);
UpcomingPayoutReviewStore::install($db);
$reviews=new UpcomingPayoutReviewStore($db);
$planned='2026-10-31';$bankDay='2026-11-01';
$reviews->observe($periodCompany,['today'=>'2026-10-17','items'=>[
    ['id'=>'hepsiburada:'.$planned,'date'=>$planned,'channel'=>'Hepsiburada','amountCents'=>100000,'source'=>'marketplace','verification'=>'estimated'],
    ['id'=>'n11:'.$planned,'date'=>$planned,'channel'=>'n11','amountCents'=>29000,'source'=>'marketplace','verification'=>'confirmed'],
]]);
$calendar=new PaymentCalendar($db);
$scope=fn(string $start):array=>$calendar->report($admin,$periodCompany,'2026-10-01','2026-10-31',includeUpcoming:true,periodStart:$start);
$beforeBank=$scope('2026-10-17');
check($beforeBank['periodView']&&$beforeBank['from']==='2026-10-17'&&$beforeBank['to']==='2026-10-31'
    &&count($beforeBank['upcoming']['periodItems'])===2,'Planlanan ödemeler tek 15 günlük döneme girmeli.');
$bankReceipt=$repo->save($admin,$periodCompany,'payments',[...$paid,'expectedDate'=>$planned,'paidDate'=>$bankDay],null);
$first=$scope('2026-10-17');
check($first['items']===[]&&$first['summary']['sentCents']===0
    &&array_column($first['upcoming']['periodItems'],'channel')===['n11'],
    'Bildirilmiş ödeme eski dönemin tahmininden ve listelerinden düşmeli; diğer pazaryeri korunmalı.');
check(in_array(['start'=>'2026-11-01','end'=>'2026-11-15'],$first['upcoming']['periods'],true),
    'Yalnız banka bildirimi olan dönem de açılabilmeli.');
$next=$scope('2026-11-01');
check($next['to']==='2026-11-15'&&$next['summary']['sentCents']===90000&&$next['summary']['receivedCents']===0
    &&$next['items'][0]['calendarDate']===$bankDay&&count($next['items'])===1,'Bildirim yalnız banka gününün ait olduğu döneme girmeli.');
$approval->approve($admin,$periodCompany,$bankReceipt['id'],['version'=>1]);
$next=$scope('2026-11-01');
check($next['summary']['receivedCents']===90000&&$next['summary']['sentCents']===0&&PaymentApproval::isApproved($next['items'][0]),
    'Ödeme, onayla aynı dönemde bir kez Hesaba geçen toplamına taşınmalı.');
$separate=$repo->save($admin,$periodCompany,'payments',[...$base,'expectedDate'=>$planned,'expectedCents'=>25000],null);
$older=$scope('2026-10-17');
check($older['summary']['estimatedCents']===25000&&in_array($separate['id'],array_column($older['upcoming']['periodItems'],'id'),true),
    'Aynı günde ayrıca bekleyen farklı bir manuel kayıt varsa onaylı ödeme onu gizlememeli.');
echo "Ödeme onayı: yetki, müşteri, sürüm, tek kayıt, korunan tutar ve ayrışmış toplamlar ve düzenleme/silme kilidi geçti.\n";
