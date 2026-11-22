<?php
declare(strict_types=1);
namespace Omnigo;

/** Rebuild deductions from a complete snapshot, never increment a previous total. */
final class IdefixReturns
{
    public static function apply(array $orders,array $claims): array
    {
        $byId=[];$byLine=[];$byOrder=[];
        foreach($claims as $claim){
            $id=$claim['id'];$key=$claim['orderNumber'].':'.$claim['lineId'];
            if(isset($byId[$id])){if($byId[$id]!==$claim)throw new SyncFailure(422);continue;}
            if(isset($byLine[$key]))throw new SyncFailure(422);
            $byId[$id]=$claim;$byLine[$key]=$claim;$byOrder[$claim['orderNumber']][$key]=$claim;
        }
        foreach($orders as &$order){
            $order['returnCents']=0;$order['refundsVerified']=true;$found=[];
            foreach($order['products'] as &$product){
                $key=$order['externalId'].':'.$product['lineId'];$claim=$byLine[$key]??null;
                if($claim&&$claim['amountCents']!==$product['grossCents'])throw new SyncFailure(422);
                // A fully cancelled line is already deducted and must not be deducted twice.
                $product['returnCents']=$claim&&$product['cancelCents']===0?$claim['amountCents']:0;
                $product['netCents']=$product['grossCents']-$product['cancelCents']-$product['returnCents'];
                $product['netQuantity']=$claim||$product['cancelCents']>0?0:$product['quantity'];
                $order['returnCents']+=$product['returnCents'];$found[$key]=true;
            }
            unset($product);
            foreach($byOrder[$order['externalId']]??[] as $key=>$claim)if(!isset($found[$key]))throw new SyncFailure(422);
        }
        unset($order);
        return $orders;
    }
}
