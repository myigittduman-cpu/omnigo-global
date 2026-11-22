<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,PazaramaPricing,PttavmPricing,ProfitabilityCalculator,ProfitabilityFinancialEvidence,ProfitabilityMarketplacePricing,
    ProfitabilityOrderPricing,ProfitabilityShipmentPricing};

function expectPrice(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$pricing=['reportBasis'=>'discounted','originalCents'=>10000,'reportCents'=>7500,'discountedCents'=>7500,
    'sellerDiscountCents'=>500,'platformDiscountCents'=>2000];
$line=['lineId'=>'L','sku'=>'SKU','title'=>'Örnek','quantity'=>1,'netQuantity'=>1,'grossCents'=>7500,'cancelCents'=>0,'returnCents'=>0,'pricing'=>$pricing];
$facts=['storePriceCents'=>10000,'buyerPaidCents'=>7500,'sellerDiscountCents'=>500,'platformDiscountCents'=>2000];
foreach(['idefix','trendyol','pttavm'] as $provider){
    expectPrice(ProfitabilityOrderPricing::fromOrder($provider,[$line])===$facts,"$provider fiyat ayrımı");
}
$paza=[...$line,'grossCents'=>9500,'pricing'=>[...$pricing,'reportCents'=>9500,'reportBasis'=>'seller_invoice']];
expectPrice(ProfitabilityOrderPricing::fromOrder('pazarama',[$paza])===$facts,'Pazarama mağaza faturası alıcının ödediği tutardan ayrı.');
$pazaRaw=['quantity'=>1,'salePrice'=>['value'=>100,'currency'=>'TL'],'discountAmount'=>['value'=>5,'currency'=>'TL'],'discountDescription'=>'Mağaza kuponu'];
$noSuffix=PazaramaPricing::fromLine($pazaRaw,9500);
$verifiedZero=PazaramaPricing::fromLine([...$pazaRaw,'discountDescription'=>'(Pazarama İndirimi - 0 TL)'],9500);
foreach([$noSuffix,array_diff_key($noSuffix,['platformDiscountReported'=>true])] as $historic){
    $result=ProfitabilityOrderPricing::fromOrder('pazarama',[[...$paza,'pricing'=>$historic]]);
    expectPrice($result===['storePriceCents'=>10000,'buyerPaidCents'=>null,'sellerDiscountCents'=>500,'platformDiscountCents'=>null],
        'Pazarama açık platform indirimi verilmediğinde mağaza faturası alıcı ödemesi sayılamaz.');
}
expectPrice(ProfitabilityOrderPricing::fromOrder('pazarama',[[...$paza,'pricing'=>$verifiedZero]])===
    ['storePriceCents'=>10000,'buyerPaidCents'=>9500,'sellerDiscountCents'=>500,'platformDiscountCents'=>0],'Pazarama açık sıfır kaydedilir.');
$missingTotal=PttavmPricing::line([],7500,0);
expectPrice(ProfitabilityOrderPricing::fromOrder('pttavm',[[...$line,'pricing'=>$missingTotal]])===
    ['storePriceCents'=>7500,'buyerPaidCents'=>null,'sellerDiscountCents'=>null,'platformDiscountCents'=>null],'PTT eksik indirim sıfır değildir.');
$reportedTotal=PttavmPricing::line(['indirimToplam'=>0],7500,0);
expectPrice(ProfitabilityOrderPricing::fromOrder('pttavm',[[...$line,'pricing'=>$reportedTotal]])['buyerPaidCents']===7500,'PTT açık sıfır indirimi doğrulanır.');
$missing=[...$line,'grossCents'=>8000,'pricing'=>[...$pricing,'sellerDiscountCents'=>0,'reportCents'=>8000,'discountedCents'=>8000]];
$legacy=ProfitabilityOrderPricing::fromOrder('trendyol',[$missing]);
expectPrice($legacy['storePriceCents']===null&&$legacy['sellerDiscountCents']===null&&$legacy['platformDiscountCents']===2000&&$legacy['buyerPaidCents']===8000,'Trendyol eski kayıtta eksik indirim sıfır varsayılamaz.');
$missing['pricing']['sellerDiscountReported']=true;
expectPrice(ProfitabilityOrderPricing::fromOrder('trendyol',[$missing])['sellerDiscountCents']===0,'Teyitli sıfır korunur.');
$partialProof=$missing;$partialProof['pricing']['sellerDiscountReported']=false;$partialProof['pricing']['sourceGrossCents']=11000;
expectPrice(ProfitabilityOrderPricing::fromOrder('trendyol',[$partialProof])===['storePriceCents'=>11000,'buyerPaidCents'=>8000,'sellerDiscountCents'=>null,'platformDiscountCents'=>2000],'Belgelendirilmiş mağaza fiyatı görünür, kalan fark satıcı indirimi varsayılmaz.');
$foreign=[...$line,'pricing'=>[...$pricing,'pricingCurrencyMismatch'=>true]];
expectPrice(ProfitabilityOrderPricing::fromOrder('trendyol',[$foreign])===ProfitabilityOrderPricing::UNKNOWN,'Farklı para birimi toplanamaz.');
$tl=static fn($amount)=>['amount'=>$amount,'currency'=>'TRY'];
$raw=['id'=>'L','quantity'=>1,'totalPrice'=>$tl(75),'merchantTotalPrice'=>$tl(100),'totalMerchantDiscount'=>$tl(5),'totalHBDiscount'=>$tl(20),
    'commission'=>$tl(13),'commissionRate'=>13,'deptorDifferenceAmount'=>25];
expectPrice(ProfitabilityMarketplacePricing::hepsiburada($raw)===$facts,'HB açık fiyat alanları.');
expectPrice(ProfitabilityMarketplacePricing::hepsiburada([...$raw,'merchantTotalPrice'=>['amount'=>100,'currency'=>'USD']])['storePriceCents']===null,'Farklı döviz TL kabul edilemez.');
expectPrice(ProfitabilityMarketplacePricing::hepsiburada([...$raw,'merchantUnitPrice'=>$tl(90)])['storePriceCents']===null,'Tutarsız satır ve birim fiyatı.');
expectPrice(ProfitabilityMarketplacePricing::hepsiburada(['quantity'=>1,'totalPrice'=>$tl(75),'deptorDifferenceAmount'=>25])===ProfitabilityOrderPricing::UNKNOWN,'Aradaki fark taraflara yazılmaz.');
$hbLive=['id'=>'X','quantity'=>1,'unitPrice'=>$tl('5069.16'),'totalPrice'=>$tl('5069.16'),
    'hbDiscount'=>['totalPrice'=>$tl('929.844'),'unitPrice'=>$tl('929.844')],
    'merchantDiscount'=>['unitPrice'=>$tl(0),'totalPrice'=>$tl(0)],'deptorDifferenceAmount'=>929.84];
expectPrice(ProfitabilityMarketplacePricing::hepsiburada($hbLive)===['storePriceCents'=>599900,'buyerPaidCents'=>506916,
    'sellerDiscountCents'=>0,'platformDiscountCents'=>92984,'storePriceBasis'=>'buyer_plus_discounts'],'HB gerçek tutarları, katkının kaynağı ve hesaplanan mağaza fiyatı.');
$hbMulti=[...$hbLive,'quantity'=>2,'totalPrice'=>$tl('10138.32'),'hbDiscount'=>['totalPrice'=>$tl('1859.688'),'unitPrice'=>$tl('929.844')]];
expectPrice(ProfitabilityMarketplacePricing::hepsiburada($hbMulti)['platformDiscountCents']===185969,'HB çoklu adette kuruş yuvarlaması toplamda yapılır.');
expectPrice(ProfitabilityMarketplacePricing::hepsiburada([...$hbLive,'totalHBDiscount'=>$tl(800)])['storePriceCents']===null,'İki kaynak birbirini tutmuyorsa toplam çıkarılamaz.');
$n11=['price'=>50,'quantity'=>2,'dueAmount'=>75,'totalSellerDiscountPrice'=>5,'totalMallDiscountPrice'=>20];
expectPrice(ProfitabilityMarketplacePricing::n11($n11)===$facts,'n11 birim fiyatı adetle bir kere çarpılır.');
expectPrice(ProfitabilityMarketplacePricing::n11([...$n11,'dueAmount'=>78,'installmentChargeWithVAT'=>3])['buyerPaidCents']===null,'Vade farkı ürün ödemesi olarak gösterilmez.');
foreach([[1,12.34,1234],[2,0,0],[1,null,null],[2,12.34,null],[0,0,null]] as [$qty,$discount,$expected]){
    expectPrice(ProfitabilityMarketplacePricing::n11CommissionDiscount(['quantity'=>$qty,'sellerCampaignCommissionDiscount'=>$discount])===$expected,
        'n11 komisyon indirimi oranla veya belirsiz adetle hesaplanmaz.');
}
$quotes=[];$quote=['commissionDiscount'=>1234];
expectPrice(ProfitabilityMarketplacePricing::collectN11Quote($quotes,'1001',$quote)
    &&ProfitabilityMarketplacePricing::collectN11Quote($quotes,'1001',$quote)&&count($quotes)===1
    &&!ProfitabilityMarketplacePricing::collectN11Quote($quotes,'1001',['commissionDiscount'=>1235]),'Tekrarlanan n11 satırındaki indirim çift sayılmaz, çelişki reddedilir.');
$idea=[...$line,'grossCents'=>922410,'pricing'=>['originalCents'=>1024900,'sellerDiscountCents'=>0,'platformDiscountCents'=>102490,
    'discountedCents'=>922410,'reportCents'=>922410,'reportBasis'=>'discounted']];
expectPrice(ProfitabilityMarketplacePricing::idefixCommission(['earningAmount'=>9019.12,'commissionAmount'=>204.98],$idea)===20498,
    'idefix gerçek siparişinde indirimli ürün tutarı ile komisyonun uzlaşması.');
expectPrice(ProfitabilityMarketplacePricing::idefixCommission(['earningAmount'=>10044.02,'commissionAmount'=>204.98],$idea)===20498,
    'idefix mağaza fiyatından kendi indirimi düşüldükten sonraki açık matrah.');
expectPrice(ProfitabilityMarketplacePricing::idefixCommission(['earningAmount'=>9000,'commissionAmount'=>204.98],$idea)===null,
    'idefix tutarları uzlaşmadığında varsayım yapılmaz.');
$mirakl=['quantity'=>2,'price_unit'=>100,'price'=>200,'promotions'=>[],'price_amount_breakdown'=>['parts'=>[
    ['amount'=>160,'debitable_from_customer'=>true],['amount'=>40,'debitable_from_customer'=>false]]]];
$market=ProfitabilityMarketplacePricing::koctas($mirakl);
expectPrice($market['storePriceCents']===20000&&$market['buyerPaidCents']===16000&&$market['platformDiscountCents']===null,
    'Koçtaş siparişin teklif birim fiyatı ve açık tahsil bileşenleri.');
expectPrice(ProfitabilityMarketplacePricing::koctas(['quantity'=>1,'price'=>150])===ProfitabilityOrderPricing::UNKNOWN,'Koçtaş rapor satışından mağaza ve alıcı fiyatı türetilmez.');
expectPrice(ProfitabilityMarketplacePricing::koctas([...$mirakl,'price'=>180,'origin_unit_price'=>105,'promotions'=>[['type'=>'ITEM']]])['storePriceCents']===21000,
    'Koçtaş kaynak orijinal teklif fiyatı kampanya sonrasından ayrı.');
$order=['externalId'=>'42','createdAt'=>'2026-11-20T10:00:00Z','orders'=>1,'products'=>[$line],'grossCents'=>7500,'cancelCents'=>0,'returnCents'=>0];
$snapshot=['provider'=>'hepsiburada','order_number'=>'42','ordered_at'=>strtotime($order['createdAt'])];
$obs=ProfitabilityFinancialEvidence::hepsiburada($order,[$raw]);
expectPrice(ProfitabilityCalculator::row($snapshot,$order,[],[],$obs)['orderPricing']===$facts,'API kanıtı finans raporuna ulaşır.');
expectPrice(ProfitabilityCalculator::row($snapshot,$order,[],[],$obs)['products'][0]['orderPricing']===$facts,'Tek ürünün sipariş fiyatı satırda gösterilir.');
expectPrice(ProfitabilityFinancialEvidence::hepsiburada($order,[$raw,$raw])===null,'Yinelenen kaynak satırı kanıt oluşturamaz.');
$wrong=[...$order,'products'=>[[...$line,'lineId'=>'other']]];
expectPrice(ProfitabilityCalculator::row($snapshot,$wrong,[],[],$obs)['orderPricing']===ProfitabilityOrderPricing::UNKNOWN,'Sipariş satırı değişmiş eski kanıt reddedilir.');
$wrongFee=ProfitabilityCalculator::row($snapshot,$wrong,[],[],$obs);
expectPrice($wrongFee['commissionNetCents']===null&&$wrongFee['commissionSource']==='pending','Kimliği değişmiş kaydın eski komisyonu reddedilir.');
$fallback=ProfitabilityCalculator::row($snapshot,$wrong,[],[['channel'=>'Hepsiburada','sku'=>'SKU','effectiveFrom'=>'2026-11-01','basisPoints'=>100]],$obs);
expectPrice($fallback['commissionNetCents']===null&&$fallback['commissionCents']===75&&$fallback['commissionSource']==='manual','Parmak izi çakışmasında yalnız tanımlı tarihli oran kullanılır.');
$second=[...$line,'lineId'=>'X','sku'=>'B','title'=>'İkinci ürün','grossCents'=>506916];
$multi=[...$order,'products'=>[$line,$second],'grossCents'=>514416];
$multiProof=ProfitabilityFinancialEvidence::hepsiburada($multi,[$hbLive,$raw]);
$withLines=ProfitabilityCalculator::row($snapshot,$multi,[],[],$multiProof);
expectPrice($withLines['products'][0]['orderPricing']===$facts
    &&$withLines['products'][1]['orderPricing']['storePriceCents']===599900
    &&$withLines['products'][1]['storePriceBasis']==='buyer_plus_discounts'
    &&$withLines['orderPricing']['storePriceCents']===609900,'Sipariş fiyatları ham satır sırasından bağımsız doğru ürünle eşleşir.');
$aggregateOnly=$multiProof;unset($aggregateOnly['orderLinePricing']);
$notSpread=ProfitabilityCalculator::row($snapshot,$multi,[],[],$aggregateOnly);
expectPrice($notSpread['orderPricing']['storePriceCents']===609900&&$notSpread['products'][0]['orderPricing']===ProfitabilityOrderPricing::UNKNOWN,
    'Çok ürünlü sipariş toplamı ürünlere bölüştürülmez.');
$ambiguous=[];ProfitabilityOrderPricing::recordLine($ambiguous,$line,$facts);ProfitabilityOrderPricing::recordLine($ambiguous,$line,$facts);
expectPrice(!ProfitabilityOrderPricing::hasLineEvidence($ambiguous),'Kanıtı aynı yerel satırdaki iki ürüne atanmaz.');
$cancel=[...$line,'lineId'=>'C','netQuantity'=>0,'cancelCents'=>7500];
$partial=[...$order,'products'=>[$line,$cancel],'grossCents'=>15000,'cancelCents'=>7500];
expectPrice(ProfitabilityCalculator::row([...$snapshot,'provider'=>'trendyol'],$partial,[])['orderPricing']===$facts,'Tam iptal satırı kalan ürüne eklenmez.');
$partialUnit=[...$line,'quantity'=>2,'netQuantity'=>1,'returnCents'=>500];
expectPrice(ProfitabilityOrderPricing::fromOrder('trendyol',[$partialUnit])===ProfitabilityOrderPricing::UNKNOWN,'Kısmi iadenin indirimleri adet oranıyla uydurulmaz.');
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->run('CREATE TABLE shipment_entries(id INTEGER PRIMARY KEY,company_id INTEGER,provider TEXT,removed_at TEXT,source_key TEXT,payload TEXT)');
$cipher=DeliveryCipher::withKey(str_repeat('a',32));$reader=new ProfitabilityShipmentPricing($db,$cipher);
$key=hash('sha256','Hepsiburada:'.hash('sha256',json_encode(['42','L'],JSON_THROW_ON_ERROR)));
$stored=['source'=>['kind'=>'marketplace','provider'=>'Hepsiburada'],'quantity'=>1,'invoiceCents'=>7500,'orderNumber'=>'42',
    'marketplaceAmounts'=>['productCents'=>7500,'merchantCents'=>10000,'platformDiscountCents'=>2000,'sellerDiscountCents'=>500]];
$db->run("INSERT INTO shipment_entries VALUES(1,7,'Hepsiburada',NULL,?,?)",[$key,$cipher->seal(7,'shipment:1',$stored)]);
expectPrice($reader->hepsiburada(7,['42'=>$order])[42]['products'][0]['orderPricing']===$facts,'Kayıtlı fiyat tam sipariş ve satır kimliğiyle alınır.');
expectPrice(!isset($reader->hepsiburada(8,['42'=>$order])[42]['products'][0]['orderPricing']),'Farklı mağazanın verisi alınmaz.');
$db->run("UPDATE shipment_entries SET removed_at='2026-11-20'");
expectPrice(!isset($reader->hepsiburada(7,['42'=>$order])[42]['products'][0]['orderPricing']),'Kaldırılmış sevkiyat yeniden kullanılmaz.');
echo "Sipariş fiyatı, indirimler, eksik kanıt, para birimi, iadeler ve mağaza ayrımı doğrulandı.\n";
