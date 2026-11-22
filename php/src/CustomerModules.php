<?php
declare(strict_types=1);
namespace Omnigo;

/** Per-customer operational sections. Existing customers keep their current sections. */
final class CustomerModules
{
    public const DEFAULTS=['shipments'=>true,'deliveries'=>true];
    public static function validate(mixed $value): array
    {
        if($value===null)return self::DEFAULTS;
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||array_diff(array_keys($value),array_keys(self::DEFAULTS)))Http::fail(400,'Müşteri bölümlerini kontrol edin.');
        $result=self::DEFAULTS;
        foreach(self::DEFAULTS as $key=>$default){if(!array_key_exists($key,$value)||!is_bool($value[$key]))Http::fail(400,'Müşteri bölümleri için açık veya kapalı seçin.');$result[$key]=$value[$key];}
        return $result;
    }
    public static function read(Database $db,int $company): array
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        $profile=$row?json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR):[];
        return array_replace(self::DEFAULTS,array_intersect_key($profile['modules']??[],self::DEFAULTS));
    }
    public static function enabled(Database $db,int $company,string $module): bool{return self::read($db,$company)[$module]??false;}
    public static function requireEnabled(Database $db,int $company,string $module): void
    {
        if(!self::enabled($db,$company,$module))Http::fail(403,'Bu bölüm bu müşteri için etkinleştirilmedi.');
    }
}
