<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{InventoryCatalogPage,MarketplaceStockFailure,MarketplaceStockPilot,PazaramaAuthClient,PazaramaStockGateway};

function must(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}
function rejects(callable $action):void
{
    try{$action();}
    catch(DomainException|MarketplaceStockFailure){return;}
    throw new RuntimeException('Geçersiz Pazarama işlemi engellenmedi.');
}

$seller='0b13187a-e535-44be-9530-5df03b447f26';
$other='118ade42-fb84-493e-8f73-1ce0c430c4d3';
$batch='f48e4ed1-642f-4766-b36e-472896439579';
$barcode='0123456789012';
$sku='TEST-ÜRÜN-1';
$config=['enabled'=>true,'sellerId'=>$seller,'apiKey'=>'fixture-api-key','apiSecret'=>'fixture-api-secret'];
$deadline=time()+90;
$token='header.'.rtrim(strtr(base64_encode(json_encode(['client_seller_id'=>$seller])),'+/','-_'),'=').'.signature';
$auth=new PazaramaAuthClient(static fn()=>['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>600])]);
$requests=[];$gateCalls=0;$httpStatus=200;
$item=['code'=>$barcode,'stockCode'=>$sku,'stockCount'=>4,'listPrice'=>175.00,'salePrice'=>149.95];
$response=['success'=>true,'data'=>['sellerProducts'=>[$item],'nextCursor'=>null]];
$gateway=new PazaramaStockGateway(
    static function($method,$url,$headers,$body)use(&$requests,&$response,&$httpStatus){
        $requests[]=[$method,parse_url($url,PHP_URL_PATH),parse_url($url,PHP_URL_QUERY),$body===null?null:json_decode($body,true)];
        return ['status'=>$httpStatus,'body'=>json_encode($response),'retryAfter'=>27];
    },
    $auth,
    static function($deadline,$send)use(&$gateCalls){$gateCalls++;return $send();},
);
$listing=$gateway->read($config,$sku,$deadline,$barcode);
must($listing['quantity']===4&&$listing['salePriceCents']===14995&&$listing['listPriceCents']===17500,'Güncel stok ve iki fiyat okunur.');
must(end($requests)===['GET','/product/products/approved','code='.$barcode,null],'Stok kodu yerine barkodla sorgulanır.');
$before=count($requests);
rejects(fn()=>$gateway->read([...$config,'sellerId'=>$other],$sku,$deadline,$barcode));
must(count($requests)===$before,'Kimliği farklı satıcı için katalog isteği gönderilmez.');
rejects(fn()=>$gateway->read($config,'FARKLI-STOK-KODU',$deadline,$barcode));
$gateway->approvedPage($config,'a+b/c=',$deadline);
must(end($requests)[2]==='Size=100&Cursor=a%2Bb%2Fc%3D','Katalog imleci bir değer olarak aktarılır.');

$legacy=['success'=>true,'data'=>[$item]];
must(InventoryCatalogPage::normalize('pazarama',$legacy,0)['items'][0]['saleActive']===null,'Eski katalog satılabilirlik kanıtı değildir.');
must(InventoryCatalogPage::normalize('pazarama',$legacy,0,1,true)['items'][0]['saleActive']===true,'Satıştaki ürünler kaynağı açık durumu doğrular.');

$response=['success'=>true,'data'=>$batch];
$stockReceipt=$gateway->submit($config,$listing,7,$deadline);
must(end($requests)===['POST','/product/updateStock-v2',null,['items'=>[['code'=>$barcode,'stockCount'=>7]]]],'Stok ayrı istekle gönderilir.');
$priceReceipt=$gateway->submitPrice($config,$listing,15999,$deadline);
must(end($requests)===['POST','/product/updatePrice-v2',null,['items'=>[['code'=>$barcode,'listPrice'=>175,'salePrice'=>159.99]]]],'Liste fiyatı korunur.');
rejects(fn()=>$gateway->submitPrice($config,$listing,17600,$deadline));
must($gateCalls===2,'Geçersiz fiyat gönderilmez.');
$httpStatus=429;
try{$gateway->submit($config,$listing,7,$deadline);throw new RuntimeException('Hız sınırı yakalanmadı.');}
catch(MarketplaceStockFailure $error){must($error->retryable&&!$error->uncertain&&$error->retryAfter===27,'Hız sınırı bekleme süresini korur.');}
$httpStatus=503;
try{$gateway->submit($config,$listing,7,$deadline);throw new RuntimeException('Belirsiz yanıt yakalanmadı.');}
catch(MarketplaceStockFailure $error){must(!$error->retryable&&$error->uncertain,'Belirsiz gönderim tekrarlanmaz.');}
$httpStatus=200;

$row=['batchId'=>$batch,'sellerId'=>$seller,'code'=>$barcode,'operationStatusText'=>'Onaya gönderildi','price'=>['status'=>0,'salePrice'=>159.99]];
$response=['success'=>true,'data'=>['data'=>[$row],'totalCount'=>1]];
must($gateway->priceStatus($config,$priceReceipt,15999,$deadline)==='pending','Onay kuyruğu başarı sayılmaz.');
$response['data']['data'][0]['operationStatusText']='Başarılı';
must($gateway->priceStatus($config,$priceReceipt,15999,$deadline)==='done','İşlenen fiyat doğrulanır.');
rejects(fn()=>$gateway->priceStatus($config,$priceReceipt,15998,$deadline));
$response['data']['data'][0]['stock']=['status'=>0,'stockCount'=>7];
must($gateway->status($config,$stockReceipt,$deadline)==='done','İşlenen stok doğrulanır.');
$response['data']['data'][0]['sellerId']=$other;
rejects(fn()=>$gateway->status($config,$stockReceipt,$deadline));
$response['data']['data'][0]['sellerId']=$seller;
$response['data']['data'][0]['operationStatusText']='Tamamlanamadı';
must($gateway->status($config,$stockReceipt,$deadline)==='failed','Pazaryeri reddi ayrı tanınır.');

$pilotFile=tempnam(sys_get_temp_dir(),'pazarama-test-');
try{
    file_put_contents($pilotFile,json_encode(['companies'=>[11=>hash('sha256','VİZYONA')],'pazaramaCompanies'=>[72=>hash('sha256','TERZİOĞLU SHOP')]]));
    $pilot=MarketplaceStockPilot::load($pilotFile);
    must(!$pilot->pazaramaEnabled(['id'=>11,'name'=>'VİZYONA']),'Genel yetki Pazarama gönderimini açmaz.');
    must($pilot->pazaramaEnabled(['id'=>72,'name'=>'TERZİOĞLU SHOP']),'Manuel mağaza ayrı eşleşir.');
    must(!$pilot->pazaramaEnabled(['id'=>73,'name'=>'TERZİOĞLU SHOP']),'İzin başka mağazaya taşınmaz.');
}finally{unlink($pilotFile);}
echo "Pazarama stok ve fiyat kontrolleri başarılı.\n";
