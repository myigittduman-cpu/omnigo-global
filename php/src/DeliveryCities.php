<?php
declare(strict_types=1);
namespace Omnigo;

/** Optional multi-city scope; legacy single-city requests retain their behavior. */
final class DeliveryCities
{
    public static function parse(mixed $value): ?array
    {
        if($value===null)return null;
        if(!is_array($value)||!array_is_list($value)||!$value||count($value)>count(DeliveryMapper::CITIES))Http::fail(400,'Teslimat filtresini kontrol edin.');
        foreach($value as $city)if(!is_string($city)||!in_array($city,DeliveryMapper::CITIES,true))Http::fail(400,'Teslimat filtresini kontrol edin.');
        $ordered=[...CustomerCities::DEFAULTS,...array_diff(DeliveryMapper::CITIES,CustomerCities::DEFAULTS)];
        return array_values(array_intersect($ordered,$value));
    }
}
