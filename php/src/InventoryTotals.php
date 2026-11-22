<?php
declare(strict_types=1);
namespace Omnigo;

/** One shared online pool plus an independent manually counted shop pool. */
final class InventoryTotals
{
    public static function calculate(string $mode, ?int $store, array $snapshots,array $shared=[]): array
    {
        if($mode==='horoz')return InventorySharedStock::calculate($snapshots,$shared);
        $online=null;$state='unlinked';$values=[];
        foreach($snapshots as $snapshot){
            if(($snapshot['health']??'')!=='current'||$snapshot['quantity']===null){$state='unverified';continue;}
            $values[]=(int)$snapshot['quantity'];
        }
        if($snapshots&&$state!=='unverified'){
            $unique=array_unique($values);
            if(count($unique)===1){$online=reset($values);$state='current';}
            else $state='conflict';
        }
        $total=match($mode){
            'store'=>$store,
            'both'=>$online!==null&&$store!==null?$online+$store:null,
            default=>$online,
        };
        // A deliberately manual product in a mixed workspace has no online allocation.
        if($mode==='both'&&!$snapshots)$total=$store;
        // Keep the previous, strict total for automatic alerts. A display fallback
        // must not announce sold-out stock while another source is stale or differs.
        $verifiedTotal=$total;
        $selection=InventoryReferenceStock::resolve($snapshots);
        $reference=$selection['reference'];
        if($reference!==null){
            $online=(int)$reference['quantity'];
            $state=$selection['different']?'reference':'current';
            $total=match($mode){'store'=>$store,'both'=>$store!==null?$online+$store:null,default=>$online};
        }
        return ['marketplaceQuantity'=>$online,'marketplaceHealth'=>$state,'marketplaceReference'=>$reference,
            'marketplaceStockDifferent'=>$selection['different'],'storeQuantity'=>$store,'totalQuantity'=>$total,
            'verifiedTotalQuantity'=>$verifiedTotal,'critical'=>$verifiedTotal!==null&&$verifiedTotal<=0];
    }
}
