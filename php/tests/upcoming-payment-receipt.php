<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,FinanceSchema,NotificationSchema,PushSchema,UpcomingPayoutReviewStore,UpcomingPaymentReceipt,PaymentCalendar,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(int $code,callable $run):void{try{$run();throw new RuntimeException('Geçersiz işlem kabul edildi.');}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
FinanceSchema::install($db);NotificationSchema::migrate($db);PushSchema::install($db);UpcomingPayoutReviewStore::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Bir',['Koçtaş']);$other=$repo->createCompany('İki',['Koçtaş']);
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$actor=['id'=>1,'name'=>'Yönetici','role'=>'admin'];$today=(new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');$day=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$row=['id'=>'panel:Koçtaş:'.$day,'channel'=>'Koçtaş','date'=>$day,'amountCents'=>8603038,'source'=>'marketplace_panel','verification'=>'estimated'];
$reviews=new UpcomingPayoutReviewStore($db);$reviews->observe($company,['today'=>$today,'items'=>[$row],'settled'=>[]]);$reviews->observe($other,['today'=>$today,'items'=>[$row],'settled'=>[]]);
$calendar=new PaymentCalendar($db);$read=fn($id)=>$calendar->report($actor,$id,$today,$today,'all',true)['upcoming']['periodItems'][0];
$service=new UpcomingPaymentReceipt($db);$input=['id'=>$row['id'],'revision'=>$read($company)['receiptRevision'],'actualCents'=>8603038,'paidDate'=>$today];
denied(403,fn()=>$service->approve(['role'=>'client','id'=>2,'name'=>'Müşteri'],$company,$input));
denied(409,fn()=>$service->approve($actor,$company,[...$input,'revision'=>'stale']));
denied(400,fn()=>$service->approve($actor,$company,[...$input,'paidDate'=>'2026-02-30']));
$saved=$service->approve($actor,$company,$input);
check($saved['status']==='paid'&&!empty($saved['paymentApproval']['at'])&&$saved['actualCents']===8603038,'Satır hesaba geçti olarak onaylanmalı.');
$after=$calendar->report($actor,$company,$today,$today,'all',true);
check($after['summary']['receivedCents']===8603038&&$after['upcoming']['paidThisMonthCents']===8603038,'Onay aynı ödeme döneminin hesabına geçen toplamını güncellemeli.');
check($after['upcoming']['remainingCents']===0&&count($after['upcoming']['periodItems'])===1&&!empty($after['upcoming']['periodItems'][0]['paymentApproval']['at']),'Ödenen satır yeşil tik için görünür kalmalı, beklenen toplama eklenmemeli.');
$period=$calendar->report($actor,$company,$today,$today,'all',true,periodStart:$day);
check(count($period['upcoming']['periodItems'])===1&&!empty($period['upcoming']['periodItems'][0]['paymentApproval']['at']),'Dönem filtresi onaylı satırı gizlememeli.');
$counts=fn()=>[$db->one("SELECT COUNT(*) n FROM records WHERE kind='payments'")['n'],$db->one('SELECT COUNT(*) n FROM audit')['n'],$db->one('SELECT COUNT(*) n FROM order_notifications')['n']];$before=$counts();
check($service->approve($actor,$company,$input)['id']===$saved['id']&&$before===$counts(),'Tekrar onayı ikinci ödeme, toplam veya bildirim üretmemeli.');
denied(409,fn()=>$service->approve($actor,$company,[...$input,'actualCents'=>1]));
denied(409,fn()=>$service->approve($actor,$other,$input));
$otherInput=[...$input,'revision'=>$read($other)['receiptRevision'],'paidDate'=>'2035-12-31'];
$db->pdo->exec("CREATE TRIGGER fail_receipt_notice BEFORE INSERT ON order_notifications BEGIN SELECT RAISE(ABORT,'local-test'); END");$before=$counts();
try{$service->approve($actor,$other,$otherInput);throw new RuntimeException('Bildirim hatası bekleniyordu.');}catch(PDOException){}
check($before===$counts(),'Hata ödeme, onay ve bildirimin tamamını geri almalı.');$db->pdo->exec('DROP TRIGGER fail_receipt_notice');
$future=$service->approve($actor,$other,$otherInput);check($future['paidDate']==='2035-12-31','Yönetici geçerli gelecek tarihini de seçebilmeli.');
$manualCompany=$repo->createCompany('Manuel',['Koçtaş']);
$manual=$repo->save($actor,$manualCompany,'payments',['channel'=>'Koçtaş','expectedDate'=>$day,'expectedCents'=>10000,'status'=>'pending','source'=>'Manuel hakediş tahmini'],null);
$manualInput=['id'=>$manual['id'],'version'=>$manual['version'],'actualCents'=>9500,'paidDate'=>'2001-01-01'];
$done=$service->approve($actor,$manualCompany,$manualInput);
check($done['id']===$manual['id']&&$done['version']===$manual['version']+2,'Manuel kaydın kendisi onaylanmalı; ikinci kayıt oluşmamalı.');
check(count($repo->records($manualCompany,'payments',$actor))===1,'Manuel kayıt sayısı korunmalı.');
echo "Satırdan ödeme onayı: toplam, yeşil tik verisi, dönem, tarih serbestliği, idempotans, bildirim, geri alma ve müşteri ayrımı doğrulandı.\n";
