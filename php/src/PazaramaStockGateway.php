<?php
declare(strict_types=1);
namespace Omnigo;

/** Pazarama updates refer to a barcode, never to the local merchant SKU. */
final class PazaramaStockGateway implements MarketplaceStockGateway
{
    private const BASE='https://isortagimapi.pazarama.com/';
    private const UUID='/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD';
    private StockGatewayHttp $http;
    private PazaramaAuthClient $auth;
    private array $session=[];

    public function __construct(?\Closure $transport=null,?PazaramaAuthClient $auth=null,private ?\Closure $writeGate=null)
    {
        $this->http=new StockGatewayHttp($transport);
        $this->auth=$auth??new PazaramaAuthClient();
    }

    public function read(array $configuration,string $sku,int $deadline,?string $barcode=null):array
    {
        $sku=StockGatewayReceipt::code($sku);
        $barcode=$this->barcode($barcode);
        [$seller,$headers]=$this->connection($configuration,$deadline);
        $page=$this->approved($headers,['code'=>$barcode],$deadline);
        $rows=$page['sellerProducts'];
        if(count($rows)!==1||$page['nextCursor']!==null||($rows[0]['code']??null)!==$barcode
            ||($rows[0]['stockCode']??null)!==$sku)throw new \DomainException('Pazarama barkodu ve stok kodu satıştaki tek bir ilanla eşleşmedi.');
        $row=$rows[0];
        if(isset($row['sellerId'])&&strcasecmp((string)$row['sellerId'],$seller)!==0)throw new \DomainException('Pazarama ilanı seçili satıcıyla eşleşmedi.');
        return ['merchantSku'=>$sku,'remoteIds'=>[$barcode],'saleActive'=>true,'sellerId'=>$seller,
            'quantity'=>StockGatewayReceipt::quantity($row['stockCount']??null),
            'salePriceCents'=>MarketplacePrice::cents($row['salePrice']??null),
            'listPriceCents'=>MarketplacePrice::cents($row['listPrice']??null)];
    }

    public function approvedPage(array $configuration,?string $cursor,int $deadline):array
    {
        [, $headers]=$this->connection($configuration,$deadline);
        $query=$cursor===null?['Size'=>100]:['Size'=>100,'Cursor'=>$cursor];
        return $this->approved($headers,$query,$deadline);
    }

    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string
    {
        $quantity=StockGatewayReceipt::quantity($quantity);
        return $this->submitItem($configuration,$listing,'stock',['stockCount'=>$quantity],$deadline,$quantity);
    }

    public function submitPrice(array $configuration,array $listing,int $price,int $deadline):string
    {
        $list=$listing['listPriceCents']??null;
        if(!is_int($list)||$list<1||$price<1||$price>$list)
            throw new \DomainException('Pazarama satış fiyatı, doğrulanan liste fiyatını aşamaz.');
        return $this->submitItem($configuration,$listing,'price',['listPrice'=>$list/100,'salePrice'=>$price/100],$deadline);
    }

    public function status(array $configuration,string $receipt,int $deadline):string
    {
        [$batch,$quantity,$tag]=StockGatewayReceipt::split($receipt);
        return $this->result($configuration,$batch,$tag,'stock',$quantity,$deadline);
    }

    public function priceStatus(array $configuration,string $receipt,int $price,int $deadline):string
    {
        [$batch,,$tag]=StockGatewayReceipt::split($receipt);
        return $this->result($configuration,$batch,$tag,'price',$price,$deadline);
    }

    private function submitItem(array $configuration,array $listing,string $kind,array $item,int $deadline,int $quantity=0):string
    {
        [$seller,$headers]=$this->connection($configuration,$deadline);
        if(($listing['saleActive']??null)!==true||($listing['sellerId']??null)!==$seller||count($listing['remoteIds']??[])!==1)
            throw new \DomainException('Pazarama satıcısı veya satıştaki ilan doğrulanamadı.');
        $barcode=$this->barcode($listing['remoteIds'][0]);
        $path=$kind==='stock'?'product/updateStock-v2':'product/updatePrice-v2';
        $send=fn()=>$this->http->request('POST',self::BASE.$path,$headers,['items'=>[['code'=>$barcode]+$item]],$deadline,true);
        $data=$this->writeGate?($this->writeGate)($deadline,$send):$send();
        if(($data['success']??null)===false)throw new \DomainException('Pazarama güncelleme isteğini reddetti.');
        $id=$data['data']??null;
        if(($data['success']??null)!==true||!is_string($id)||!preg_match(self::UUID,$id))throw new MarketplaceStockFailure(uncertain:true);
        return StockGatewayReceipt::make(strtolower($id),$seller,$barcode,$quantity);
    }

    private function result(array $configuration,string $batch,string $tag,string $kind,int $value,int $deadline):string
    {
        if(!preg_match(self::UUID,$batch))throw new \DomainException('Pazarama takip numarası geçersiz.');
        [$seller,$headers]=$this->connection($configuration,$deadline);
        $data=$this->http->request('GET',self::BASE.'listing-state/batch-id/'.$batch.'/lake-projections?page=1&pageSize=3000',$headers,null,$deadline);
        $page=$data['data']??null;$rows=$page['data']??null;
        if(($data['success']??null)!==true||!is_array($rows)||!array_is_list($rows))throw new MarketplaceStockFailure();
        if($rows===[])return 'pending';
        $row=$rows[0];
        if(!is_array($row)||!is_string($row['code']??null))throw new MarketplaceStockFailure();
        $barcode=$this->barcode($row['code']);
        if(count($rows)!==1||(int)($page['totalCount']??0)!==1||strcasecmp((string)($row['batchId']??''),$batch)!==0
            ||strcasecmp((string)($row['sellerId']??''),$seller)!==0||!hash_equals($tag,StockGatewayReceipt::tag($seller,$barcode)))
            throw new MarketplaceStockFailure();
        $state=$row['operationStatusText']??null;
        if(in_array($state,['İşleniyor','Onaya gönderildi'],true))return 'pending';
        if(in_array($state,['Hata oluştu','Tamamlanamadı'],true))return 'failed';
        $operation=$row[$kind]??null;
        if($state!=='Başarılı'||($operation['status']??null)!==0)throw new MarketplaceStockFailure();
        $confirmed=$kind==='stock'?($operation['stockCount']??null):MarketplacePrice::cents($operation['salePrice']??null);
        // The API example omits the stock count. The worker must still read the catalogue after a successful batch.
        if(($kind==='price'||$confirmed!==null)&&$confirmed!==$value)throw new MarketplaceStockFailure();
        return 'done';
    }

    private function barcode(mixed $value):string
    {
        $code=StockGatewayReceipt::code($value);
        if(mb_strlen($code)>100)throw new \DomainException('Pazarama barkodu doğrulanamadı.');
        return $code;
    }

    private function approved(array $headers,array $query,int $deadline):array
    {
        $data=$this->http->request('GET',self::BASE.'product/products/approved?'.http_build_query($query),$headers,null,$deadline);
        $page=$data['data']??null;
        if(($data['success']??null)!==true||!is_array($page)||!is_array($page['sellerProducts']??null)||!array_is_list($page['sellerProducts'])
            ||!array_key_exists('nextCursor',$page)||($page['nextCursor']!==null&&(!is_string($page['nextCursor'])||$page['nextCursor']===''||strlen($page['nextCursor'])>2048)))
            throw new MarketplaceStockFailure();
        return $page;
    }

    private function connection(array $configuration,int $deadline):array
    {
        if(empty($configuration['enabled']))throw new \DomainException('Pazarama bağlantısı kapalı.');
        $credentials=PazaramaCredentials::fromArray($configuration);
        $key=hash('sha256',$credentials->sellerId."\0".$credentials->apiKey."\0".$credentials->apiSecret);
        if(($this->session['key']??null)!==$key||($this->session['expiresAt']??0)<=time()+30){
            try{
                $token=$this->auth->authenticate($credentials,$deadline);
                PazaramaSellerIdentity::verify($token['accessToken'],$credentials->sellerId);
            }catch(SyncFailure $error){
                $status=$error->httpStatus;
                throw new MarketplaceStockFailure(retryable:$status===429||$status>=500,retryAfter:max(10,$error->retryAfter));
            }
            $this->session=['key'=>$key,'expiresAt'=>$token['expiresAt'],'headers'=>['Authorization: Bearer '.$token['accessToken']]];
        }
        return [$credentials->sellerId,$this->session['headers']];
    }
}
