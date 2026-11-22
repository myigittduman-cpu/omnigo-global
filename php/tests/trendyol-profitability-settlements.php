<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{ProfitabilityCommissionSource,QuestionReadTransport,SyncFailure,TrendyolProfitabilitySettlements,TrendyolSaleCommission};

function checkSettlement(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$day=new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul'));
$since=$day->getTimestamp()*1000;
$products=[];
foreach (['31','32'] as $id) $products[]=['lineId'=>$id,'quantity'=>1,'netQuantity'=>1,'sku'=>'SKU-'.$id,
    'grossCents'=>44999,'cancelCents'=>0,'returnCents'=>0];
$order=['externalId'=>'12','orders'=>1,'createdAt'=>$day->format(DATE_ATOM),'grossCents'=>89998,
    'cancelCents'=>0,'returnCents'=>0,'products'=>$products];
$row=['id'=>'71','orderNumber'=>'12','orderDate'=>$since,'orderLineItemId'=>31,'sellerId'=>82,'currency'=>'TRY',
    'transactionType'=>'Satış','transactionDate'=>$since,'debt'=>0,'credit'=>449.99,'commissionAmount'=>67.4985,
    'sellerRevenue'=>382.4915,'commissionRate'=>15];
$second=[...$row,'id'=>'72','orderLineItemId'=>'32'];
$line=TrendyolSaleCommission::movement($row,$order);
$other=TrendyolSaleCommission::movement($second,$order);
$proof=TrendyolSaleCommission::evidence($order,[$line,$other]);
checkSettlement($proof!==null && $proof['commissionCents']===13500 && $proof['commissionRateBasisPoints']===1500
    && $proof['commissionSource']==='marketplace_sale' && !isset($proof['afterDeductionsCents']),'Satış komisyonu doğrulanmalı; nihai ödeme üretilmemeli.');
$tiny=[...$row,'credit'=>1,'commissionAmount'=>'0.004','sellerRevenue'=>'0.996','commissionRate'=>null];
$smallOrder=[...$order,'grossCents'=>200,'products'=>array_map(fn($p)=>[...$p,'grossCents'=>100],$products)];
$one=TrendyolSaleCommission::movement($tiny,$smallOrder);
$two=TrendyolSaleCommission::movement([...$tiny,'id'=>72,'orderLineItemId'=>32],$smallOrder);
checkSettlement(TrendyolSaleCommission::evidence($smallOrder,[$one,$two])['commissionCents']===1,'Alt kuruşlar toplam alındıktan sonra yuvarlanmalı.');
checkSettlement(TrendyolSaleCommission::movement([...$row,'orderDate'=>$since-10800000],$order)===$line,
    'Sipariş ve finans uçlarının farklı tarihleri, eşleşen sipariş ve ürün kimliklerini değiştirmez.');
foreach ([['orderNumber'=>999],['currency'=>'USD'],['transactionType'=>'İade'],['orderLineItemId'=>null],
    ['commissionAmount'=>null],['debt'=>1],['sellerRevenue'=>0],['affiliate'=>'TRENDYOLAZJV']] as $invalid)
    checkSettlement(TrendyolSaleCommission::movement([...$row,...$invalid],$order)===null,'Farklı veya eksik hareket kabul edildi.');
foreach ([[$line],[$line,$line],[$line,[...$other,'id'=>$line['id']]],[$line,[...$other,'creditCents'=>2]]] as $invalid)
    checkSettlement(TrendyolSaleCommission::evidence($order,$invalid)===null,'Kalemleri örtüşmeyen komisyon üretildi.');
checkSettlement(TrendyolSaleCommission::evidence([...$order,'returnCents'=>3],[$line,$other])===null,'İadeli siparişe satış komisyonu onayı verilmemeli.');

$settings=['enabled'=>true,'supplierId'=>'82','apiKey'=>'test','apiSecret'=>'test'];
$saved=[['order_number'=>'12','sourceOrder'=>$order]];
$calls=0;
$pages=[array_merge([$row],array_fill(0,499,[...$row,'orderNumber'=>'99'])),[$second]];
$transport=new QuestionReadTransport(function($url,$headers,$deadline,$body) use (&$calls,$pages) {
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    checkSettlement(parse_url($url,PHP_URL_HOST)==='apigw.trendyol.com' && $query['transactionType']==='Sale'
        && (int)$query['page']===$calls && $body===null,'Finans isteği sınırı korunmalı.');
    $page=$calls++;
    return ['status'=>200,'body'=>json_encode(['page'=>$page,'size'=>500,'totalElements'=>501,'totalPages'=>2,'content'=>$pages[$page]],JSON_THROW_ON_ERROR)];
});
$source=new TrendyolProfitabilitySettlements($transport);
$checkpoint=null;
checkSettlement($source->read($settings,$saved,$day,time()+30,$checkpoint)===[] && $checkpoint!==null && $calls===1,'İlk sayfa ayrı bir iş diliminde kalmalı.');
checkSettlement($source->read($settings,$saved,$day,time()+30,$checkpoint)['12']['commissionCents']===13500 && $checkpoint===null && $calls===2,'Son sayfa tüm sipariş kalemlerini doğrulamalı.');

$earlier=$day->modify('-16 days');
$pastOrder=[...$order,'createdAt'=>$earlier->format(DATE_ATOM)];
$savedPast=[['order_number'=>'12','sourceOrder'=>$pastOrder]];
$windows=[];
$stub=new QuestionReadTransport(function($url) use (&$windows,$earlier,$row,$second) {
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $from=(int)$query['startDate'];
    $to=(int)$query['endDate'];
    $windows[]=[$from,$to];
    $move=count($windows)===1?$row:$second;
    $entry=[...$move,'orderDate'=>$earlier->getTimestamp()*1000,'transactionDate'=>$from];
    return ['status'=>200,'body'=>json_encode(['page'=>0,'size'=>500,'totalElements'=>1,'totalPages'=>1,'content'=>[$entry]])];
});
$reader=new TrendyolProfitabilitySettlements($stub);
checkSettlement($reader->read($settings,$savedPast,$earlier,time()+30,$checkpoint)===[] && $checkpoint!==null,'Pencere değişiminde sonuç tamamlandı sayılmamalı.');
$complete=$reader->read($settings,$savedPast,$earlier,time()+30,$checkpoint);
checkSettlement($complete['12']['commissionCents']===13500 && $checkpoint===null
    && $windows[1][0]===$windows[0][1]+1 && $windows[0][1]-$windows[0][0]<15*86400000,'Geç oluşan satış hareketleri sonraki pencerede birleştirilmeli.');

$offset=0;
$stub=new QuestionReadTransport(function() use (&$offset,$pages) {
    $page=$offset++;
    return ['status'=>200,'body'=>json_encode(['page'=>$page,'size'=>500,'totalElements'=>$page===0?501:502,
        'totalPages'=>2,'content'=>$pages[$page]])];
});
$reader=new TrendyolProfitabilitySettlements($stub);
$reader->read($settings,$saved,$day,time()+30,$checkpoint);
checkSettlement($reader->read($settings,$saved,$day,time()+30,$checkpoint)===[] && array_keys($checkpoint)===['batch'],
    'Sayfalama sırasında değişen sonuç yeniden taranmalı.');
$checkpoint=null;

foreach ([['sellerId'=>81, 'expected'=>403],['transactionDate'=>$since-1,'expected'=>422]] as $change) {
    $entry=[...$row,...$change];
    $stub=new QuestionReadTransport(fn()=>['status'=>200,'body'=>json_encode(['page'=>0,'size'=>500,'totalElements'=>1,'totalPages'=>1,'content'=>[$entry]])]);
    try { (new TrendyolProfitabilitySettlements($stub))->read($settings,$saved,$day,time()+30,$checkpoint); throw new RuntimeException('Geçersiz sayfa kabul edildi.'); }
    catch (SyncFailure $e) { checkSettlement($e->httpStatus===$change['expected'],'Sayfa doğrulama hatası bekleniyor.'); }
    $checkpoint=null;
}
$stub=new QuestionReadTransport(fn()=>['status'=>429,'retryAfter'=>123,'body'=>'']);
try { (new TrendyolProfitabilitySettlements($stub))->read($settings,$saved,$day,time()+30,$checkpoint); throw new RuntimeException('Kota hatası kayboldu.'); }
catch (SyncFailure $e) { checkSettlement($e->httpStatus===429 && $e->retryAfter===123,'Kuyruk bekleme süresini korumalı.'); }
checkSettlement(isset(ProfitabilityCommissionSource::PROVIDERS['trendyol']),'Kaynak komisyon kuyruğuna bağlı olmalı.');
echo "Trendyol satış komisyonu ve kesintisiz sayfalama kontrolleri geçti.\n";
