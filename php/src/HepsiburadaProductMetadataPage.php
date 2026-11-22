<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaProductMetadataPage
{
    public static function normalize(array $data,int $page):array
    {
        if(($data['success']??false)!==true||!is_array($data['data']??null)||!array_is_list($data['data'])||count($data['data'])>1000||(int)($data['number']??$page)!==$page)throw new \DomainException('Ürün bilgisi sayfası doğrulanamadı.');
        $items=[];
        foreach($data['data'] as $row){
            $title=self::text($row['productName']??'',500);$sku=self::text($row['merchantSku']??'',180);$hbSku=self::text($row['hbSku']??'',180);
            if($title===''||($sku===''&&$hbSku===''))continue;
            $images=InventoryImageCandidates::extract($row);
            foreach($row['baseAttributes']??[] as $attribute)if(preg_match('/^Image[1-5]$/D',(string)($attribute['name']??''))&&InventoryImageCandidates::valid($attribute['value']??null))$images[]=$attribute['value'];
            $items[]=['sku'=>$sku,'hbSku'=>$hbSku,'title'=>$title,'barcode'=>self::text($row['barcode']??'',32),'brand'=>self::text($row['brand']??'',180),'images'=>array_slice(array_values(array_unique($images)),0,5)];
        }
        if(isset($data['last'])&&is_bool($data['last']))$next=!$data['last'];
        elseif(isset($data['totalPages'])&&is_numeric($data['totalPages']))$next=$page+1<(int)$data['totalPages'];
        else throw new \DomainException('Ürün bilgisi sayfalaması doğrulanamadı.');
        if($next&&!$data['data'])throw new \DomainException('Ürün bilgisi sayfası boş; kontrol yeniden denenecek.');
        return ['items'=>$items,'next'=>$next,'fingerprint'=>hash('sha256',json_encode(array_map(fn($i)=>[$i['sku'],$i['hbSku']],$items),JSON_THROW_ON_ERROR))];
    }
    private static function text(mixed $value,int $limit):string{return is_string($value)&&mb_strlen(trim($value))<=$limit?trim($value):'';}
}
