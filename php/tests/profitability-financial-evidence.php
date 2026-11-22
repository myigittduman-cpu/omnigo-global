<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{FinanceClient,ProfitabilityCalculator,ProfitabilityCommission,ProfitabilityFinancialEvidence,SyncFailure};
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function pazaramaEvidence(array $order,array $movements):?array{return ProfitabilityFinancialEvidence::pazarama($order,$movements,'875601548');}
function sourceOrder(int $sales,int $qty=1):array{return ['externalId'=>'12345678-aaaa-bbbb-cccc-123456789abc','createdAt'=>'2026-11-17T12:00:00+03:00','orders'=>1,
    'grossCents'=>$sales,'cancelCents'=>0,'returnCents'=>0,'products'=>[['sku'=>'SKU','title'=>'Ürün','lineId'=>'1','quantity'=>$qty,'netQuantity'=>$qty,
    'grossCents'=>$sales,'cancelCents'=>0,'returnCents'=>0]]];}
$order=sourceOrder(2768200);
$raw=['orderId'=>875601548,'trxId'=>12345,'trxCode'=>'sale','status'=>'Satış','transactionDate'=>'2026-11-17T12:00:00',
    'transferredDate'=>null,'installmentNumber'=>1,'amount'=>27682,'commissionAmount'=>-2770.42,'integrationAmount'=>-13.08,
    'stoppageAmount'=>-230.68,'merchantCargoDebt'=>0,'couponDiscount'=>0,'allowanceAmount'=>24667.82];
$proof=pazaramaEvidence($order,[$raw]);
$snapshot=['order_number'=>'875601548','provider'=>'pazarama','ordered_at'=>strtotime($order['createdAt'])];
verify($proof['afterDeductionsCents']===2466782&&$proof['otherDeductionsCents']===24376,'Gerçek Pazarama örneği kesintileri nete eşit.');
verify($proof['statementCouponCents']===0&&$proof['statementShippingCents']===0,'Ekstredeki açık sıfırlar korunur.');
foreach(['amount'=>27682.01,'status'=>'İade','currencyCode'=>'USD'] as $key=>$value)
    verify(pazaramaEvidence($order,[[...$raw,$key=>$value]])===null,'Yanlış satış, iade veya yabancı para eşleştirilemez: '.$key);
foreach(['allowanceAmount'=>24667.81,'commissionAmount'=>2770.42,'merchantCargoDebt'=>100,'couponDiscount'=>1] as $key=>$value){
    $known=pazaramaEvidence($order,[[...$raw,$key=>$value]]);
    verify(($known['afterDeductionsCents']??null)===null&&($known['otherDeductionsCents']??null)===null
        &&$known['withholdingCents']===23068&&$known['serviceCents']===1308,'Toplamı doğrulanmamış kayıtta teyitli masraflar korunur: '.$key);
    if($key==='commissionAmount')verify(!isset($known['commissionCents']),'Yanlış yöndeki komisyon kesinti sayılamaz.');
    $pending=ProfitabilityCalculator::row($snapshot,$order,[],[],$known);
    verify($pending['statementCouponCents']===null&&$pending['statementShippingCents']===null,'Uyuşmayan ekstre kuponu veya kargosu doğrulanmış gibi gösterilemez.');
}
$cargo=pazaramaEvidence($order,[[...$raw,'merchantCargoDebt'=>-117.44,'allowanceAmount'=>24550.38]]);
verify($cargo['afterDeductionsCents']===2455038&&$cargo['otherDeductionsCents']===36120&&!isset($cargo['shippingCents']),'Gerçek Pazarama kargo borcu hakediş kaydında bir kez bulunur.');
foreach([[-20,24530.38,38120],[20,24570.38,34120]] as [$coupon,$net,$other]){
    $withCoupon=pazaramaEvidence($order,[[...$raw,'merchantCargoDebt'=>-117.44,'couponDiscount'=>$coupon,'allowanceAmount'=>$net]]);
    verify($withCoupon['afterDeductionsCents']===(int)round($net*100)&&$withCoupon['otherDeductionsCents']===$other,
        'Pazarama kupon hareketinin bildirilen işareti kuruş mutabakatına katılır.');
    $row=ProfitabilityCalculator::row($snapshot,$order,[],[],$withCoupon);
    verify($row['statementCouponCents']===$coupon*100&&$row['statementShippingCents']===-11744&&$row['shippingCents']===null,
        'Raporda doğrulanmış ekstre kuponu ve kargo borcunun hareket yönü korunur.');
}
verify(pazaramaEvidence($order,[$raw,$raw])===null,'Yinelenen hareket çift sayılmaz.');
verify(pazaramaEvidence($order,[$raw,[...$raw,'status'=>'İade']])===null,'Aynı trxId ile iade gizlenemez.');
verify(pazaramaEvidence($order,[[...$raw,'orderId'=>99]])===null,'Başka sipariş eşleştirilemez.');
$missing=$raw;unset($missing['integrationAmount']);
$part=pazaramaEvidence($order,[$missing]);
verify(!isset($part['serviceCents'])&&!isset($part['afterDeductionsCents'])&&$part['commissionCents']===277042&&$part['withholdingCents']===23068,
    'Eksik ücret sıfır olamaz, diğer teyitli kesintiler korunur.');
$single=ProfitabilityFinancialEvidence::hepsiburada(sourceOrder(506916),[['id'=>'1','quantity'=>1,'commission'=>['currency'=>'TRY','amount'=>779.87],
    'commissionRate'=>13,'deptorDifferenceAmount'=>929.84,'totalPrice'=>['currency'=>'TRY','amount'=>5069.16]]]);
verify($single['commissionCents']===null&&$single['commissionNetCents']===77987&&$single['orderPricing']['platformDiscountCents']===null
    &&$single['orderPricing']['storePriceCents']===null&&!isset($single['platformCreditCents']),'HB komisyon matrahı mağaza fiyatını veya kampanyayı kimin ödediğini kanıtlamaz.');
$multi=ProfitabilityFinancialEvidence::hepsiburada(sourceOrder(2066120,2),[['id'=>'1','quantity'=>2,'commission'=>['currency'=>'TRY','amount'=>1318.80],
    'commissionRate'=>12,'deptorDifferenceAmount'=>659.40,'totalPrice'=>['currency'=>'TRY','amount'=>20661.20],'vatRate'=>20]]);
verify($multi['commissionNetCents']===263760&&$multi['commissionTaxCents']===null,'HB ürün başı komisyon iki adette bir kez çarpılır; ürün KDVsi ücret KDVsi sayılamaz.');
verify(ProfitabilityFinancialEvidence::seal([...$order,'returnCents'=>1],[])===null,'İade edilmiş sipariş eski satış ekstresiyle eşleşemez.');
$row=ProfitabilityCalculator::row($snapshot,$order,[],[],$proof);
verify($row['afterDeductionsCents']===2466782&&$row['purchaseCents']===null&&$row['profitCents']===null,'Eksik alış maliyeti net alacağı gizlemez ve kâr uydurulmaz.');
$stale=ProfitabilityCalculator::row($snapshot,$order,[],[],[...$proof,'checkedAt'=>gmdate('c',time()-90000)]);
verify($stale['status']==='statement_stale','Eski ekstre güncel olarak sunulamaz.');
$changed=[...$order,'products'=>[[...$order['products'][0],'sku'=>'OTHER']]];
verify(ProfitabilityCalculator::row($snapshot,$changed,[],[],$proof)['afterDeductionsCents']===null,'Kalem kimliği değişirse eski mali eşleşme kullanılamaz.');
$unknown=ProfitabilityCalculator::row($snapshot,$order,[]);
verify($unknown['commissionCents']===null&&$unknown['shippingCents']===null&&$unknown['afterDeductionsCents']===null,'Sabit yüzde veya kargo varsayımı yok.');
$requests=[];
$client=new FinanceClient(static function($method,$url,$headers,$body,$deadline)use(&$requests,$raw){
    $requests[]=json_decode($body,true);return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['transactionList'=>[$raw]]])];});
verify(count($client->pazaramaOrder('token','875601548',time()+5))===1&&$requests[0]['orderId']===875601548,'Sipariş finans filtresi ayrı okuma yöntemi kullanır.');
try{(new FinanceClient(static fn()=>['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['transactionList'=>[[...$raw,'orderId'=>99]]]])]))
    ->pazaramaOrder('token','875601548',time()+5);throw new RuntimeException('API filtresini yok saydı.');}catch(SyncFailure $e){verify($e->httpStatus===422,'Filtre yanıt hatası.');}
echo "Gerçek HB ve Pazarama örnekleri, KDV/adet, eksik/tekrarlı/iade hareketleri ve sipariş kanıtı doğrulandı.\n";

