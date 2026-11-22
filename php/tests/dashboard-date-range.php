<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/fixtures/financial-visibility.php';
use Omnigo\{DashboardDateRange,HttpError,Database,Repository,MarketplaceSalesSummary};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$other=$repo->createCompany('Diğer');
$channels=$repo->records($company,'channels',['role'=>'admin']);$clock=new DateTimeImmutable('2026-10-11T16:00:00+03:00');
$save=function(int $firm,string $kind,array $value)use($db){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$firm,$kind,json_encode($value),gmdate('c')]);};
$save($company,'sync_koctas',['startDate'=>'2026-08-01T00:00:00+03:00','watermark'=>$clock->format(DATE_ATOM)]);
foreach([['2026-10-10T21:00:00Z',10000,1000,500],['2026-10-10T09:00:00Z',5000,0,0],['2026-10-10T20:00:00Z',7000,0,0],['2026-09-12T00:00:00+03:00',11000,0,0]] as [$created,$gross,$cancel,$returns]){
 $save($company,'order_koctas',['externalId'=>$created,'createdAt'=>$created,'orders'=>1,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$returns,'products'=>[['sku'=>'A','title'=>'Örnek','quantity'=>2,'netQuantity'=>1,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$returns,'netCents'=>$gross-$cancel-$returns]]]);
}
$save($other,'order_koctas',['createdAt'=>'2026-10-11T12:00:00+03:00','orders'=>1,'grossCents'=>99000000,'cancelCents'=>0,'returnCents'=>0]);
$service=new MarketplaceSalesSummary($db);
foreach(['2026-10-10','2026-10-11','2026-10-12'] as $day){
 $save($company,'tasks',['channel'=>'Tüm kanallar','date'=>$day,'status'=>'planned','visibility'=>'client','title'=>$day]);
 $save($company,'payments',['channel'=>'Koçtaş','expectedDate'=>$day,'paidDate'=>'','status'=>'pending','expectedCents'=>1000,'actualCents'=>0]);
}
foreach(['2026-09','2026-10','2026-11'] as $month)$save($company,'reports',['channel'=>'Tüm kanallar','month'=>$month,'status'=>'published','title'=>$month]);
$before=json_encode($db->all('SELECT * FROM records ORDER BY id'));
$range=['from'=>'2026-10-11','to'=>'2026-10-11'];
$result=$service->dashboard($company,$channels,'2026-10',$clock,$range);
$item=array_column($result['channels'],null,'channel')['Koçtaş'];
check($item['periods']['month']['amountCents']===8500,'Günlük aralık iptal ve iadeyi aynı hesapla düşer');
check($result['currentMonthTurnoverCents']===22000,'Toplam ciro, seçilen tek günün yerine ayın tüm brüt aktarımını kullanır');
check(count($item['days'])===1&&$item['days'][0]['date']==='2026-10-11','Grafik yalnız seçili günü gösterir');
$span=$service->dashboard($company,$channels,'2026-10',$clock,['from'=>'2026-09-12','to'=>'2026-10-10']);
$item=array_column($span['channels'],null,'channel')['Koçtaş'];
check($item['periods']['month']['amountCents']===23000,'Ay aşan aralık tüm günleri içerir, sonraki güne taşmaz');
check($span['currentMonthTurnoverCents']===22000,'Satış tablosunun tarih seçimi cari ay cirosunu değiştirmez');
allowFinancialVisibility($db,$company);
$admin=$repo->dashboard(['role'=>'admin'],$company,'2026-10',$range);
$client=$repo->dashboard(['id'=>2,'role'=>'client','company_id'=>$company],$company,'2026-10',$range);
$a=array_column($admin['sales'],null,'channel')['Koçtaş'];$c=array_column($client['sales'],null,'channel')['Koçtaş'];
check($a['grossCents']===10000&&$a['cancelCents']===1000&&$a['returnCents']===500&&\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($a),['cancellations'=>false,'returns'=>false])===$c,'İki panel aynı fatura bazlı aralığı gösterir; müşteri iptal/iade kırılımını almaz');
check($admin['dateRange']===$range,'Yanıt uygulanan aralığı taşır');
check(count($admin['tasks'])===1&&$admin['tasks'][0]['date']==='2026-10-11','Çalışmalar yalnız tarih aralığından gelir');
check(count($admin['payments'])===1&&$admin['summary']['expectedCents']===1000,'Hakedişler ve toplamları aynı tarih aralığındadır');
check(array_column($admin['reports'],'month')===['2026-10'],'Aylık raporlar aralıkla kesişen dönemden gelir');
check(!DashboardDateRange::includes('','2026-10-01','2026-10-28'),'Tarihsiz kayıt gün aralığına dahil edilmez');
try{$repo->dashboard(['role'=>'admin'],$company,'2026-10',['from'=>'2026-10-12','to'=>'2026-10-11']);throw new RuntimeException('Ters aralık reddedilmedi');}catch(HttpError $e){check($e->getCode()===400,'Geçersiz aralık hatası');}
check($before===json_encode($db->all('SELECT * FROM records ORDER BY id')),'Tarih filtresi sipariş ve bağlantı kayıtlarını değiştirmez');
echo "Tarih aralığı: gün/ay sınırları, iptal/iade neti, müşteri yalıtımı, iki panel eşliği ve salt okunurluk geçti.\n";
