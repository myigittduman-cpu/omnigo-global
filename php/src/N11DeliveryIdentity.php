<?php
declare(strict_types=1);
namespace Omnigo;

/** Null package IDs occur on cancelled n11 packages; order lines provide a stable fallback. */
final class N11DeliveryIdentity
{
    public static function lineKey(array $ids): string
    {
        $ids=array_values(array_unique(array_map('strval',$ids)));sort($ids,SORT_STRING);
        if(!$ids||in_array('',$ids,true))throw new SyncFailure(422);
        return 'lines-'.hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
    }
    public static function externalId(array $raw): string
    {
        $package=DeliveryMapper::text($raw['id']??'',60);
        if($package==='')$package=self::lineKey(array_map(fn($line)=>$line['orderLineId']??'',$raw['lines']??[]));
        return DeliveryMapper::text($raw['orderNumber']??'',100).':'.$package;
    }
    /** Upgrade the old :local key under the existing company lock, retaining row/document IDs. */
    public static function migrateLegacy(Database $db,DeliveryCipher $cipher,int $company,string $number,?string $incoming=null): void
    {
        $row=$db->one("SELECT * FROM delivery_orders WHERE company_id=? AND provider='n11' AND external_id=?",[$company,$number.':local']);
        if($row)self::move($db,$cipher,$company,$number,$row);
        // Some packages have an ID while active and a null ID after cancellation.
        // Preserve their row/document identity when the exact line set uniquely matches.
        if(!$incoming||!str_starts_with($incoming,$number.':lines-'))return;
        if($db->one("SELECT id FROM delivery_orders WHERE company_id=? AND provider='n11' AND external_id=?",[$company,$incoming]))return;
        $prefix=strtr($number,['!'=>'!!','%'=>'!%','_'=>'!_']).':%';$matches=[];
        foreach($db->all("SELECT * FROM delivery_orders WHERE company_id=? AND provider='n11' AND external_id LIKE ? ESCAPE '!'",[$company,$prefix]) as $candidate){
            $value=$cipher->open($company,'order:n11:'.$candidate['external_id'],$candidate['payload']);
            $ids=array_keys($value['cancellation']['lines']??[]);
            if(!$ids)$ids=array_column($value['details']['products']??[],'lineId');
            if($ids&&empty($value['details']['superseded'])&&$number.':'.self::lineKey($ids)===$incoming)$matches[]=$candidate;
        }
        if(count($matches)>1)throw new SyncFailure(409);
        if($matches)self::move($db,$cipher,$company,$number,$matches[0]);
    }
    private static function move(Database $db,DeliveryCipher $cipher,int $company,string $number,array $row): void
    {
        $value=$cipher->open($company,'order:n11:'.$row['external_id'],$row['payload']);
        $ids=array_keys($value['cancellation']['lines']??[]);
        if(!$ids)$ids=array_column($value['details']['products']??[],'lineId');
        $external=$number.':'.self::lineKey($ids);
        if($db->one("SELECT id FROM delivery_orders WHERE company_id=? AND provider='n11' AND external_id=?",[$company,$external]))throw new SyncFailure(409);
        $value['externalId']=$external;
        $db->run('UPDATE delivery_orders SET external_id=?,payload=?,source_hash=?,version=version+1 WHERE id=?',[
            $external,$cipher->seal($company,'order:n11:'.$external,$value),hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),$row['id']]);
    }
}
