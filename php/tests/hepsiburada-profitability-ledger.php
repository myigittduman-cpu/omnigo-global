<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{HepsiburadaProfitabilityLedger,QuestionReadTransport,SyncFailure};

function checkLedger(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$merchant='11111111-1111-1111-1111-111111111111';
$number='1234567890';
$money=fn($v)=>['value'=>$v,'currencyCode'=>'949'];
$item=['merchantId'=>$merchant,'orderNumber'=>$number,'orderItemNumber'=>'000010','id'=>'posting-a','transactionType'=>'Commission',
    'transactionTypeCategory'=>'OrderBased','status'=>'Paid','dueDate'=>'2026-11-17T00:00:00','isIncome'=>false,
    'amount'=>$money(-3165.12),'netAmount'=>$money(-2637.60),'taxAmount'=>$money(-527.52)];
$fee=[...$item,'id'=>'posting-b','transactionType'=>'PaymentServiceCostReflection','amount'=>$money(-165.29),'netAmount'=>$money(-137.74),'taxAmount'=>$money(-27.55)];
$other=[...$item,'id'=>'posting-c','transactionType'=>'TotalPayment'];
$rows=[$fee,$item,$other];
$page=['count'=>3,'items'=>$rows];
$facts=HepsiburadaProfitabilityLedger::items($page,$merchant,$number);
checkLedger(count($facts)===2&&$facts[0]===['type'=>'Commission','status'=>'Paid','dueDate'=>'2026-11-17',
    'grossCents'=>-316512,'netCents'=>-263760,'taxCents'=>-52752]&&$facts[1]['grossCents']===-16529,
    'Ekstredeki muhasebe eksi işareti ve KDV kuruşları korunmalı, hesap transferi eklenmemeli.');
$credit=[...$item,'isIncome'=>true,'transactionType'=>'CampaignDiscount','status'=>'WillBePaid',
    'amount'=>$money(1318.8),'netAmount'=>$money(1099),'taxAmount'=>$money(219.8)];
checkLedger(HepsiburadaProfitabilityLedger::items(['count'=>1,'items'=>[$credit]],$merchant,$number)[0]['grossCents']===131880,
    'Kampanya alacağı ve beklenen durum korunmalı.');
foreach([['count'=>2,'items'=>[$item]],['count'=>2,'items'=>[$item,$item]],['count'=>101,'items'=>array_fill(0,101,$item)],
    ['count'=>1,'items'=>[[...$item,'orderNumber'=>'123']]],['count'=>1,'items'=>[[...$item,'merchantId'=>'different']]],
    ['count'=>1,'items'=>[[...$item,'taxAmount'=>$money(-1)]]],['count'=>1,'items'=>[[...$item,'netAmount'=>null]]],
    ['count'=>1,'items'=>[[...$item,'amount'=>['currencyCode'=>'EUR','value'=>-3165.12]]]],
    ['count'=>1,'items'=>[[...$item,'isIncome'=>true]]],['count'=>1,'items'=>[[...$item,'orderItemNumber'=>null]]]] as $bad){
    checkLedger(HepsiburadaProfitabilityLedger::items($bad,$merchant,$number)===[],'Eksik veya farklı kapsamın tutarı kabul edildi.');
}
$settings=['merchantId'=>$merchant,'username'=>'sample','serviceKey'=>'example','userAgent'=>'sample'];
$checkpoint=null;$counter=0;$orderReads=0;
$transport=new QuestionReadTransport(function($url,$headers,$deadline,$body)use(&$counter,$number,$page){
    $counter++;
    parse_str(parse_url($url,PHP_URL_QUERY),$q);
    checkLedger(parse_url($url,PHP_URL_HOST)==='mpfinance-external.hepsiburada.com'&&$q===['OrderNumber'=>$number,'Offset'=>'0','Limit'=>'100']
        &&$body===null,'Geniş dönem yerine yalnız ilgili sipariş için GET kullanılmalı.');
    return ['status'=>200,'body'=>json_encode($page,JSON_THROW_ON_ERROR)];
});
$order=['orderedAt'=>1,'salesCents'=>111];
$source=new HepsiburadaProfitabilityLedger($transport);
$readOrder=function()use(&$orderReads,$order,$number){$orderReads++;return [$number=>$order];};
$first=$source->observe($settings,[$number=>true],time()+10,$checkpoint,$readOrder);
checkLedger($orderReads===1&&$counter===0&&$checkpoint['order']===$order&&$first[$number]===$order,'Sipariş ve muhasebe çağrıları ayrı kuyruk dilimlerinde çalışmalı.');
$second=$source->observe($settings,[$number=>true],time()+10,$checkpoint,$readOrder);
checkLedger($checkpoint===null&&$orderReads===1&&$counter===1&&$second[$number]['statementItems']===$facts
    &&!isset($second[$number]['afterDeductionsCents']),'Ekstre siparişe eklenmeli; genel kalan üretilmemeli.');
foreach([403,429] as $status){
    $failing=new HepsiburadaProfitabilityLedger(new QuestionReadTransport(fn()=>['status'=>$status,'retryAfter'=>75,'body'=>'']));
    $failing->observe($settings,[$number=>true],time()+5,$checkpoint,$readOrder);
    try{
        $base=$failing->observe($settings,[$number=>true],time()+5,$checkpoint,$readOrder);
        checkLedger($status===403&&$base[$number]===$order&&$checkpoint===null,'Muhasebe erişimi eksikse sipariş kanıtı yayımlanmalı.');
    }catch(SyncFailure $e){
        checkLedger($status===429&&$e->retryAfter===75&&$checkpoint!==null,'Kota bekleme süresi korunmalı.');
        $checkpoint['startedAt']=time()-901;
        checkLedger($failing->observe($settings,[$number=>true],time()+5,$checkpoint,$readOrder)[$number]===$order
            &&$checkpoint===null,'Muhasebe sorunu diğer siparişleri sürekli bekletmemeli.');
    }
}
echo "Hepsiburada sipariş ekstresi, KDV ve kuyruk kontrolleri geçti.\n";
