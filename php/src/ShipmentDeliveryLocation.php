<?php
declare(strict_types=1);
namespace Omnigo;

/** Recover the shipping locality from this row and its own linked delivery packages. */
final class ShipmentDeliveryLocation
{
    public static function fields(array $row,array $payload,array $packages): array
    {
        // The public row can contain the logistics bucket as a legacy display fallback.
        $province=self::province($payload['province']??'');
        $district=trim((string)($payload['district']??$row['district']??''));
        $address=(string)($payload['deliveryAddress']??$row['deliveryAddress']??'');
        $locked=$payload['source']['locked']??[];
        $sourceProvinces=[];
        if(!array_intersect(['province','district','deliveryAddress'],$locked))foreach($packages as $package){
            $details=$package['details']??[];
            $sourceProvince=self::province($package['city']??'')?:self::province($details['province']??'');
            if($sourceProvince===''||($province!==''&&$province!==$sourceProvince))continue;
            $sourceProvinces[$sourceProvince]??=[];
            $sourceDistrict=DeliveryDistrict::verified($sourceProvince,[$details['district']??'',$package['district']??''],
                (string)($details['deliveryAddress']??''),(string)($package['address']??''));
            if($sourceDistrict!=='')$sourceProvinces[$sourceProvince][$sourceDistrict]=true;
        }
        $sourceSuppliesProvince=$province===''&&count($sourceProvinces)===1;
        if($sourceSuppliesProvince)$province=(string)array_key_first($sourceProvinces);
        if($province===''&&!$sourceProvinces&&!in_array('province',$locked,true)){
            $parts=preg_split('/[,;\/\r\n]+/u',trim($address),-1,PREG_SPLIT_NO_EMPTY);
            $last=$parts?self::province(end($parts)):'';
            // Accept an address-only city when its own address ends with a matching district/city pair.
            if($last!==''&&DeliveryDistrict::verified($last,[],$address)!=='')$province=$last;
        }
        if(in_array('district',$locked,true)){
            // Preserve a personal correction, including an intentionally cleared field.
            return ['province'=>$province,'district'=>($province!==''&&self::province($district)===$province)?'':$district];
        }
        $sourceDistricts=$sourceProvinces[$province]??[];
        $sourceDistrict=count($sourceDistricts)===1?(string)array_key_first($sourceDistricts):'';
        $candidates=$sourceSuppliesProvince?[$sourceDistrict,$district]:[$district,$sourceDistrict];
        return ['province'=>$province,'district'=>DeliveryDistrict::verified($province,$candidates,$address)];
    }

    private static function province(mixed $raw): string
    {
        if($raw===null||$raw==='')return '';
        if(in_array($raw,TurkeyCities::ALL,true))return $raw;
        $name=DeliveryMapper::city($raw);
        return $name==='Diğer / Kontrol'?'':$name;
    }
}
