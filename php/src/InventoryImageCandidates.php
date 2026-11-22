<?php
declare(strict_types=1);
namespace Omnigo;

/** Retain only image URLs from the exact product/variant row, never descriptions. */
final class InventoryImageCandidates
{
    public static function valid(mixed $url):bool
    {
        if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f]/',$url))return false;
        $parts=parse_url($url);
        return $parts!==false&&($parts['scheme']??'')==='https'&&!isset($parts['user'])&&!isset($parts['pass'])&&(!isset($parts['port'])||$parts['port']===443)&&isset($parts['host'])&&!filter_var($parts['host'],FILTER_VALIDATE_IP)&&str_contains($parts['host'],'.');
    }
    public static function extract(array $row):array
    {
        $urls=[];
        $collect=static function(mixed $value)use(&$urls,&$collect):void{
            if(count($urls)>=5)return;
            if(is_string($value)){if(self::valid($value))$urls[]=$value;return;}
            if(!is_array($value))return;
            if(array_is_list($value)){foreach(array_slice($value,0,10) as $item)$collect($item);return;}
            foreach(['url','imageUrl','imageURL','resimUrl','resimAdresi','originalUrl','largeUrl'] as $field)if(isset($value[$field]))$collect($value[$field]);
        };
        foreach(['imageUrls','images','imageUrl','image','productImage','product_images','product_image','resimler','resimListesi','resim'] as $field)if(isset($row[$field]))$collect($row[$field]);
        return array_slice(array_values(array_unique($urls)),0,5);
    }
}
