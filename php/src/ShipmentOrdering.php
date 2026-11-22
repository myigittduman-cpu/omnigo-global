<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentOrdering
{
    public static function timestamp(array $payload): string
    {
        $value=$payload['orderedAt']??null;
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value))throw new \InvalidArgumentException('Sipariş zamanı geçersiz.');
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    public static function backfill(Database $db,DeliveryCipher $cipher): int
    {
        $count=0;
        while($rows=$db->all("SELECT id,company_id,payload FROM shipment_entries WHERE ordered_at='' ORDER BY id LIMIT 250")){
            $db->transaction(function() use($db,$cipher,$rows,&$count){
                foreach($rows as $row){$value=$cipher->open((int)$row['company_id'],'shipment:'.$row['id'],$row['payload']);$db->run('UPDATE shipment_entries SET ordered_at=? WHERE id=?',[self::timestamp($value),$row['id']]);$count++;}
            });
        }
        return $count;
    }
}
