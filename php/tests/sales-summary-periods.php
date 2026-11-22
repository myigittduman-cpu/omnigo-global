<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\SalesSummaryPeriods;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$clock=new DateTimeImmutable('2026-10-17T17:00:00+03:00');$from=new DateTimeImmutable('2026-09-01T00:00:00+03:00');
$order=fn($date,$amount,$cancel=0,$refund=0)=>['at'=>new DateTimeImmutable($date),'grossCents'=>$amount,'cancelCents'=>$cancel,'returnCents'=>$refund,'orders'=>1,'quantity'=>1,'salesQuantity'=>2];
$orders=[$order('2026-10-09T20:59:59Z',999),$order('2026-10-09T21:00:00Z',10000,1000,2000),$order('2026-10-16T20:59:59Z',5000),$order('2026-10-16T21:00:00Z',99000),$order('2026-09-17T00:00:00+03:00',4000)];
$seven=SalesSummaryPeriods::completed($orders,7,$clock,$from,$clock);
check($seven['from']==='2026-10-10'&&$seven['to']==='2026-10-16'&&$seven['orders']===2,'Yedi tamamlanmış Türkiye günü; bugün hariç');
check($seven['salesCents']===14000&&$seven['amountCents']===12000&&$seven['cancelCents']===1000&&$seven['returnCents']===2000,'Satış, iptal, iade ve net birbirine karışmaz');
check($seven['salesQuantity']===4&&$seven['quantity']===2,'Satış adedi ve net adet ayrı');
$thirty=SalesSummaryPeriods::completed($orders,30,$clock,$from,$clock);
check($thirty['from']==='2026-09-17'&&$thirty['to']==='2026-10-16'&&$thirty['salesCents']===18999,'Otuz gün ay sınırı');
check($thirty['previous']===null,'Eksik önceki dönem yüzdeye çevrilmez');
$rolling=SalesSummaryPeriods::rolling($orders,7,$clock,$from,$clock);
check($rolling['from']==='2026-10-11'&&$rolling['orders']===2,'Diğer pazaryerinin mevcut tarih kuralı korunur');
echo "Satış dönemleri: tamamlanmış 7/30 gün, Türkiye gün sınırı, ayrı iptal/iade/net, adet ve eksik karşılaştırma geçti.\n";
