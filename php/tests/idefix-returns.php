<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{IdefixReturnClient,IdefixReturns,QuestionReadTransport,SyncFailure};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$config=['vendorId'=>'123','apiKey'=>'test','apiSecret'=>'test'];
$claim=['customerName'=>'Saklanmamalı','orderNumber'=>'O1','items'=>[
 ['id'=>11,'orderLineId'=>21,'state'=>'approved','discountedTotalPrice'=>'123.45'],
 ['id'=>12,'orderLineId'=>22,'state'=>'ready','discountedTotalPrice'=>'999.00'],
 ['id'=>13,'orderLineId'=>23,'state'=>'decline','discountedTotalPrice'=>'999.00']]];
$reader=new IdefixReturnClient(new QuestionReadTransport(function($url,$headers,$deadline,$xml)use($claim){
 check($xml===null&&str_starts_with($url,'https://merchantapi.idefix.com/oms/123/claim-list?'),'Yalnız GET ve doğru satıcı');
 parse_str(parse_url($url,PHP_URL_QUERY),$q);check($q['limit']==='50'&&$q['startDate']==='2026/10/01 00:00:00','Tarih ve sayfa limiti');
 return ['status'=>200,'body'=>json_encode(['items'=>[$claim],'totalCount'=>1,'pageCount'=>1,'currentPage'=>1])];
}));
$result=$reader->page($config,'2026-10-01T00:00:00+03:00','2026-10-16T15:00:00+03:00',1,time()+10);
check(count($result['claims'])===1&&!str_contains(json_encode($result),'Saklanmamalı'),'Yalnız onaylı iade, kişisel veri yok');
$line=['lineId'=>'21','quantity'=>1,'grossCents'=>12345,'cancelCents'=>0,'returnCents'=>0,'netCents'=>12345,'netQuantity'=>1];
$orders=[['externalId'=>'O1','grossCents'=>12345,'cancelCents'=>0,'returnCents'=>0,'products'=>[$line]]];
$mapped=IdefixReturns::apply($orders,[...$result['claims'],...$result['claims']]);
check($mapped[0]['returnCents']===12345&&$mapped[0]['products'][0]['netQuantity']===0&&$mapped[0]['refundsVerified'],'Onaylı iade düşülür, aynı kayıt çift düşmez');
check(IdefixReturns::apply($mapped,$result['claims'])===$mapped,'Yeniden çalıştırma tutarı bir daha düşürmez');
$cancelled=$orders;$cancelled[0]['cancelCents']=12345;$cancelled[0]['products'][0]['cancelCents']=12345;
check(IdefixReturns::apply($cancelled,$result['claims'])[0]['returnCents']===0,'İptal ve iade aynı satırı iki kez düşmez');
$foreign=$result['claims'];$foreign[0]['orderNumber']='ESKI';check(IdefixReturns::apply($orders,$foreign)[0]['returnCents']===0,'Dönem dışı başka sipariş bu ayı azaltmaz');
foreach(['amount','line','duplicate'] as $case){$bad=$result['claims'];if($case==='amount')$bad[0]['amountCents']++;elseif($case==='line')$bad[0]['lineId']='unknown';else{$bad[]=$bad[0];$bad[1]['id']='99';}try{IdefixReturns::apply($orders,$bad);throw new RuntimeException('Tutarsız kaynak kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===422,'Tutarsız iade engellendi');}}
foreach([429,503] as $status){$failed=new IdefixReturnClient(new QuestionReadTransport(fn()=>['status'=>$status,'retryAfter'=>120]));try{$failed->page($config,'2026-10-01','2026-10-16',1,time()+10);throw new RuntimeException('Hata yutuldu');}catch(SyncFailure $e){check($e->httpStatus===$status,'Kuyruk için hata korunur');}}
echo "idefix iadeleri: onay filtresi, kuruş hesabı, tekilleştirme, iptal çakışması, GET ve kuyruk hataları geçti.\n";
