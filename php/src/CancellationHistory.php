<?php
declare(strict_types=1);
namespace Omnigo;

/** A missing line or stale snapshot must not reset already observed cancellation quantities. */
final class CancellationHistory
{
    public static function previousPackages(Database $db,DeliveryCipher $cipher,int $company,array $value,array $old): array
    {
        if($value['provider']!=='n11')return ['old'=>$old,'known'=>(bool)$old];
        $prefix=strtr($value['orderNumber'],['!'=>'!!','%'=>'!%','_'=>'!_']).':%';
        $rows=$db->all("SELECT external_id,payload FROM delivery_orders WHERE company_id=? AND provider='n11' AND external_id LIKE ? ESCAPE '!'",[$company,$prefix]);
        foreach($rows as $row){
            $package=$cipher->open($company,'order:n11:'.$row['external_id'],$row['payload']);
            $history=self::advance($package,$package)['seen'];
            foreach($history as $id=>$quantity)$old['cancellationHistory'][$id]=max($old['cancellationHistory'][$id]??0,$quantity);
        }
        return ['old'=>$old,'known'=>(bool)$rows];
    }
    public static function advance(array $value,array $old): array
    {
        $seen=$old['cancellationHistory']??[];
        foreach($old['cancellation']['lines']??[] as $id=>$line)$seen[$id]=max($seen[$id]??0,(int)$line['cancelledQuantity']);
        $delta=0;
        foreach($value['cancellation']['lines']??[] as $id=>$line){
            $count=(int)$line['cancelledQuantity'];$delta+=max(0,$count-($seen[$id]??0));
            if($count>0)$seen[$id]=max($seen[$id]??0,$count);
        }
        $seen=array_filter($seen,fn($quantity)=>$quantity>0);ksort($seen,SORT_STRING);
        return ['seen'=>$seen,'delta'=>$delta];
    }
    public static function assertCurrent(array $value,array $old): void
    {
        $seen=self::advance($old,$old)['seen'];
        foreach($value['cancellation']['lines']??[] as $id=>$line){
            if((int)$line['cancelledQuantity']<($seen[$id]??0))throw new OrderSnapshotConflict();
        }
    }
}
