<?php
declare(strict_types=1);
namespace Omnigo;

/** Mirakl RT11 records: intentionally no address, free-text description or estimated refund. */
final class KoctasReturnMapper
{
    public static function page(array $response): array
    {
        $rows=$response['data']??null;$next=$response['next_page_token']??null;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>100||($next!==null&&(!is_string($next)||$next===''||strlen($next)>16384))||(!$rows&&$next!==null))throw new SyncFailure(422);
        $items=[];
        foreach($rows as $row){
            if(!is_array($row))throw new SyncFailure(422);
            $claim=self::identifier($row['id']??null);$order=self::identifier($row['order_id']??null);$status=self::identifier($row['state']??null);
            $created=ReturnRequestMapper::date($row['date_created']??null);$updated=ReturnRequestMapper::date($row['last_updated']??null);
            $lines=$row['return_lines']??null;if(!$created||!is_array($lines)||!array_is_list($lines)||!$lines||count($lines)>1000)throw new SyncFailure(422);
            foreach($lines as $line){
                $id=self::identifier($line['order_line_id']??null);$quantity=$line['quantity']??null;
                if(!is_int($quantity)||$quantity<1||$quantity>100000)throw new SyncFailure(422);
                $items[]=['id'=>$claim.':'.$id,'claimId'=>$claim,'orderNumber'=>$order,'lineId'=>$id,'status'=>$status,'createdAt'=>$created,'updatedAt'=>$updated,
                    'quantity'=>$quantity,'compliant'=>self::compliance($line),'rejectionReason'=>ReturnRequestMapper::text($row['rejection_reason_code']??''),'amountCents'=>null,'product'=>'','reason'=>ReturnRequestMapper::text($line['reason_code']??$row['reason_code']??'',300)];
            }
        }
        return ['items'=>$items,'next'=>$next];
    }
    private static function identifier(mixed $value): string
    {
        if(!is_string($value)||$value===''||mb_strlen($value)>180||preg_match('/[\x00-\x1f]/',$value))throw new SyncFailure(422);
        return $value;
    }
    private static function compliance(array $line): ?bool
    {
        $items=$line['compliance']??null;if(!is_array($items)||!array_is_list($items)||!$items)return null;
        foreach($items as $item)if(($item['compliant']??null)!==true)return false;
        return true;
    }
}
