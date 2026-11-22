<?php
declare(strict_types=1);
namespace Omnigo;

final class N11StockGateway implements MarketplaceStockGateway
{
    private const BASE='https://api.n11.com/ms/';
    private StockGatewayHttp $http;
    public function __construct(?\Closure $transport=null){$this->http=new StockGatewayHttp($transport);}

    public function read(array $configuration,string $sku,int $deadline):array
    {
        [$seller,$headers]=$this->connection($configuration);
        $sku=StockGatewayReceipt::code($sku);
        $query=http_build_query(['stockCode'=>$sku,'sender'=>'SELLER','page'=>0,'size'=>100]);
        $data=$this->http->request('GET',self::BASE.'product-query?'.$query,$headers,null,$deadline);
        $rows=$data['content']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)!==1||($data['totalElements']??null)!==1||($data['totalPages']??null)!==1)throw new \DomainException('n11 stok kodu tek bir satıcı ilanıyla eşleşmiyor.');
        $row=$rows[0];
        if(($row['sender']??null)!=='SELLER'||($row['stockCode']??null)!==$sku||StockGatewayReceipt::seller($row['sellerId']??null)!==$seller)throw new \DomainException('n11 ilanı seçilen mağaza ve satıcı deposuyla eşleşmiyor.');
        $product=$row['n11ProductId']??null;
        if((!is_int($product)&&!is_string($product))||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$product))throw new MarketplaceStockFailure();
        return ['merchantSku'=>$sku,'quantity'=>StockGatewayReceipt::quantity($row['quantity']??null),'remoteIds'=>[(string)$product],
            'saleActive'=>InventorySaleStatus::fromSource('n11',$row),'sellerId'=>$seller,
            'listPriceCents'=>MarketplacePrice::cents($row['listPrice']??null),'salePriceCents'=>MarketplacePrice::cents($row['salePrice']??null)];
    }
    public function submitPrice(array $configuration,array $listing,int $price,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        if(($listing['sellerId']??null)!==$seller||!is_int($listing['listPriceCents']??null))throw new \DomainException('n11 liste fiyatı veya satıcı doğrulanamadı.');
        $item=['stockCode'=>StockGatewayReceipt::code($listing['merchantSku']),'salePrice'=>$price/100,'listPrice'=>max($listing['listPriceCents'],$price)/100];
        $data=$this->http->request('POST',self::BASE.'product/tasks/price-stock-update',$headers,['payload'=>['integrator'=>'OmnigoGlobal','skus'=>[$item]]],$deadline,true);
        if(($data['status']??null)==='REJECT')throw new \DomainException('n11 fiyat gönderimini reddetti.');
        if(($data['status']??null)!=='IN_QUEUE'||!ctype_digit((string)($data['id']??'')))throw new MarketplaceStockFailure(uncertain:true);
        return (string)$data['id'];
    }

    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        $quantity=StockGatewayReceipt::quantity($quantity);$sku=StockGatewayReceipt::code($listing['merchantSku']??null);
        if(($listing['saleActive']??null)!==true||($listing['sellerId']??null)!==$seller)throw new \DomainException('n11 mağazası veya satıştaki ilan doğrulanamadı.');
        $data=$this->http->request('POST',self::BASE.'product/tasks/price-stock-update',$headers,
            ['payload'=>['integrator'=>'OmnigoGlobal','skus'=>[['stockCode'=>$sku,'quantity'=>$quantity]]]],$deadline,true);
        if(($data['status']??null)==='REJECT')throw new \DomainException('n11 stok güncelleme talebini reddetti.');
        $id=$data['id']??null;
        if(($data['type']??null)!=='SKU_UPDATE'||($data['status']??null)!=='IN_QUEUE'||(!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id)||(string)(int)$id!==(string)$id)throw new MarketplaceStockFailure(uncertain:true);
        return StockGatewayReceipt::make((string)$id,$seller,$sku,$quantity);
    }

    public function status(array $configuration,string $receipt,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        [$id,$quantity,$tag]=StockGatewayReceipt::split($receipt);
        if((string)(int)$id!==$id||(int)$id<1)throw new \DomainException('n11 görev numarası geçersiz.');
        $data=$this->http->request('POST',self::BASE.'product/task-details/page-query',$headers,
            ['taskId'=>(int)$id,'pageable'=>['page'=>0,'size'=>100]],$deadline);
        if((string)($data['taskId']??'')!==$id)throw new MarketplaceStockFailure();
        if(($data['status']??null)==='IN_QUEUE')return 'pending';
        if(($data['status']??null)==='REJECT')return 'failed';
        $page=$data['skus']??[];$rows=$page['content']??null;
        if(($data['status']??null)!=='PROCESSED'||($page['totalElements']??null)!==1||!is_array($rows)||!array_is_list($rows)||count($rows)!==1)throw new MarketplaceStockFailure();
        $row=$rows[0];$sku=$row['itemCode']??null;
        if(!is_string($sku)||!hash_equals($tag,StockGatewayReceipt::tag($seller,$sku))||(string)($row['ownerId']??'')!==$seller||(string)($row['taskId']??'')!==$id)throw new MarketplaceStockFailure();
        if(($row['status']??null)==='FAIL')return 'failed';
        if(($row['status']??null)!=='SUCCESS'||(isset($row['sku']['stock'])&&$row['sku']['stock']!==$quantity))throw new MarketplaceStockFailure();
        return 'done';
    }

    private function connection(array $configuration):array
    {
        if(empty($configuration['enabled']))throw new \DomainException('n11 bağlantısı kapalı.');
        $seller=StockGatewayReceipt::seller($configuration['sellerId']??null);
        $credentials=N11Credentials::fromArray($configuration);
        return [$seller,['appKey: '.$credentials->apiKey,'appSecret: '.$credentials->apiSecret]];
    }
}
