<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{TrendyolStockGateway,N11StockGateway,MarketplaceStockFailure};

$checks=0;
function check(bool $condition,string $label):void{
    global $checks;if(!$condition)throw new RuntimeException($label);$checks++;
}
function blocked(callable $call,string $label):void{
    try{$call();}catch(DomainException|MarketplaceStockFailure){check(true,$label);return;}
    throw new RuntimeException($label);
}
final class StockFixtureHttp
{
    public array $response=[],$requests=[];
    public int $status=200,$retryAfter=25;
    public function call(string $method,string $url,array $headers,?string $json,int $deadline):array{
        $this->requests[]=[$method,$url,$headers,$json===null?null:json_decode($json,true,64,JSON_THROW_ON_ERROR)];
        return ['status'=>$this->status,'body'=>json_encode($this->response,JSON_THROW_ON_ERROR),'retryAfter'=>$this->retryAfter];
    }
    public function last():array{return end($this->requests);}
}

$deadline=time()+60;$sku='STK/İ 01';$barcode='01234X';
$tr=['enabled'=>true,'supplierId'=>'101','apiKey'=>'test-key','apiSecret'=>'test-secret'];
$trHttp=new StockFixtureHttp();$trGateway=new TrendyolStockGateway($trHttp->call(...));
$variant=['supplierId'=>101,'stockCode'=>$sku,'barcode'=>$barcode,'onSale'=>true,'stock'=>['quantity'=>4],'price'=>['salePrice'=>120.50]];
$product=['title'=>'Örnek ürün','variants'=>[$variant]];
$catalog=['totalElements'=>1,'totalPages'=>1,'page'=>0,'size'=>100,'content'=>[$product]];
$trHttp->response=$catalog;$listing=$trGateway->read($tr,$sku,$deadline);
[$method,$url,$headers]=$trHttp->last();parse_str(parse_url($url,PHP_URL_QUERY),$query);
check($method==='GET'&&str_starts_with($url,'https://apigw.trendyol.com/integration/product/sellers/101/products/approved?')&&$query['stockCode']===$sku&&$query['supplierId']==='101','Trendyol V2 mağaza ve tam kod sorgusu');
check(in_array('Authorization: Basic '.base64_encode('test-key:test-secret'),$headers,true)&&in_array('User-Agent: 101 - OmnigoGlobal',$headers,true),'Trendyol satıcı doğrulaması');
check($listing['quantity']===4&&$listing['saleActive']===true&&$listing['remoteIds']===[$barcode]&&$listing['merchantSku']===$sku,'Trendyol katalog kimliği ve satılabilir stok');
$id='c57e3453-2c00-11f0-aa3a-be9298facace-1746718627';$trHttp->response=['batchRequestId'=>$id];$receipt=$trGateway->submit($tr,$listing,0,$deadline);
[$method,$url,,$body]=$trHttp->last();
check($method==='POST'&&$url==='https://apigw.trendyol.com/integration/inventory/sellers/101/products/price-and-inventory'&&$body===['items'=>[['barcode'=>$barcode,'quantity'=>0]]],'Trendyol gerçek barkod ile sıfır stok; fiyat alanları yollanmaz');
check(strlen($receipt)<=100,'Makbuz veri tabanı sınırına sığmalı');
foreach([[2100000,21000],[1800099,20000]] as [$sale,$expectedList]){
    $trHttp->response=['batchRequestId'=>$id];
    $trGateway->submitPrice($tr,array_replace($listing,['listPriceCents'=>2000000]),$sale,$deadline);
    $sent=$trHttp->last()[3]['items'][0];
    check($sent===['barcode'=>$barcode,'salePrice'=>$sale/100,'listPrice'=>$expectedList],'Satış fiyatı listeyi yalnız gerektiğinde yükseltir; stok gönderilmez');
}
blocked(fn()=>$trGateway->submitPrice($tr,$listing,2100000,$deadline),'Kaynak liste fiyatı eksikse mevcut liste fiyatı ezilmez');
$batch=['batchRequestId'=>$id,'batchRequestType'=>'ProductInventoryUpdate','failedItemCount'=>0,'items'=>[['requestItem'=>['barcode'=>$barcode,'quantity'=>0],'status'=>'SUCCESS','failureReasons'=>[]]]];
$trHttp->response=$batch;
check($trGateway->status($tr,$receipt,$deadline)==='done'&&$trHttp->last()[1]==='https://apigw.trendyol.com/integration/product/sellers/101/products/batch-requests/'.$id,'Üst durum olmayan barkod bazlı Trendyol başarısı');
$trHttp->response['items'][0]['status']='IN_PROGRESS';check($trGateway->status($tr,$receipt,$deadline)==='pending','Trendyol sırada');
$trHttp->response['items'][0]['status']='FAILED';check($trGateway->status($tr,$receipt,$deadline)==='failed','Trendyol satır hatası');
$trHttp->response=$batch;$trHttp->response['items'][0]['requestItem']['barcode']='BASKA';
blocked(fn()=>$trGateway->status($tr,$receipt,$deadline),'Başka varyantın sonucu kabul edilmedi');
$trHttp->response=$batch;$trHttp->response['items'][0]['requestItem']['quantity']=1;
blocked(fn()=>$trGateway->status($tr,$receipt,$deadline),'Yanlış adetli Trendyol makbuzu');
$trHttp->response=$catalog;$trHttp->response['content'][0]['variants'][0]['stock']['quantity']=0;$trHttp->response['content'][0]['variants'][0]['onSale']=false;
$zero=$trGateway->read($tr,$sku,$deadline);check($zero['quantity']===0&&$zero['saleActive']===false&&$zero['remoteIds']===$listing['remoteIds'],'Trendyol sıfırlanan pasif ilan geri okundu');
blocked(fn()=>$trGateway->submit($tr,$zero,1,$deadline),'Pasif Trendyol ilanı değiştirilemez');
$trHttp->response=$catalog;$trHttp->response['content'][0]['variants'][]=[...$variant,'barcode'=>'ikinci'];
blocked(fn()=>$trGateway->read($tr,$sku,$deadline),'Aynı stockCode iki barkoda ait');
$trHttp->response=$catalog;$trHttp->response['content'][0]['variants'][0]['supplierId']=999;
blocked(fn()=>$trGateway->read($tr,$sku,$deadline),'Yabancı Trendyol mağazası');
$trHttp->response=$catalog;$trHttp->response['content'][0]['variants'][0]['barcode']=' '.$barcode;
blocked(fn()=>$trGateway->read($tr,$sku,$deadline),'Kaynak barkodu kırparak yazma kimliği oluşturulamaz');
$trHttp->response=$catalog;$trHttp->response['content'][0]['variants'][0]['stock']['quantity']=null;
blocked(fn()=>$trGateway->read($tr,$sku,$deadline),'Eksik adet sıfır gibi alınmaz');

$n11=['enabled'=>true,'apiKey'=>'test-key','apiSecret'=>'test-secret','sellerId'=>'103'];
$nHttp=new StockFixtureHttp();$nGateway=new N11StockGateway($nHttp->call(...));
$item=['n11ProductId'=>10203040506,'sellerId'=>103,'stockCode'=>$sku,'quantity'=>9,'sender'=>'SELLER','status'=>'Active','saleStatus'=>'On_Sale'];
$nPage=['content'=>[$item],'totalElements'=>1,'totalPages'=>1];$nHttp->response=$nPage;
$listing=$nGateway->read($n11,$sku,$deadline);[$method,$url,$headers]=$nHttp->last();parse_str(parse_url($url,PHP_URL_QUERY),$query);
check($method==='GET'&&str_starts_with($url,'https://api.n11.com/ms/product-query?')&&$query['sender']==='SELLER'&&$query['stockCode']===$sku,'n11 yalnız satıcı deposu ve tam stok kodu');
check(in_array('appKey: test-key',$headers,true)&&in_array('appSecret: test-secret',$headers,true),'n11 satıcı anahtarları');
check($listing['quantity']===9&&$listing['saleActive']===true&&$listing['remoteIds']===['10203040506']&&$listing['merchantSku']===$sku,'n11 ürün kimliği ve stok');
$nHttp->response=['id'=>362,'type'=>'SKU_UPDATE','status'=>'IN_QUEUE'];$nReceipt=$nGateway->submit($n11,$listing,0,$deadline);
[$method,$url,,$body]=$nHttp->last();
check($method==='POST'&&$url==='https://api.n11.com/ms/product/tasks/price-stock-update'&&$body===['payload'=>['integrator'=>'OmnigoGlobal','skus'=>[['stockCode'=>$sku,'quantity'=>0]]]],'n11 yalnız stok, döviz ve fiyat alanı yok');
$detail=['taskId'=>362,'status'=>'PROCESSED','skus'=>['totalElements'=>1,'content'=>[['taskId'=>362,'ownerId'=>103,'itemCode'=>$sku,'status'=>'SUCCESS','reasons'=>['Başarılı'],'sku'=>['stock'=>0]]]]];
$nHttp->response=['taskId'=>362,'status'=>'IN_QUEUE'];check($nGateway->status($n11,$nReceipt,$deadline)==='pending','n11 kuyruğa alındı henüz sonuç değil');
[$method,$url,,$body]=$nHttp->last();check($method==='POST'&&$url==='https://api.n11.com/ms/product/task-details/page-query'&&$body['taskId']===362,'n11 salt okunur görev sorgusunun POST biçimi');
$nHttp->response=$detail;check($nGateway->status($n11,$nReceipt,$deadline)==='done','n11 görev ve satır başarılı');
$nHttp->response['skus']['content'][0]['status']='FAIL';check($nGateway->status($n11,$nReceipt,$deadline)==='failed','n11 genel işlem tamamlandığı halde satır başarısız');
$nHttp->response=$detail;$nHttp->response['skus']['content'][0]['ownerId']=555;blocked(fn()=>$nGateway->status($n11,$nReceipt,$deadline),'n11 başka satıcının görev satırı');
$nHttp->response=$detail;$nHttp->response['skus']['content'][0]['itemCode']='S-OTHER';blocked(fn()=>$nGateway->status($n11,$nReceipt,$deadline),'n11 başka stok koduna ait görev');
$nHttp->response=$detail;$nHttp->response['skus']['content'][0]['sku']['stock']=7;blocked(fn()=>$nGateway->status($n11,$nReceipt,$deadline),'n11 başka adede ait görev');
$nHttp->response=$nPage;$nHttp->response['content'][0]['quantity']=0;$nHttp->response['content'][0]['saleStatus']='Off_Sale';
$zero=$nGateway->read($n11,$sku,$deadline);check($zero['quantity']===0&&$zero['saleActive']===false&&$zero['remoteIds']===$listing['remoteIds'],'n11 sıfır stok pasif ilan tekrar okundu');
blocked(fn()=>$nGateway->submit($n11,$zero,1,$deadline),'n11 pasif ilana stok yazılmaz');
$nHttp->response=$nPage;$nHttp->response['content'][0]['sender']='N11';blocked(fn()=>$nGateway->read($n11,$sku,$deadline),'n11 depo stoğuna yazılamaz');
$nHttp->response=$nPage;$nHttp->response['content'][0]['sellerId']=555;blocked(fn()=>$nGateway->read($n11,$sku,$deadline),'n11 yanlış mağaza');
$nHttp->response=$nPage;$nHttp->response['totalPages']=2;blocked(fn()=>$nGateway->read($n11,$sku,$deadline),'Belirsiz n11 ilanı');
$nHttp->response=$nPage;$nHttp->response['content'][0]['stockCode']=strtolower($sku);blocked(fn()=>$nGateway->read($n11,$sku,$deadline),'n11 kodu tam eşleşmeli');
$before=count($nHttp->requests);$unknown=$n11;unset($unknown['sellerId']);
blocked(fn()=>$nGateway->read($unknown,$sku,$deadline),'n11 mağaza kimliği bağımsız doğrulanmamış');
check(count($nHttp->requests)===$before,'Eksik mağaza eşlemesinde çağrı dahi yapılmaz');
$nHttp->status=503;
try{$nGateway->submit($n11,$listing,2,$deadline);throw new RuntimeException('Ağ hatası bekleniyordu');}catch(MarketplaceStockFailure $e){check($e->uncertain&&!$e->retryable,'Sonucu bilinmeyen stok çağrısı tekrar yollanmaz');}
try{$nGateway->status($n11,$nReceipt,$deadline);throw new RuntimeException('Ağ hatası bekleniyordu');}catch(MarketplaceStockFailure $e){check(!$e->uncertain&&$e->retryable,'POST ile yapılan n11 görev okuması yeniden denenebilir');}
$nHttp->status=429;try{$nGateway->submit($n11,$listing,2,$deadline);throw new RuntimeException('429 bekleniyordu');}catch(MarketplaceStockFailure $e){check(!$e->uncertain&&$e->retryable&&$e->retryAfter===25,'Oran sınırı ve Retry-After korundu');}
echo $checks." stok API sözleşmesi doğrulandı. Gerçek pazaryerine çağrı yapılmadı.\n";
