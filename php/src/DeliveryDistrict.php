<?php
declare(strict_types=1);
namespace Omnigo;

/** Verify delivery locality against the named province, including older marketplace records. */
final class DeliveryDistrict
{
    private static function key(string $value): string
    {
        return strtr(mb_strtolower(strtr(trim($value),['İ'=>'i','I'=>'i']),'UTF-8'),[
            'ı'=>'i','ş'=>'s','ç'=>'c','ö'=>'o','ğ'=>'g','ü'=>'u','â'=>'a',
        ]);
    }

    public static function resolve(string $province,array $fields,string ...$addresses): string
    {
        return self::match($province,$fields,$addresses,true);
    }

    public static function verified(string $province,array $fields,string ...$addresses): string
    {
        if($province==='')return '';
        return self::match($province,$fields,$addresses,false);
    }

    private static function match(string $province,array $fields,array $addresses,bool $keepUnlisted): string
    {
        if(!isset(TurkeyDistricts::BY_PROVINCE[$province]))$province=DeliveryMapper::city($province);
        $names=TurkeyDistricts::BY_PROVINCE[$province]??null;
        $provinceKey=self::key($province);
        $supplied='';
        static $byName=[];
        if($names!==null&&!isset($byName[$province])){
            $byName[$province]=[];
            foreach(explode('|',$names) as $name)$byName[$province][self::key($name)]=$name;
        }
        $valid=$byName[$province]??[];
        foreach($fields as $field){
            $field=DeliveryMapper::text($field,100);
            $key=self::key($field);
            if($field===''||$key===$provinceKey)continue;
            if(isset($valid[$key]))return $keepUnlisted?$field:$valid[$key];
            if($supplied==='')$supplied=$field;
        }
        foreach($addresses as $address){
            // Match only a complete locality at the end. Street and neighbourhood names can also be districts.
            $parts=preg_split('/\s*[,;\/\r\n]+\s*|\b\d{5}\b/u',$address,-1,PREG_SPLIT_NO_EMPTY);
            foreach(array_reverse($parts?:[]) as $part){
                $key=self::key(trim($part," .-\t"));
                if($key===''||$key===$provinceKey||in_array($key,['turkiye','turkey','tr'],true))continue;
                if(isset($valid[$key]))return $valid[$key];
                break;
            }
        }
        return $keepUnlisted?$supplied:'';
    }
}
