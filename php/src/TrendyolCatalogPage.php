<?php
declare(strict_types=1);
namespace Omnigo;

/** Product V2 content pages contain independent merchant barcode variants. */
final class TrendyolCatalogPage
{
    public static function normalize(array $response,int $page,int $partition):array
    {
        if(!in_array($partition,[0,1],true))throw new SyncFailure(422);
        $rows=$response['content']??null;
        $total=self::number($response['totalElements']??null);$pages=self::number($response['totalPages']??null);$size=self::number($response['size']??null);
        if(!is_array($rows)||!array_is_list($rows)||$size<1||$size>100||count($rows)>$size||$pages!==(int)ceil($total/$size))throw new SyncFailure(422);
        if($page<100&&self::number($response['page']??null)!==$page)throw new SyncFailure(422);
        if(count($rows)!==max(0,min($size,$total-$page*$size)))throw new SyncFailure(409);
        $items=[];$seen=[];
        foreach($rows as $content){
            if(!is_array($content))throw new SyncFailure(422);
            $title=self::text($content['title']??null,500);$brand=is_array($content['brand']??null)?self::text($content['brand']['name']??'',180,false):'';
            $variants=$partition===0?($content['variants']??null):[$content];
            if(!is_array($variants)||!array_is_list($variants)||!$variants)throw new SyncFailure(422);
            foreach($variants as $variant){
                if(!is_array($variant))throw new SyncFailure(422);
                $barcode=self::text($variant['barcode']??null,180);$sku=self::text($variant['stockCode']??null,180);
                if(isset($seen[$barcode]))throw new SyncFailure(409);$seen[$barcode]=true;
                $quantity=$partition===0?($variant['stock']['quantity']??null):($variant['quantity']??null);
                $quantity=$quantity===null?null:self::number($quantity);
                if($quantity!==null&&$quantity>100000000)throw new SyncFailure(422);
                $sale=$partition===1?false:self::saleStatus($variant);
                $imageRow=$partition===1?['images'=>$content['media']??[]]:$content;
                $images=InventoryImageCandidates::extract($imageRow);
                $items[]=['id'=>$barcode,'title'=>$title,'sku'=>$sku,'barcode'=>$barcode,'quantity'=>$quantity,'saleActive'=>$sale,
                    'offerPriceCents'=>$partition===0?MarketplacePrice::cents($variant['price']['salePrice']??null):null,
                    'brand'=>$brand,'model'=>'','modelVerified'=>false,'imageCandidates'=>$images,'imageBrand'=>$brand,
                    'invoiceModel'=>ShipmentSourceProduct::model($variant)?:ShipmentSourceProduct::model($content),
                    'aliases'=>[$sku,$barcode]];
            }
        }
        $next=$page+1<$pages;$cursor=$response['nextPageToken']??null;
        if($cursor!==null&&(!is_string($cursor)||$cursor===''||strlen($cursor)>8192))throw new SyncFailure(422);
        if($next&&$page>=99&&$cursor===null)throw new SyncFailure(422);
        return ['items'=>$items,'next'=>$next,'totalPages'=>max(1,$pages),'cursor'=>$cursor,'sourceCount'=>count($rows),'sourceTotal'=>$total];
    }

    public static function number(mixed $value):int
    {
        if(!is_int($value)&&!(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value)))throw new SyncFailure(422);
        if((int)$value<0)throw new SyncFailure(422);return (int)$value;
    }
    private static function text(mixed $value,int $limit,bool $required=true):string
    {
        if(!is_string($value)||mb_strlen($value)>$limit||($required&&trim($value)===''))throw new SyncFailure(422);
        return trim($value);
    }
    private static function saleStatus(array $variant):?bool
    {
        foreach(['archived','locked','blacklisted','hasViolation'] as $flag){
            if(($variant[$flag]??null)===true)return false;
            if(array_key_exists($flag,$variant)&&!is_bool($variant[$flag]))return null;
        }
        return is_bool($variant['onSale']??null)?$variant['onSale']:null;
    }
}
