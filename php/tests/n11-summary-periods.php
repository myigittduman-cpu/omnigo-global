<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,MarketplaceSalesSummary,N11SummaryPeriods,SalesSummaryPeriods};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$clock=new DateTimeImmutable('2026-10-17T16:00:00Z');$from=new DateTimeImmutable('2026-07-01T00:00:00+03:00');
$order=fn($at,$amount,$cancel=0,$refund=0)=>['at'=>new DateTimeImmutable($at),'grossCents'=>$amount,'cancelCents'=>$cancel,'returnCents'=>$refund,'panelSalesCents'=>$amount-$cancel,'platformDiscountCents'=>0,'sellerDiscountCents'=>0,'orders'=>1,'quantity'=>1,'salesQuantity'=>1];
$orders=[$order('2026-10-09T20:59:59Z',999),$order('2026-10-09T21:00:00Z',15000,1000,2000),$order('2026-10-17T10:00:00+03:00',5000),$order('2026-09-17T00:00:00+03:00',9000)];
$week=N11SummaryPeriods::window($orders,'seven',$clock,$from,$clock);
check($week['from']==='2026-10-10'&&$week['to']==='2026-10-17'&&$week['orders']===2,'n11 hafta başlangıcı ve bugün kapsanır; UTC sınırı korunur');
check($week['salesCents']===19000&&$week['returnCents']===2000&&$week['amountCents']===17000,'İptal, iade ve net birbirine karışmaz');
$month=N11SummaryPeriods::window($orders,'thirty',$clock,$from,$clock);check($month['from']==='2026-09-17'&&$month['salesCents']===28999,'n11 bir aylık kapsayıcı tarih');
foreach(['2026-03-31'=>'2026-02-28','2028-03-31'=>'2028-02-29','2026-01-31'=>'2025-12-31'] as $day=>$expected){$c=new DateTimeImmutable($day.'T12:00:00+03:00');$p=N11SummaryPeriods::window([],'thirty',$c,$c->modify('-90 days'),$c);check($p['from']===$expected,'Kısa ay/yıl sınırı: '.$day);}
check(SalesSummaryPeriods::rolling($orders,7,$clock,$from,$clock)['from']==='2026-10-11','Diğer pazaryerinin dönemi değişmez');
$partial=N11SummaryPeriods::window($orders,'seven',$clock,new DateTimeImmutable('2026-10-11T00:00:00+03:00'),$clock);check($partial['partial']&&$partial['previous']===null,'Eksik kapsam yüzdeye dönüştürülmez');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new \Omnigo\Repository($db);$company=$repo->createCompany('Örnek');
foreach(['n11','idefix'] as $provider){
 $state=['startDate'=>$from->format(DATE_ATOM),'watermark'=>$clock->format(DATE_ATOM),'ordersPublished'=>true,'refundsVerified'=>true];
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_'.$provider,json_encode($state),gmdate('c')]);
 foreach($orders as $o){$v=[...$o,'createdAt'=>$o['at']->format(DATE_ATOM),'products'=>[]];unset($v['at']);$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_'.$provider,json_encode($v),gmdate('c')]);}
}
$service=new MarketplaceSalesSummary($db);$channels=[['name'=>'n11'],['name'=>'idefix']];$summary=$service->dashboard($company,$channels,'2026-10',$clock);$by=array_column($summary['channels'],null,'channel');
check($by['n11']['periods']['seven']['salesCents']===19000&&$by['n11']['periodPolicy']==='n11_inclusive','Özet n11 politika ve tutarı');
check($by['n11']['days'][0]['date']==='2026-09-17'&&count($by['n11']['days'])===31,'n11 grafik kapsamı özetle aynıdır');
check($by['idefix']['periods']['seven']['from']==='2026-10-11'&&count($by['idefix']['days'])===30,'idefix dönem ve grafik kapsamı korunur');
$past=$service->dashboard($company,$channels,'2026-09',$clock);$past=array_column($past['channels'],null,'channel')['n11'];check($past['days'][0]['date']==='2026-09-01'&&end($past['days'])['date']==='2026-09-30','Seçili geçmiş ay değişmez');
$longClock=new DateTimeImmutable('2026-05-31T12:00:00+03:00');
$db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='sync_n11'",[json_encode(['startDate'=>'2026-02-01T00:00:00+03:00','watermark'=>$longClock->format(DATE_ATOM)]),$company]);
$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_n11',json_encode(['createdAt'=>'2026-03-29T01:00:00+03:00','orders'=>1,'grossCents'=>12345,'cancelCents'=>0,'returnCents'=>0,'products'=>[]]),gmdate('c')]);
$long=$service->dashboard($company,$channels,'2026-05',$longClock);$long=array_column($long['channels'],null,'channel')['n11'];
check($long['periods']['thirty']['previous']['salesCents']===12345,'Uzun aylarda önceki dönemin ilk günleri veritabanı sorgusunda kaybolmaz');
echo "n11 kapsayıcı hafta/ay, kısa ay ve yıl sınırı, iptal/iade/net, grafik ve diğer pazaryeri regresyonları geçti.\n";
