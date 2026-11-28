<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,FinanceSchema,UpcomingPayoutReviewStore,UpcomingPayoutAdjustment,PaymentCalendar,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
FinanceSchema::install($db);UpcomingPayoutReviewStore::install($db);
$company=(new Repository($db))->createCompany('Yerel hakediş testi',['Koçtaş']);
$db->run("INSERT INTO users(id,email,password,name,role) VALUES(1,'test@example.test','hash','Yönetici','admin')");
$actor=['id'=>1,'role'=>'admin'];$today=(new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
$date=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$row=['id'=>'panel:Koçtaş:'.$date,'channel'=>'Koçtaş','date'=>$date,'amountCents'=>8603038,'source'=>'marketplace_panel','verification'=>'estimated'];
(new UpcomingPayoutReviewStore($db))->observe($company,['today'=>$today,'items'=>[$row],'settled'=>[]]);
$calendar=new PaymentCalendar($db);$read=fn()=>$calendar->report($actor,$company,$today,$today,'all',true)['upcoming']['periodItems'][0];
$store=new UpcomingPayoutAdjustment($db);
foreach(['2001-01-01','2035-12-31'] as $target){
 $current=$read();$store->save($actor,$company,['id'=>$row['id'],'revision'=>$current['adjustmentRevision'],'action'=>'edit','date'=>$target,'amountCents'=>8603038]);
 check($read()['date']===$target,'Geçmiş ve gelecek ödeme tarihleri kaydedilmeli.');
}
$current=$read();
foreach([['date'=>'2026-02-30','amountCents'=>8603038],['date'=>$today,'amountCents'=>0]] as $invalid){
 try{$store->save($actor,$company,['id'=>$row['id'],'revision'=>$current['adjustmentRevision'],'action'=>'edit',...$invalid]);throw new RuntimeException('Geçersiz kayıt kabul edildi.');}
 catch(HttpError $e){check($e->getCode()===400,'Geçersiz tarih ve sıfır tutar reddedilmeli.');}
}
try{$store->save(['role'=>'client','id'=>2],$company,[]);throw new RuntimeException('Müşteri işlemi kabul edildi.');}catch(HttpError $e){check($e->getCode()===403,'Müşteri salt görüntüleme sınırı korunmalı.');}
echo "Hakediş düzeltmesi: geçmiş/gelecek tarih, geçersiz tarih, tutar ve yönetici sınırı doğrulandı.\n";
