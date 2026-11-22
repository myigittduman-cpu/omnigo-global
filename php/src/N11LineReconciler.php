<?php
declare(strict_types=1);
namespace Omnigo;

/** Reconcile repeated package snapshots without counting a line twice. */
final class N11LineReconciler
{
    public static function merge(array $previous,array $next): array
    {
        $left=$previous;$right=$next;unset($left['status'],$right['status']);
        // Monetary, quantity, identity and date conflicts must still stop publication.
        if($left!==$right)throw new SyncFailure(422);
        $a=$previous['status'];$b=$next['status'];if($a===$b)return $next;
        $cancelled=['Cancelled','UnSupplied'];$progress=['Created'=>0,'Picking'=>1,'Shipped'=>2,'Delivered'=>3];
        if(in_array($a,$cancelled,true)||in_array($b,$cancelled,true)){
            if(in_array($a,['Shipped','Delivered'],true)||in_array($b,['Shipped','Delivered'],true))throw new SyncFailure(422);
            return [...$next,'status'=>in_array('Cancelled',[$a,$b],true)?'Cancelled':'UnSupplied'];
        }
        if(!isset($progress[$a],$progress[$b]))throw new SyncFailure(422);
        return $progress[$a]>$progress[$b]?$previous:$next;
    }
}
