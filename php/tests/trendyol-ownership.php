<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{TrendyolReadClient,SyncFailure};

$checks=0;
function expectPage(array $packages,?int $expectedCode=null): void
{
    global $checks;
    $reader=new TrendyolReadClient(static fn()=>['status'=>200,'body'=>json_encode(['content'=>$packages,'hasMore'=>false],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]);
    try{
        $page=$reader->orders(['supplierId'=>'123','apiKey'=>'synthetic-key','apiSecret'=>'synthetic-secret'],100,200,null,time()+30);
        if($expectedCode!==null)throw new RuntimeException('Yabancı/geçersiz paket kabul edildi.');
        if($page['items']!==$packages)throw new RuntimeException('Kaynak sipariş bilgisi değiştirildi.');
    }catch(SyncFailure $error){
        if($expectedCode===null||$error->httpStatus!==$expectedCode)throw new RuntimeException('Beklenmeyen sahiplik sonucu.');
    }
    $checks++;
}

// The live response has a different package supplierId but every line belongs to the configured seller.
expectPage([['supplierId'=>456,'lines'=>[['sellerId'=>123],['sellerId'=>'123']]]]);
expectPage([['lines'=>[['sellerId'=>123]]]]);
expectPage([['supplierId'=>123,'lines'=>[['sellerId'=>123]]]]);
// A matching package ID cannot hide a foreign line, including the last package on a page.
expectPage([['supplierId'=>123,'lines'=>[['sellerId'=>456]]]],403);
expectPage([['supplierId'=>456,'lines'=>[['sellerId'=>123],['sellerId'=>456]]]],403);
expectPage([['supplierId'=>456,'lines'=>[['sellerId'=>123]]],['supplierId'=>123,'lines'=>[['sellerId'=>456]]]],403);
// Legacy responses only fall back when a line identity key is absent and package ownership is exact.
expectPage([['supplierId'=>123,'lines'=>[['quantity'=>1]]]]);
expectPage([['supplierId'=>'123','lines'=>[['sellerId'=>123],['quantity'=>1]]]]);
expectPage([['supplierId'=>456,'lines'=>[['sellerId'=>123],['quantity'=>1]]]],403);
expectPage([['lines'=>[['quantity'=>1]]]],403);
foreach([null,true,false,123.0,[],new stdClass(),'0123','123 ','1.23e2','123x',0,-123] as $invalid)
    expectPage([['supplierId'=>123,'lines'=>[['sellerId'=>$invalid]]]],403);
foreach([null,[],['named'=>['sellerId'=>123]],'invalid'] as $invalid)
    expectPage([['supplierId'=>123,'lines'=>$invalid]],422);
expectPage([['supplierId'=>123,'lines'=>['invalid']]],422);
expectPage([null],422);
expectPage([]);
echo $checks." Trendyol mağaza sahipliği kontrolü geçti.\n";
