<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CustomerNotificationPolicy,Database,MobilePushMessage,NotificationAudience,NotificationSchema,OperationalNotifications,PaymentApprovalReminder,PushSchema};

function check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);
PushSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci mağaza'),(2,'İkinci mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',1),(3,'second@example.test','İkinci yönetici','x','admin',NULL)");
$users=array_column($db->all('SELECT id,role,company_id FROM users'),null,'id');

$day='2026-11-26';
$base=['channel'=>'PTT AVM','expectedDate'=>$day,'status'=>'pending','expectedCents'=>6481497];
$seed=function(int $company,array $overrides=[])use($db,$base): int{
    $db->run('INSERT INTO records(company_id,kind,payload,updated,version) VALUES(?,?,?,?,1)',
        [$company,'payments',json_encode([...$base,...$overrides],JSON_THROW_ON_ERROR),gmdate('c')]);
    return $db->insertId();
};
$pending=$seed(1);
$sent=$seed(2,['status'=>'sent']);
$unapproved=$seed(2,['status'=>'paid','actualCents'=>6481497,'paidDate'=>$day]);
$seed(1,['status'=>'paid','paymentApproval'=>['at'=>'2026-11-26T12:59:00Z']]);
$seed(1,['integration'=>'archive']);
$seed(1,['archivedAt'=>'2026-11-26T10:00:00Z']);
$seed(1,['expectedDate'=>'2026-11-25']);
$seed(1,['expectedDate'=>'2026-11-27']);
$seed(1,['expectedDate'=>'']);
$seed(1,['paymentStateUnknown'=>true]);
$seed(1,['channel'=>'Teknosa']);
$reminder=new PaymentApprovalReminder($db);
check($reminder->run(new DateTimeImmutable('2026-11-26T12:59:59Z'))===0,'16.00 öncesi bildirim oluşmamalı.');
check($reminder->run(new DateTimeImmutable('2026-11-26T13:00:00Z'))===3,'Yalnız bugün onaylanmamış üç hakediş bildirilir.');
check($reminder->run(new DateTimeImmutable('2026-11-26T19:30:00Z'))===0,'Aynı ödeme için tekrar bildirim oluşmamalı.');
$rows=$db->all('SELECT * FROM order_notifications ORDER BY id');
foreach([$pending,$sent,$unapproved] as $index=>$record){
    $event=$rows[$index];
    $payload=json_decode($event['payload'],true,32,JSON_THROW_ON_ERROR);
    check($payload['targetId']===$record&&$payload['payoutDate']===$day,'Bildirim doğru kayıt ve tarihle eşleşmeli.');
    check(!array_intersect(['expectedCents','actualCents'],array_keys($payload)),'Bildirim tutar taşımamalı.');
    foreach([1,3] as $id)check(NotificationAudience::permits($users[$id],$event)
        &&CustomerNotificationPolicy::recipient($db,$users[$id],$event),'Her yetkili yönetici bildirimi alabilmeli.');
    check(!NotificationAudience::permits($users[2],$event)
        &&!CustomerNotificationPolicy::recipient($db,$users[2],$event),'Mağaza kullanıcısı yönetici hatırlatmasını almamalı.');
    $detail=(new OperationalNotifications($db))->detail((int)$event['company_id'],(int)$event['id']);
    check($detail['message']===PaymentApprovalReminder::MESSAGE&&$detail['createdAt']==='2026-11-26T13:00:00+00:00','Zil detayı saat ve mesajı göstermeli.');
    $push=MobilePushMessage::create([],$event,'test');
    check(str_contains($push['url'],'view=payments')&&str_contains($push['body'],'Hakedişiniz hâlâ yatmadı mı?'),'Cihaz bildirimi hakediş ekranına gitmeli.');
}
echo "Hakediş hatırlatması: yerel saat, tarih, banka onayı, tekilleştirme, yöneticiler ve bildirim yönlendirmesi geçti.\n";
