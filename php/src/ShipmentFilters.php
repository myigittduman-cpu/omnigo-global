<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentFilters
{
    public const DEFAULTS=['invoiced'=>'','cancelled'=>'','shipped'=>'','rowStatus'=>'','customerType'=>'','dateFrom'=>'','dateTo'=>''];
    public static function parse(mixed $value): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||array_diff(array_keys($value),array_keys(self::DEFAULTS)))Http::fail(400,'Filtre seçimi geçersiz.');
        $filters=[...self::DEFAULTS,...$value];
        foreach($filters as $field=>$selected){
            if(in_array($field,['dateFrom','dateTo'],true)){
                if(!is_string($selected))Http::fail(400,'Tarih filtresi geçersiz.');
                if($selected!==''){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$selected,new \DateTimeZone('Europe/Istanbul'));if(!$date||$date->format('Y-m-d')!==$selected)Http::fail(400,'Tarih filtresi geçersiz.');}
                continue;
            }
            $allowed=$field==='rowStatus'?[...ShipmentStatus::VALUES,'none']:($field==='customerType'?['','Bireysel','Kurumsal']:['','yes','no']);
            if(!in_array($selected,$allowed,true))Http::fail(400,'Filtre seçimi geçersiz.');
        }
        if($filters['dateFrom']!==''&&$filters['dateTo']!==''&&$filters['dateFrom']>$filters['dateTo'])Http::fail(400,'Başlangıç tarihi bitiş tarihinden sonra olamaz.');
        if($filters['rowStatus']==='delivered')$filters['rowStatus']='completed';
        return $filters;
    }
    public static function predicate(array $filters,bool $storedStatus=true,bool $storedShipped=true): array
    {
        $sql='';$params=[];
        if($storedStatus&&$filters['rowStatus']!==''){$sql.=' AND '.ShipmentStatus::SQL.'=?';$params[]=$filters['rowStatus']==='none'?'':$filters['rowStatus'];}
        foreach(['invoiced','cancelled','shipped'] as $field)if($filters[$field]!==''&&($field!=='shipped'||$storedShipped)){$sql.=" AND $field=?";$params[]=$filters[$field]==='yes'?1:0;}
        foreach(['dateFrom','dateTo'] as $field)if($filters[$field]!==''&&($field!=='shipped'||$storedShipped)){
            $date=new \DateTimeImmutable($filters[$field],new \DateTimeZone('Europe/Istanbul'));
            if($field==='dateTo')$date=$date->modify('+1 day');
            $sql.=$field==='dateFrom'?' AND ordered_at>=?':' AND ordered_at<?';
            $params[]=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        return [$sql,$params];
    }
    public static function matchesStatus(array $item,array $filters): bool
    {
        return ($filters['shipped']===''||$item['shipped']===($filters['shipped']==='yes'))
            &&($filters['rowStatus']===''||($item['displayRowStatus']??$item['rowStatus'])===($filters['rowStatus']==='none'?'':$filters['rowStatus']));
    }
}
