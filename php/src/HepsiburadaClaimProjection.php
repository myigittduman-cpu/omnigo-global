<?php
declare(strict_types=1);
namespace Omnigo;

/** Confirmed refunds for undelivered goods stop dispatch; financial refunds remain separate. */
final class HepsiburadaClaimProjection
{
    private static function confirmed(array $claim): bool
    {
        return ($claim['status']??'')==='Refunded'&&($claim['claimType']??'')==='UndeliveredProduct';
    }
    /** Called only after a complete claims scan, inside the company transaction. */
    public static function remember(array &$state,array $claims): void
    {
        foreach($claims as $claim){
            if(!self::confirmed($claim))continue;
            $id=$claim['id'];
            if(($state['fulfillmentClaims'][$id]??null)===$claim)continue;
            $state['fulfillmentClaims'][$id]=$claim;
            $state['pendingOrders'][$claim['orderNumber']]=true;
        }
        if(count($state['fulfillmentClaims']??[])>10000)throw new SyncFailure(503,60);
    }
    /** Keep the source order amounts unchanged: the sales worker deducts the refund once. */
    public static function apply(array $mapped,array $claims): array
    {
        $order=$mapped['order'];$byLine=[];$seen=[];$products=array_column($order['products'],null,'lineId');
        foreach($claims as $claim){
            if(!self::confirmed($claim)||($claim['orderNumber']??'')!==$order['externalId'])continue;
            if(isset($seen[$claim['id']])){
                if($seen[$claim['id']]!==$claim)throw new SyncFailure(409);
                continue;
            }
            $seen[$claim['id']]=$claim;$line=$claim['lineId'];
            if(!isset($products[$line])||strtotime($claim['orderedAt'])!==strtotime($order['createdAt'])
                ||!is_int($claim['quantity'])||$claim['quantity']<1||!is_int($claim['amountCents'])||$claim['amountCents']<0
                ||strtotime($claim['refundedAt']??'')<strtotime($order['createdAt']))throw new SyncFailure(422);
            $byLine[$line]['quantity']=($byLine[$line]['quantity']??0)+$claim['quantity'];
            $byLine[$line]['amountCents']=($byLine[$line]['amountCents']??0)+$claim['amountCents'];
            if($byLine[$line]['quantity']>$products[$line]['quantity']||$byLine[$line]['amountCents']>$products[$line]['grossCents'])throw new SyncFailure(422);
        }
        foreach($mapped['deliveries'] as &$delivery){
            $product=$delivery['details']['products'][0];$id=$product['lineId'];
            if(!isset($byLine[$id]))continue;
            $quantity=$product['quantity'];$cancelled=max($product['cancelledQuantity']??0,$byLine[$id]['quantity']);$remaining=$quantity-$cancelled;
            $cancellation=['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>$remaining>0];
            $delivery['cancellation']=[...$cancellation,'lines'=>[$id=>$cancellation]];
            $delivery['cancelledProducts']=[['lineId'=>$id,'title'=>$product['title'],'quantity'=>$cancelled]];
            $delivery['details']['products'][0]['cancelledQuantity']=$cancelled;
            if($remaining===0){
                $delivery['active']=false;$delivery['products']=[];
                $delivery['status']=$delivery['deliveryStatus']='İptal edildi';
                $delivery['details']['products'][0]['shippingState']='cancelled';
                $delivery['details']['products'][0]['status']='İptal edildi';
            }else foreach($delivery['products'] as &$dispatch)$dispatch['quantity']=$remaining;
            unset($dispatch);
            foreach($delivery['shipmentItems']??[] as $index=>$item){
                $delivery['shipmentItems'][$index]['payload']['marketplaceCancellation']=$cancellation;
                if($remaining===0){
                    $delivery['shipmentItems'][$index]['payload']['marketplaceShipping']='cancelled';
                    $delivery['shipmentItems'][$index]['payload']['packageStatus']='İptal edildi';
                }
            }
        }unset($delivery);
        return $mapped;
    }
}
