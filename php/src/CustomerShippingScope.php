<?php
declare(strict_types=1);
namespace Omnigo;

/** Geography belongs to the customer, independently of stock model or carrier. */
final class CustomerShippingScope
{
    public static function read(array $profile):array
    {
        $saved=$profile['shippingScope']??null;
        if(is_array($saved)&&in_array($saved['mode']??'', ['regional','nationwide'],true))return $saved;
        $cities=$profile['deliveryCities']??CustomerCities::DEFAULTS;
        return ['mode'=>count($cities)===81?'nationwide':'regional','regionalCities'=>$cities,'confirmed'=>false];
    }
    public static function validate(mixed $value):array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||!in_array($value['mode']??null,['regional','nationwide'],true))Http::fail(400,'Sevkiyat kapsamını seçin: Bölgesel Satış veya Tüm Türkiye.');
        $cities=CustomerCities::validate($value['regionalCities']??[]);
        if($value['mode']==='regional'&&!$cities)Http::fail(400,'Bölgesel satış için en az bir il seçin.');
        return ['mode'=>$value['mode'],'regionalCities'=>$cities,'confirmed'=>true];
    }
    public static function apply(array $profile,array $value):array
    {
        $scope=self::validate($value);
        $profile=CustomerCities::update($profile,$scope['mode']==='nationwide'?TurkeyCities::ALL:$scope['regionalCities']);
        $profile['shippingScope']=$scope;return $profile;
    }
}
