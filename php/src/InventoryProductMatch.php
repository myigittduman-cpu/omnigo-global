<?php
declare(strict_types=1);
namespace Omnigo;

/** Corroborate alternate seller codes using the full product title and its verified GTIN. */
final class InventoryProductMatch
{
    public static function name(string $title):string
    {
        $title=str_replace(['İ',"\u{0307}"],['I',''],mb_strtoupper(trim($title),'UTF-8'));
        $title=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$title)??'');
        return mb_strlen($title)>=12&&preg_match('/\p{L}/u',$title)&&!str_starts_with($title,'STOK KODU ')?$title:'';
    }

    public static function barcode(string $barcode):string
    {
        return InventoryCatalog::validGtin($barcode)?str_pad($barcode,14,'0',STR_PAD_LEFT):'';
    }

    public static function sameModel(string $first,string $second):bool
    {
        $first=self::name($first);$second=self::name($second);
        if($first===''||$second==='')return false;
        if($first===$second)return true;
        $models=static function(string $name):array{
            preg_match_all('/(?<!\S)[A-Z0-9]{5,}(?!\S)/',$name,$matches);
            $tokens=array_values(array_unique(array_filter($matches[0],static fn($token)=>ctype_digit($token)
                ?strlen($token)>=8&&!InventoryCatalog::validGtin($token):(bool)preg_match('/[0-9]/',$token))));
            sort($tokens);
            return $tokens;
        };
        $modelsInFirst=$models($first);
        return count($modelsInFirst)===1&&$modelsInFirst===$models($second);
    }

    public static function aliases(array $facts):array
    {
        $groups=[];
        foreach($facts as $key=>$item){
            if(count($item['barcodes'])!==1||count($item['names'])!==1)continue;
            $name=array_key_first($item['names']);
            $signature=array_key_first($item['barcodes']).':'.$name;
            $sku=$item['sku'];
            $model=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$sku)??'');
            $named=mb_strlen($model)>=5&&preg_match('/\d/',$model)&&str_contains(' '.$name.' ',' '.$model.' ');
            $groups[$signature][$key]=($named?2:0)+(ctype_digit($sku)?0:1);
        }
        $aliases=[];
        foreach($groups as $group){
            if(count($group)<2)continue;
            ksort($group);
            arsort($group,SORT_NUMERIC);
            $target=array_key_first($group);
            foreach(array_keys($group) as $key)if($key!==$target)$aliases[$key]=$target;
        }
        return $aliases;
    }

    /** Different validated identifiers and names may represent independent variants of one code. */
    public static function conflicts(array $facts):array
    {
        return array_filter($facts,static function(array $item):bool{
            if(count($item['barcodes'])<2||count($item['names'])<2)return false;
            $model=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$item['sku'])??'');
            foreach(array_keys($item['names']) as $name)
                if($model===''||!str_contains(' '.$name.' ',' '.$model.' '))return true;
            return false;
        });
    }
}
