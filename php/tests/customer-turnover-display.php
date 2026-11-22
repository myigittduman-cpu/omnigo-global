<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{CustomerSensitiveVisibility,CustomerTableVisibility,CustomerTurnoverDisplay,SalesUnitTotals,SalesPeriodMetrics};

function expect($actual,$expected,string $field):void {
    if($actual!==$expected)throw new RuntimeException($field.' '.json_encode([$actual,$expected]));
}

$source=['turnoverCents'=>100000,'grossCents'=>98000,'cancelledTurnoverCents'=>20000,'cancelCents'=>19000,
    'salesTurnoverCents'=>80000,'returnCents'=>11000,'netCents'=>68000,'turnoverAverageCents'=>33333,
    'orders'=>3,'salesOrders'=>2,'quantity'=>7,'orderedQuantity'=>10,'salesQuantity'=>8];
$input=['summary'=>$source,'series'=>[['days'=>[$source]]],'periods'=>['previous'=>$source],
    'sales'=>[['turnoverCents'=>900,'cancelledTurnoverCents'=>400,'returnCents'=>300]],
    'invoice'=>['grossCents'=>9981,'netCents'=>7481],'payments'=>[['expectedCents'=>82]]];
$projected=CustomerTurnoverDisplay::project($input);
foreach([$projected['summary'],$projected['series'][0]['days'][0],$projected['periods']['previous']] as $row){
    expect($row['turnoverDisplayBasis'],'after_cancellations','Ciro kaynağı');
    expect($row['turnoverCents'],80000,'İade tekrar düşülmez');
    expect($row['grossCents'],80000,'Brüt takma adı');
    expect($row['turnoverAverageCents'],40000,'Sipariş ortalaması');
    expect([$row['orders'],$row['quantity'],$row['orderedQuantity']],[2,8,8],'Görünen sipariş ve adet');
    expect(array_key_exists('salesTurnoverCents',$row),false,'Çift ciro');
}
expect($projected['sales'][0]['turnoverCents'],500,'Eski kayıtların kaynak-bazlı iptali');
expect(CustomerTurnoverDisplay::project($projected),$projected,'İkinci yansıtmada iptal iki kez düşülmez');
expect($projected['invoice'],$input['invoice'],'Fatura korunur');
expect($projected['payments'],$input['payments'],'Hakediş korunur');
expect($input['summary'],$source,'Kaynak korunur');
foreach([['turnoverCents'=>10000,'salesTurnoverCents'=>null,'cancelCents'=>0],
         ['turnoverCents'=>10000],['grossCents'=>50,'cancelCents'=>60]] as $unknown){
    expect(CustomerTurnoverDisplay::project($unknown)['turnoverCents'],null,'Teyitsiz iptal sonrası veri');
}
$hidden=CustomerSensitiveVisibility::redact($projected['summary'],CustomerTableVisibility::NONE);
expect(array_intersect_key($hidden,array_flip(['cancelledTurnoverCents','cancelCents','returnCents','netCents','salesOrders'])),[],'Gizli kırılımlar');
expect(CustomerSensitiveVisibility::redact($projected['summary'],CustomerTableVisibility::ALL)['returnCents'],11000,'Sonradan verilen iade görünürlüğü');
expect(SalesUnitTotals::activeLine(['quantity'=>5,'cancelledQuantity'=>2,'returnCents'=>100,'netQuantity'=>2]),3,'İade adedi tekrar çıkarılmaz');
expect(SalesUnitTotals::activeLine(['quantity'=>5,'cancelCents'=>100,'grossCents'=>300,'returnCents'=>10,'netQuantity'=>2]),null,'Belirsiz kısmi iptal adedi');
expect(SalesUnitTotals::activeLine(['quantity'=>4,'cancelCents'=>0,'returnCents'=>80,'netQuantity'=>2]),4,'Yalnız iade');
$clock=new DateTimeImmutable('2026-11-12T12:00:00+03:00');
$windows=SalesPeriodMetrics::windows($clock);
$order=[...$source,'salesQuantity'=>8,'at'=>$clock,'cancelCents'=>20000,'returnCents'=>11000];
$admin=SalesPeriodMetrics::marketplace([$order],$windows,$clock->modify('-1 month'),$clock,true);
$customer=SalesPeriodMetrics::marketplace([$order],$windows,$clock->modify('-1 month'),$clock,true,true);
expect($admin['month']['turnoverCents'],100000,'Yöneticinin brüt aylık cirosu');
expect($customer['month']['turnoverCents'],80000,'Müşterinin aylık cirosu');
expect($customer['month']['orders'],2,'Aylık iptal sonrası sipariş sayısı');
$quantityOnly=CustomerTurnoverDisplay::project(['orderedQuantity'=>5,'cancelledQuantity'=>2]);
expect($quantityOnly['orderedQuantity'],3,'Eski satırda sadece doğrulanan iptal kadar düş');
expect(CustomerTurnoverDisplay::project($quantityOnly),$quantityOnly,'Yalnız adet sunumunda tekrar iptal düşülmez');
echo "Müşteri ciro, adet ve dönem yansıması doğrulandı.\n";
