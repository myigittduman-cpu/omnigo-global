<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{N11ReadClient,N11SalesMapper,ProfitabilityCommission,ProfitabilityCommissionSource,SyncFailure};

function expectN11Commission(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
}

function n11Observation(array $pages): ?array
{
    $reader=new N11ReadClient(static function(string $url) use ($pages): array {
        parse_str(parse_url($url,PHP_URL_QUERY),$request);
        $page=(int)$request['page'];
        return ['status'=>200,'body'=>json_encode(['content'=>$pages[$page],'totalPages'=>count($pages),'page'=>$page],JSON_THROW_ON_ERROR)];
    });
    $source=new ProfitabilityCommissionSource($reader);
    $result=$source->read('n11',['apiKey'=>'test','apiSecret'=>'test'],[['order_number'=>'100']],
        new DateTimeImmutable('2026-11-19',new DateTimeZone('Europe/Istanbul')),time()+30);
    return $result['100']??null;
}

$first=['orderLineId'=>101,'productId'=>201,'productName'=>'Birinci ürün','stockCode'=>'A',
    'orderItemLineItemStatusName'=>'Picking','quantity'=>1,'price'=>'1010.01','totalSellerDiscountPrice'=>'10.00',
    'totalMallDiscountPrice'=>'7.00','dueAmount'=>'993.01','sellerInvoiceAmount'=>'1000.01',
    'commissionRate'=>'5.00','sellerCampaignCommissionRate'=>0,'sellerCampaignCommissionDiscount'=>'0.00'];
$second=[...$first,'orderLineId'=>102,'productId'=>202,'productName'=>'İkinci ürün','stockCode'=>'B',
    'quantity'=>2,'price'=>'1005.01','sellerInvoiceAmount'=>'2000.02','dueAmount'=>'1993.02','commissionRate'=>'12.000'];
$package=['id'=>1,'orderNumber'=>'100','sellerId'=>7,'shipmentPackageStatus'=>'Picking',
    'packageHistories'=>[['status'=>'Created','createdDate'=>strtotime('2026-11-19T12:30:00+03:00')*1000]],
    'lines'=>[$first,$second]];
$mixed=n11Observation([[$package],[$package]]);
expectN11Commission(($mixed['commissionSource']??null)==='marketplace_line_rates','Satır oranlarının kaynağı belirtilmeli.');
expectN11Commission($mixed['salesCents']===300003 && $mixed['commissionCents']===29000
    && $mixed['salesCents']-$mixed['commissionCents']===271003 && $mixed['commissionRateBasisPoints']===967,
    'Tekrarlanan paket, satır adedi ve platform indirimi satıcı matrahından ikinci kez düşülmemeli.');
expectN11Commission(!isset($mixed['afterDeductionsCents']),'Satır komisyonu kesin hakediş oluşturamaz.');

$smallA=[...$first,'price'=>'0.05','totalSellerDiscountPrice'=>0,'sellerInvoiceAmount'=>'0.05','commissionRate'=>10];
$smallB=[...$smallA,'orderLineId'=>102,'productId'=>202,'stockCode'=>'B'];
$singleRate=n11Observation([[[...$package,'lines'=>[$smallA,$smallB]]]]);
expectN11Commission($singleRate['commissionCents']===1 && $singleRate['commissionSource']==='marketplace_rate',
    'Eşit oran tek seferde sipariş bazında yuvarlanmalı.');

foreach ([
    'oran eksik'=>['commissionRate'=>null], 'oran hassasiyeti'=>['commissionRate'=>'12.004'],
    'geçersiz oran'=>['commissionRate'=>101], 'kampanya verisi eksik'=>['sellerCampaignCommissionRate'=>null],
    'komisyon kampanyası'=>['sellerCampaignCommissionRate'=>'0.001'], 'komisyon indirimi'=>['sellerCampaignCommissionDiscount'=>4],
    'para birimi'=>['currencyCode'=>'EUR'], 'çelişkili fatura matrahı'=>['sellerInvoiceAmount'=>'1900.00'],
    'iptal satır'=>['orderItemLineItemStatusName'=>'Cancelled'],
] as $reason=>$delta) {
    $facts=n11Observation([[[...$package,'lines'=>[$first,[...$second,...$delta]]]]]);
    expectN11Commission(!isset($facts['commissionCents']),$reason.' bulunan siparişe komisyon yazılamaz.');
}
$conflict=[...$package,'lines'=>[$first,[...$second,'commissionRate'=>'15']]];
expectN11Commission(n11Observation([[$package],[$conflict]])===null,'Aynı satır için çelişen kayıtlarda işlem yapılmaz.');
try {
    $missing=$second;
    unset($missing['sellerInvoiceAmount']);
    n11Observation([[[...$package,'lines'=>[$first,$missing]]]]);
    throw new RuntimeException('Eksik fatura matrahında sipariş verisi doğrulanmamalı.');
} catch (SyncFailure $error) {
    expectN11Commission($error->httpStatus===422,'Matrahı eksik sipariş doğrulama hatası vermeli.');
}

$adjusted=N11SalesMapper::orders([N11SalesMapper::package($package)],[[
    'claimReturnId'=>301,'status'=>'APPROVED','quantity'=>1,'productId'=>'202','orderNumber'=>'100',
]],'2026-11-19T00:00:00+03:00','2026-11-19T23:59:59+03:00')[0];
$afterReturn=ProfitabilityCommission::resolve('n11',strtotime($adjusted['createdAt']),
    $adjusted['grossCents']-$adjusted['returnCents'],$adjusted['products'],[],$mixed);
expectN11Commission($afterReturn['commissionCents']===null,'İadeden sonra önceki komisyon kanıtı kullanılamaz.');
echo "n11 satır matrahları, farklı ve ortak oranlar, kampanya, çakışma, iptal ve iade kontrolleri geçti.\n";
