<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{PazaramaPricing,SourceSalesAmounts,PazaramaDateWindow};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$money=fn($value)=>['currency'=>'TL','value'=>$value];
$line=['quantity'=>1,'salePrice'=>$money(42545),'discountAmount'=>$money(200),'discountDescription'=>'1000 TL üzeri 200 TL kupon (Pazarama İndirimi - 2127.25 TL)'];
$pricing=PazaramaPricing::fromLine($line,4234500);
check($pricing['discountedCents']===4021775&&$pricing['reportCents']===4234500,'Kupon ikinci kez düşülmez; platform katkısı ayrı tutulur');
$product=['grossCents'=>4234500,'cancelCents'=>0,'returnCents'=>0,'pricing'=>$pricing];
$order=['grossCents'=>4234500,'cancelCents'=>0,'products'=>[$product]];
$source=SourceSalesAmounts::order('pazarama',$order);
check($source===['panelSalesCents'=>4021775,'platformDiscountCents'=>212725,'sellerDiscountCents'=>20000],'Pazarama paneli ve fatura farkı');
$multi=PazaramaPricing::fromLine([...$line,'quantity'=>4,'salePrice'=>$money(27990),'discountAmount'=>$money(0),'discountDescription'=>' (Pazarama İndirimi - 6717.6 TL)'],11196000);
check($multi['discountedCents']===10524240,'Platform indirimi miktarla yeniden çarpılmaz');
check(PazaramaPricing::fromLine([...$line,'discountDescription'=>'Pazarama bilinmeyen biçim'],4234500)===null,'Bilinmeyen tutar uydurulmaz');
check(PazaramaPricing::fromLine([...$line,'discountDescription'=>'(Pazarama İndirimi - 999999 TL)'],4234500)===null,'Faturayı aşan indirim reddedilir');
$idefix=['grossCents'=>9074790,'cancelCents'=>0,'returnCents'=>10000,'pricing'=>['originalCents'=>10083100,'platformDiscountCents'=>1008310,'sellerDiscountCents'=>0,'discountedCents'=>9074790,'reportCents'=>9074790,'reportBasis'=>'discounted']];
check(SourceSalesAmounts::order('idefix',['products'=>[$idefix]])['panelSalesCents']===10083100,'idefix indirimsiz panel satışını gösterir; iade ayrıca kalır');
check(SourceSalesAmounts::order('idefix',['products'=>[[...$idefix,'cancelCents'=>9074790]]])['panelSalesCents']===0,'İptal ana satışa eklenmez');
check(SourceSalesAmounts::order('idefix',['products'=>[[...$idefix,'cancelCents'=>1]]])['panelSalesCents']===null,'Belirsiz kısmi iptal sıfıra dönüşmez');
$sum=SourceSalesAmounts::ZERO;SourceSalesAmounts::add($sum,$source);SourceSalesAmounts::add($sum,['panelSalesCents'=>null]);check($sum['panelSalesCents']===null,'Eksik fiyat bilgisi toplamda korunur');
check(PazaramaDateWindow::end('2026-09-01T00:00','2026-10-17T18:00')==='2026-09-14T23:59','İki haftalık aralık bitiş gününü kapsar');
check(PazaramaDateWindow::next('2026-09-14T23:59')==='2026-09-15T00:00','Sınır siparişi ikinci kez taranmaz');
check(PazaramaDateWindow::end('2026-10-13T00:00','2026-10-17T18:00')==='2026-10-17T18:00','Son tarama kapsamı korunur');
echo "Panel/fatura ayrımı, çok adetli indirim, iptal/iade ve tarih sınırı kontrolleri geçti.\n";
