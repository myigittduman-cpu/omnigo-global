<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{TurnoverAmounts,SalesSummaryPeriods};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$invoice=['grossCents'=>100000,'cancelCents'=>20000,'returnCents'=>10000,'products'=>[['grossCents'=>100000,'pricing'=>['originalCents'=>105000]]]];$before=$invoice;
foreach(['hepsiburada','koctas','n11','pazarama','idefix','trendyol'] as $provider)check(TurnoverAmounts::order($provider,$invoice)['turnoverCents']===100000,'Cirodan iptal/iade düşülmemeli: '.$provider);
check(TurnoverAmounts::order('pttavm',$invoice)['turnoverCents']===105000,'PTT kaynak toplamında kupon ikinci kez düşülmemeli');check($before===$invoice,'Fatura ve net hesap değişmemeli');
check(TurnoverAmounts::order('pttavm',['grossCents'=>100000])['turnoverCents']===null,'Doğrulanamayan kaynak tutarı tahmin edilmemeli');
$total=TurnoverAmounts::ZERO;TurnoverAmounts::add($total,['turnoverCents'=>100]);TurnoverAmounts::add($total,['turnoverCents'=>null]);TurnoverAmounts::add($total,['turnoverCents'=>200]);check($total['turnoverCents']===null,'Eksik tutar sıfır sayılmamalı');
$at=new DateTimeImmutable('2026-11-06T12:00:00+03:00');$order=['at'=>$at,'grossCents'=>100000,'cancelCents'=>20000,'returnCents'=>10000,'orders'=>1,'turnoverCents'=>105000,'quantity'=>1,'salesQuantity'=>1];
$p=SalesSummaryPeriods::window([$order],$at->setTime(0,0),$at,$at->setTime(0,0),$at);check($p['turnoverCents']===105000&&$p['amountCents']===70000,'Ciro ve finansal net aynı dönemde bağımsız kalmalı');
$result=['marketplaceSales'=>['channels'=>[['channel'=>'PTT AVM','periods'=>['month'=>$p,'today'=>$p]]]],'sales'=>[['channel'=>'PTT AVM','integration'=>'pttavm',...$invoice]],'summary'=>['netCents'=>70000,'paidCents'=>12345],'salesToday'=>['channels'=>[['channel'=>'PTT AVM','netCents'=>70000]]]];
$out=TurnoverAmounts::dashboard($result);check($out['summary']['turnoverCents']===105000&&$out['summary']['netCents']===70000&&$out['summary']['paidCents']===12345,'Ciro sunumu ödeme ve neti bozmamalı');check($out['sales'][0]['grossCents']===100000,'Sipariş fatura tutarı korunmalı');
echo "Ciro, iptal/iade ayrımı, PTT kuponu, bilinmeyen tutar ve fatura/ödeme koruması geçti.\n";
