<?php
declare(strict_types=1);
namespace Omnigo;

/** Normalize stock data and retain image candidates without any image I/O. */
final class InventoryCatalogPage
{
    public const LABELS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pazarama'=>'Pazarama','pttavm'=>'PTT AVM','koctas'=>'Koçtaş','trendyol'=>'Trendyol'];
    public const SUPPORTED=['hepsiburada','n11','idefix','pazarama','pttavm','koctas','trendyol'];
    public static function normalize(string $provider,array $response,int $page,?int $partition=null,bool $pazaramaApproved=false):array
    {
        if($provider==='trendyol')return TrendyolCatalogPage::normalize($response,$page,$partition??0);
        $rows=match($provider){'n11'=>$response['content']??null,'hepsiburada'=>$response['listings']??null,'pazarama'=>$response['data']??null,'idefix'=>$response['products']??null,'koctas'=>$response['offers']??null,'pttavm'=>array_is_list($response)?$response:null,default=>null};
        if(!is_array($rows)||!array_is_list($rows))throw new \DomainException('Ürün listesinin biçimi doğrulanamadı.');
        if($provider==='pazarama'&&($response['success']??false)!==true)throw new \DomainException('Ürün sorgusu tamamlanamadı.');
        $items=[];
        foreach($rows as $r){
            $item=match($provider){
                'n11'=>['id'=>(string)($r['n11ProductId']??''),'title'=>$r['title']??'','sku'=>$r['stockCode']??'','barcode'=>$r['barcode']??'','quantity'=>$r['quantity']??null],
                'hepsiburada'=>['id'=>(string)($r['listingId']??$r['hepsiburadaSku']??''),'title'=>trim((string)($r['productName']??$r['title']??''))?:'Stok kodu: '.($r['merchantSku']??$r['hepsiburadaSku']??''),'sku'=>$r['merchantSku']??'','barcode'=>'','quantity'=>$r['availableStock']??null],
                'pazarama'=>['id'=>(string)($r['code']??''),'title'=>$r['displayName']??$r['name']??'','sku'=>$r['stockCode']??'','barcode'=>$r['code']??'','quantity'=>$r['stockCount']??null],
                'idefix'=>['id'=>(string)($r['barcode']??''),'title'=>$r['title']??'','sku'=>$r['vendorStockCode']??'','barcode'=>$r['barcode']??'','quantity'=>$r['inventoryQuantity']??null,'model'=>$r['productMainId']??'','brand'=>$r['matchedProduct']['brand']??'','modelVerified'=>true],
                'koctas'=>['id'=>(string)($r['offer_id']??''),'title'=>$r['product_title']??'','sku'=>$r['shop_sku']??'','barcode'=>self::gtin($r['product_references']??[]),'quantity'=>$r['quantity']??null],
                'pttavm'=>['id'=>(string)($r['urunId']??''),'title'=>$r['urunAdi']??'','sku'=>$r['urunKodu']??'','barcode'=>($r['gtin']??'')?:($r['barkod']??''),'quantity'=>$r['miktar']??null],
            };
            $item['saleActive']=$provider==='pazarama'&&$pazaramaApproved?true:InventorySaleStatus::fromSource($provider,$r);
            $item['offerPriceCents']=InventoryCatalogOfferPrice::extract($provider,$r);
            $item['imageCandidates']=InventoryImageCandidates::extract($r);
            $item['imageBrand']=is_string($r['brandName']??null)?trim($r['brandName']):'';
            if($provider==='hepsiburada')$item['sourceCatalogId']=trim((string)($r['hepsiburadaSku']??''));
            if($provider==='koctas')$item['invoiceCatalogId']=ShipmentProductCode::normalize($r['product_sku']??null);
            $item['invoiceModel']=ShipmentSourceProduct::model($r);
            foreach(['id','title','sku','barcode'] as $field)$item[$field]=trim((string)$item[$field]);
            if($item['quantity']!==null){$q=filter_var($item['quantity'],FILTER_VALIDATE_INT);if($q===false||$q<0)throw new \DomainException('Kaynak stok adedi doğrulanamadı.');$item['quantity']=$q;}
            $item['aliases']=array_values(array_unique(array_filter([$item['sku'],$item['barcode'],(string)($r['hepsiburadaSku']??''),(string)($r['product_sku']??''),(string)($r['productMainId']??''),(string)($r['barkod']??'')])));
            // A parent stock quantity cannot represent several independent variants.
            if($provider==='pttavm'&&!empty($r['variantListesi'])){
                foreach($r['variantListesi'] as $variant){
                    $barcode=trim((string)($variant['variantBarkod']??''));if($barcode==='')throw new \DomainException('Varyant kimliği eksik.');
                    $quantity=filter_var($variant['miktar']??null,FILTER_VALIDATE_INT);if($quantity===false||$quantity<0)throw new \DomainException('Varyant stok adedi doğrulanamadı.');
                    $items[]=[...$item,'id'=>$item['id'].':'.$barcode,'barcode'=>$barcode,'sku'=>$item['sku'],'quantity'=>$quantity,'title'=>$item['title'].' '.trim(($variant['variant1Deger']??'').' '.($variant['variant2Deger']??'')),'aliases'=>[$item['sku']],'imageCandidates'=>InventoryImageCandidates::extract($variant),'offerPriceCents'=>null];
                }
            }else $items[]=$item;
        }
        $next=match($provider){
            'n11'=>$page+1<(int)($response['totalPages']??throw new \DomainException('Sayfa sayısı eksik.')),
            'hepsiburada'=>($page+1)*100<(int)($response['totalCount']??throw new \DomainException('Ürün sayısı eksik.')),
            'pazarama'=>($response['hasNextPage']??false)===true,
            'koctas'=>($page+1)*100<(int)($response['total_count']??throw new \DomainException('Ürün sayısı eksik.')),
            // The idefix pool endpoint has no total: continue through its final empty page.
            'idefix'=>count($rows)>0,
            'pttavm'=>count($rows)>0,
            default=>false,
        };
        $totalPages=match($provider){
            'n11'=>max(1,(int)$response['totalPages']),
            'hepsiburada'=>max(1,(int)ceil((int)$response['totalCount']/100)),
            'koctas'=>max(1,(int)ceil((int)$response['total_count']/100)),
            default=>null,
        };
        $result=['items'=>$items,'next'=>$next,'totalPages'=>$totalPages];
        if($provider==='pttavm'){
            $accepted=array_filter($items,static fn(array $item):bool=>
                strtolower($item['title'])!=='deneme'||strtolower($item['sku'])!=='deneme'||strtolower($item['barcode'])!=='deneme');
            if(count($accepted)!==count($items)){
                // Cursors track the original provider page, including intentionally skipped rows.
                $result['sourceIds']=array_column($items,'id');
                $result['items']=array_values($accepted);
            }
        }
        return $result;
    }
    private static function gtin(array $refs):string
    {
        foreach($refs as $ref)if(in_array(strtoupper((string)($ref['reference_type']??'')),['EAN','GTIN','UPC'],true)&&InventoryCatalog::validGtin((string)($ref['reference']??'')))return (string)$ref['reference'];
        return '';
    }
}
