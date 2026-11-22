<?php
declare(strict_types=1);
namespace Omnigo;

final class DeliveryRecordSelection
{
    public const LIMIT=5000;
    public const BODY_LIMIT=768*1024;

    public static function entries(mixed $selection): array
    {
        if(!is_array($selection)||!array_is_list($selection)||!$selection||count($selection)>self::LIMIT){
            Http::fail(400,'Bir işlemde 1–5000 teslimat kaydı seçin.');
        }
        $entries=[];
        foreach($selection as $entry){
            if($entry instanceof \stdClass)$entry=(array)$entry;
            if(!is_array($entry)||!is_int($entry['id']??null)||$entry['id']<1||!is_int($entry['version']??null)
                ||$entry['version']<1||isset($entries[$entry['id']])){
                Http::fail(400,'Sipariş seçimini kontrol edin.');
            }
            $entries[$entry['id']]=$entry;
        }
        return $entries;
    }

    public static function rows(Database $db,int $company,array $entries,bool $visibleOnly): array
    {
        $rows=[];
        foreach(array_chunk(array_keys($entries),200) as $batch){
            $found=$db->all('SELECT * FROM delivery_orders WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($batch),'?')).')',
                [$company,...$batch]);
            if(count($found)!==count($batch))Http::fail(404,'Sipariş bulunamadı.');
            foreach($found as $row){
                if($visibleOnly)MarketplaceVisibility::requireVisible($row['provider']);
                if((int)$row['version']!==$entries[$row['id']]['version'])Http::fail(409,'Bir sipariş değişmiş. Güncel listeyi kontrol edin.');
                $rows[$row['id']]=$row;
            }
        }
        return $rows;
    }
}
