<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,PaymentCalendar,PaymentApproval,NotificationSchema,PushSchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);PushSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek A');$other=$repo->createCompany('Örnek B');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',?)",[$company]);
$admin=['id'=>1,'name'=>'Omnigo','role'=>'admin'];$client=['id'=>2,'name'=>'Müşteri','role'=>'client','company_id'=>$company];$approval=new PaymentApproval($db);
$base=['channel'=>'Hepsiburada','expectedDate'=>'2026-11-03','expectedCents'=>100000,'status'=>'pending','source'=>'Elle giriş'];
$repo->save($admin,$company,'payments',$base,null);
$repo->save($admin,$company,'payments',[...$base,'expectedCents'=>20000,'verification'=>'confirmed','verificationSource'=>'Ekstre'],null);
$repo->save($admin,$company,'payments',[...$base,'expectedCents'=>30000,'status'=>'sent'],null);
$receipt=[...$base,'status'=>'paid','paidDate'=>'2026-11-04','actualCents'=>40000,'expectedCents'=>45000,'confirmation'=>'Banka'];
$reported=$repo->save($admin,$company,'payments',$receipt,null);
$approved=$repo->save($admin,$company,'payments',[...$receipt,'expectedCents'=>55000,'actualCents'=>50000],null);
$approval->approve($admin,$company,$approved['id'],['version'=>$approved['version']]);
$repo->save($admin,$other,'payments',[...$receipt,'actualCents'=>999999],null);
$legacy=$repo->save($admin,$company,'payments',$receipt,null);
$db->run("UPDATE records SET payload=? WHERE id=?",[json_encode([...$receipt,'integration'=>'old']),$legacy['id']]);
$payloads=fn()=>$db->all("SELECT id,payload FROM records WHERE kind='payments' ORDER BY id");$before=$payloads();
foreach([$admin,$client] as $actor){
 $calendar=(new PaymentCalendar($db))->report($actor,$company,'2026-11-01','2026-11-30');
 check($calendar['summary']===['estimatedCents'=>100000,'confirmedCents'=>20000,'receivedCents'=>50000,'sentCents'=>70000],'Beklenen, bildirilen ve onaylı tutarlar iki portalda ayrı kalmalı');
 check(count($calendar['items'])===5&&count(array_unique(array_column($calendar['items'],'id')))===5,'Bir ödeme iki satırla sayılmamalı');
 $summary=$repo->dashboard($actor,$company,'2026-11')['summary'];
 check($summary['paidCents']===50000&&$summary['sentCents']===70000&&$summary['expectedCents']===120000,'Genel bakış onaysız banka bilgisini alınan tutara katmamalı');
}
check($payloads()===$before,'Liste ve toplam okumaları hiçbir ödeme kaydını değiştirmemeli');
$done=$approval->approve($admin,$company,$reported['id'],['version'=>$reported['version']]);
check($done['expectedCents']===45000&&$done['actualCents']===40000,'Onay beklenen ve bildirilen farkı değiştirmemeli');
$after=(new PaymentCalendar($db))->report($admin,$company,'2026-11-01','2026-11-30')['summary'];
check($after===['estimatedCents'=>100000,'confirmedCents'=>20000,'receivedCents'=>90000,'sentCents'=>30000],'Onay yalnız aynı tutarı bildirilen toplamından onaylı toplama taşımalı');
$dashboard=$repo->dashboard($admin,$company,'2026-11')['summary'];
check($dashboard['paidCents']===90000&&$dashboard['sentCents']===30000,'Takvim ve genel bakış aynı onayı esas almalı');
check((new PaymentCalendar($db))->report($admin,$company,'2026-12-01','2026-12-31')['summary']===['estimatedCents'=>0,'confirmedCents'=>0,'receivedCents'=>0,'sentCents'=>0],'Ödeme başka dönemde tekrar toplanmamalı');
echo "Ödeme toplamları: onay şartı, aktarım ayrımı, kuruş farkı, rol ve müşteri yalıtımı, dönem ve tek sayım geçti.\n";
