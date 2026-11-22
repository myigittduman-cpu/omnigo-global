<?php
declare(strict_types=1);
namespace Omnigo;

/** Display scope is tenant-specific. Once enabled, a city's existing records keep synchronising. */
final class CustomerCities
{
    public const DEFAULTS=['Ankara','Kırıkkale','Kırşehir','Nevşehir','Kayseri','Eskişehir'];
    public static function validate(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>81)Http::fail(400,'Teslimat şehirlerini kontrol edin.');
        foreach($value as $city)if(!is_string($city)||!in_array($city,TurkeyCities::ALL,true))Http::fail(400,'Listeden geçerli bir il seçin.');
        return array_values(array_unique($value));
    }
    public static function fromProfile(array $profile,bool $active=false): array
    {
        if(!$active&&($profile['shippingScope']['mode']??'')==='nationwide')return TurkeyCities::ALL;
        return $profile[$active?'activeDeliveryCities':'deliveryCities']??self::DEFAULTS;
    }
    public static function read(Database $db,int $company,bool $active=false): array
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        return self::fromProfile($row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[],$active);
    }
    public static function update(array $profile,array $selected): array
    {
        $profile['activeDeliveryCities']=array_values(array_unique([...self::fromProfile($profile,true),...$selected]));
        $profile['deliveryCities']=$selected;
        if(isset($profile['shippingScope'])){
            $profile['shippingScope']['mode']=count($selected)===81?'nationwide':'regional';
            if(count($selected)!==81)$profile['shippingScope']['regionalCities']=$selected;
        }
        return $profile;
    }
    public static function scope(Database $db,int $company,array $scope): array
    {
        [$sql,$params]=$scope;$cities=self::read($db,$company);
        if(!$cities)return [$sql.' AND 1=0',$params];
        return [$sql.' AND city IN ('.implode(',',array_fill(0,count($cities),'?')).')',[...$params,...$cities]];
    }
}
