<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{TurnoverAmounts,SalesSummaryPeriods,CustomerSensitiveVisibility};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$order=['grossCents'=>100000,'cancelCents'=>20000,'returnCents'=>10000];$before=$order;
foreach(['hepsiburada','koctas','n11','pazarama','idefix','trendyol'] as $provider){
 $value=TurnoverAmounts::order($provider,$order);check($value===['turnoverCents'=>100000,'cancelledTurnoverCents'=>20000,'salesTurnoverCents'=>80000],$provider.' kısmi iptal ayrımı');
 check(TurnoverAmounts::order($provider,[...$order,'cancelCents'=>100000])['salesTurnoverCents']===0,'Tam iptal ana satışa eklenmez');
}
check($order===$before,'Kaynak fatura ve iade değiştirilmez');
$line=['grossCents'=>90000,'cancelCents'=>90000,'quantity'=>2,'cancelledQuantity'=>2,'pricing'=>['originalCents'=>100000]];
check(TurnoverAmounts::order('pttavm',['products'=>[$line]])===['turnoverCents'=>100000,'cancelledTurnoverCents'=>100000,'salesTurnoverCents'=>0],'PTT kupon öncesi tam iptal');
$line['cancelledQuantity']=1;$line['cancelCents']=45000;
check(TurnoverAmounts::order('pttavm',['products'=>[$line]])['salesTurnoverCents']===50000,'PTT kısmi iptal aynı kaynak esasında');
unset($line['cancelledQuantity']);check(TurnoverAmounts::order('pttavm',['products'=>[$line]])['salesTurnoverCents']===null,'Teyitsiz kısmi iptal tahmin edilmez');
$clock=new DateTimeImmutable('2026-11-06T12:00:00+03:00');$source=[...$order,...TurnoverAmounts::order('idefix',$order),'at'=>$clock,'orders'=>1,'quantity'=>2,'salesQuantity'=>1];
$period=SalesSummaryPeriods::window([$source],$clock->setTime(0,0),$clock,$clock->setTime(0,0),$clock);
check($period['salesTurnoverCents']===80000&&$period['turnoverCents']===100000&&$period['cancelledTurnoverCents']===20000,'Dönem kırılımında brüt, iptal ve satış ayrı');
$data=['marketplaceSales'=>['channels'=>[['channel'=>'idefix','periods'=>['month'=>$period,'today'=>$period]]]],'sales'=>[['channel'=>'idefix','integration'=>'idefix',...$order]],'summary'=>['netCents'=>70000,'paidCents'=>12345],'salesToday'=>['channels'=>[['channel'=>'idefix']]]];
$out=TurnoverAmounts::dashboard($data);check($out['summary']['salesTurnoverCents']===80000&&$out['sales'][0]['salesTurnoverCents']===80000&&$out['salesToday']['channels'][0]['salesTurnoverCents']===80000,'Aylık, gün ve kanal aynı hesabı kullanır');
check($out['summary']['paidCents']===12345&&$out['summary']['netCents']===70000&&$out['sales'][0]['grossCents']===100000,'Ödeme ve fatura korunur');
$client=CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($out),['cancellations'=>false,'returns'=>false]);check(!isset($client['summary']['cancelledTurnoverCents'])&&!isset($client['sales'][0]['cancelCents'])&&$client['summary']['turnoverCents']===80000&&!array_key_exists('salesTurnoverCents',$client['summary']),'Gizli iptal tutarı gönderilmeden doğru satış görünür');
check(TurnoverAmounts::order('idefix',['grossCents'=>100,'cancelCents'=>101])['salesTurnoverCents']===null,'Geçersiz kaynak tutarı sıfıra çevrilmez');
echo "Tam/kısmi iptal, PTT kuponu, müşteri gizliliği ve değişmeyen fatura/ödeme hesapları geçti.\n";
