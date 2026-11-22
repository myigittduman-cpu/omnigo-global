<?php
declare(strict_types=1);
namespace Omnigo;

final class TrendyolStockGateway implements MarketplaceStockGateway
{
    private const BASE='https://apigw.trendyol.com/integration/';
    private StockGatewayHttp $http;
    public function __construct(?\Closure $transport=null){$this->http=new StockGatewayHttp($transport);}

    public function read(array $configuration,string $sku,int $deadline):array
    {
        [$seller,$headers]=$this->connection($configuration);
        $sku=StockGatewayReceipt::code($sku);
        $query=http_build_query(['supplierId'=>$seller,'stockCode'=>$sku,'size'=>100,'page'=>0]);
        $data=$this->http->request('GET',self::BASE.'product/sellers/'.$seller.'/products/approved?'.$query,$headers,null,$deadline);
        if(!is_array($data['content']??null)||!array_is_list($data['content'])||($data['totalPages']??null)!==1)throw new \DomainException('Trendyol ilanı tek katalog sayfasında doğrulanamadı.');
        $prices=[];
        foreach($data['content'] as $content){
            foreach($content['variants']??[] as $variant){
                if(StockGatewayReceipt::seller($variant['supplierId']??null)!==$seller)throw new \DomainException('Trendyol ilanı seçilen mağazayla eşleşmiyor.');
                // The display catalogue trims identifiers; a write must use the exact source bytes.
                StockGatewayReceipt::code($variant['stockCode']??null);
                StockGatewayReceipt::code($variant['barcode']??null);
                if(($variant['stockCode']??null)===$sku)$prices[$variant['barcode']]=$variant['price']??[];
            }
        }
        try{$page=TrendyolCatalogPage::normalize($data,0,0);}
        catch(SyncFailure){throw new MarketplaceStockFailure();}
        $matches=array_values(array_filter($page['items'],static fn(array $item):bool=>$item['sku']===$sku));
        if(count($matches)!==1)throw new \DomainException('Trendyol stok kodu tek bir onaylı varyantla eşleşmiyor.');
        $row=$matches[0];$barcode=StockGatewayReceipt::code($row['barcode']);
        return ['merchantSku'=>$sku,'barcode'=>$barcode,'quantity'=>StockGatewayReceipt::quantity($row['quantity']),
            'remoteIds'=>[$barcode],'saleActive'=>$row['saleActive'],'sellerId'=>$seller,
            'listPriceCents'=>self::priceCents($prices[$barcode]['listPrice']??null),
            'salePriceCents'=>self::priceCents($prices[$barcode]['salePrice']??null)];
    }

    private static function priceCents(mixed $amount):?int
    {
        if(!is_numeric($amount)||!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D',(string)$amount))return null;
        $parts=explode('.',(string)$amount);
        return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    }

    public function submitPrice(array $configuration,array $listing,int $salePriceCents,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        $barcode=StockGatewayReceipt::code($listing['barcode']??null);
        if(($listing['saleActive']??null)!==true||($listing['sellerId']??null)!==$seller||($listing['remoteIds']??null)!==[$barcode])
            throw new \DomainException('Fiyat için mağaza ve aktif varyant doğrulanamadı.');
        $listPrice=$listing['listPriceCents']??null;
        if(!is_int($listPrice)||$listPrice<0||$salePriceCents<1||$salePriceCents>999999999999)
            throw new \DomainException('Satış fiyatını veya pazaryerindeki liste fiyatını kontrol edin.');
        $item=['barcode'=>$barcode,'salePrice'=>$salePriceCents/100,'listPrice'=>max($listPrice,$salePriceCents)/100];
        $result=$this->http->request('POST',self::BASE.'inventory/sellers/'.$seller.'/products/price-and-inventory',
            $headers,['items'=>[$item]],$deadline,true);
        $receipt=$result['batchRequestId']??null;
        if(!is_string($receipt)||!preg_match('/^[a-zA-Z0-9-]{1,64}$/D',$receipt))throw new MarketplaceStockFailure(uncertain:true);
        return $receipt;
    }

    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        $quantity=StockGatewayReceipt::quantity($quantity);$barcode=StockGatewayReceipt::code($listing['barcode']??null);
        if(($listing['saleActive']??null)!==true||($listing['sellerId']??null)!==$seller||($listing['remoteIds']??[])!==[$barcode])throw new \DomainException('Trendyol mağazası veya satıştaki varyant doğrulanamadı.');
        $result=$this->http->request('POST',self::BASE.'inventory/sellers/'.$seller.'/products/price-and-inventory',$headers,
            ['items'=>[['barcode'=>$barcode,'quantity'=>$quantity]]],$deadline,true);
        if(!is_string($result['batchRequestId']??null))throw new MarketplaceStockFailure(uncertain:true);
        return StockGatewayReceipt::make($result['batchRequestId'],$seller,$barcode,$quantity);
    }

    public function status(array $configuration,string $receipt,int $deadline):string
    {
        [$seller,$headers]=$this->connection($configuration);
        [$id,$quantity,$tag]=StockGatewayReceipt::split($receipt);
        $data=$this->http->request('GET',self::BASE.'product/sellers/'.$seller.'/products/batch-requests/'.$id,$headers,null,$deadline);
        if(($data['batchRequestId']??null)!==$id||($data['batchRequestType']??null)!=='ProductInventoryUpdate')throw new MarketplaceStockFailure();
        $items=$data['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)!==1)throw new MarketplaceStockFailure();
        $item=$items[0];$request=$item['requestItem']??[];$barcode=$request['barcode']??null;
        if(!is_string($barcode)||!hash_equals($tag,StockGatewayReceipt::tag($seller,$barcode))||($request['quantity']??null)!==$quantity)throw new MarketplaceStockFailure();
        if(!empty($item['failureReasons'])||!empty($data['failedItemCount']))return 'failed';
        return match($item['status']??null){
            'SUCCESS'=>'done','FAILED'=>'failed','IN_PROGRESS'=>'pending',default=>throw new MarketplaceStockFailure(),
        };
    }

    private function connection(array $configuration):array
    {
        if(empty($configuration['enabled']))throw new \DomainException('Trendyol bağlantısı kapalı.');
        $seller=StockGatewayReceipt::seller($configuration['supplierId']??null);
        foreach(['apiKey','apiSecret'] as $key){
            if(!is_string($configuration[$key]??null)||!preg_match('/^[\x21-\x7e]{1,2048}$/D',$configuration[$key]))throw new \DomainException('Trendyol bağlantı bilgileri eksik.');
        }
        return [$seller,['User-Agent: '.$seller.' - OmnigoGlobal','Authorization: Basic '.base64_encode($configuration['apiKey'].':'.$configuration['apiSecret'])]];
    }
}
