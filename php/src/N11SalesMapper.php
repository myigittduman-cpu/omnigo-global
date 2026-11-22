<?php
declare(strict_types=1);
namespace Omnigo;

/** Keeps only sales fields. Customer addresses, names and credentials never enter snapshots. */
final class N11SalesMapper
{
    public static function package(array $raw): array
    {
        $value=N11Preview::map([$raw])['packages'][0];
        if (!$value['sellerId'] || ($raw['micro']??false)) throw new SyncFailure(422);
        $created=[];
        foreach ($raw['packageHistories']??[] as $history) if (($history['status']??'')==='Created') {
            $time=$history['createdDate']??null;
            if (!is_int($time)||$time<1) throw new SyncFailure(422);
            $created[]=$time;
        }
        if (!$created) throw new SyncFailure(422);
        $value['createdAt']=(new \DateTimeImmutable('@'.intdiv(min($created),1000)))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format(DATE_ATOM);
        foreach ($value['products'] as $index=>&$product) {
            $id=$raw['lines'][$index]['productId']??null;
            if (!preg_match('/^[0-9]{1,40}$/D',(string)$id)) throw new SyncFailure(422);
            $product['productId']=(string)$id;
            if (!in_array($product['status'],['Created','Picking','Shipped','Delivered','Cancelled','UnSupplied','Unpacked'],true)) throw new SyncFailure(422);
        }
        unset($product);
        return $value;
    }

    public static function orders(array $packages,array $claims,string $from,string $through): array
    {
        $lines=[];$unpacked=[];$sellers=[];
        foreach ($packages as $package) {
            $sellers[$package['sellerId']]=true;
            // Overlapping windows can return the same package. Count each order line once.
            foreach ($package['products'] as $product) {
                $key=$package['orderNumber'].':'.$product['lineId'];
                if ($package['status']==='Unpacked'||$product['status']==='Unpacked') {$unpacked[$key]=true;continue;}
                $line=[...$product,'orderNumber'=>$package['orderNumber'],'createdAt'=>$package['createdAt']];
                if (isset($lines[$key])) $line=N11LineReconciler::merge($lines[$key],$line);
                $lines[$key]=$line;
            }
        }
        if (count($sellers)>1 || array_diff_key($unpacked,$lines)) throw new SyncFailure(422);
        $returned=[];$seen=[];
        foreach ($claims as $claim) {
            $id=$claim['claimReturnId'];
            if (isset($seen[$id])) {if ($seen[$id]!==$claim) throw new SyncFailure(422);continue;}
            $seen[$id]=$claim;
            if (!in_array($claim['status'],['APPROVED','MANUAL_REFUND'],true)) continue;
            $matches=array_filter($lines,fn($line)=>$line['orderNumber']===$claim['orderNumber']&&$line['productId']===$claim['productId']);
            // A claim for an older, uncovered order cannot change this period's sales.
            if (!$matches) {
                if (array_filter($lines,fn($line)=>$line['orderNumber']===$claim['orderNumber'])) throw new SyncFailure(422);
                continue;
            }
            if (count($matches)!==1) throw new SyncFailure(422);
            $key=array_key_first($matches);$returned[$key]=($returned[$key]??0)+$claim['quantity'];
        }
        $orders=[];
        foreach ($lines as $key=>$line) {
            if (strtotime($line['createdAt'])<strtotime($from)||strtotime($line['createdAt'])>strtotime($through)) throw new SyncFailure(422);
            $cancelled=in_array($line['status'],['Cancelled','UnSupplied'],true);
            $quantity=$line['quantity'];$refundQuantity=$returned[$key]??0;
            if ($refundQuantity>$quantity||($cancelled&&$refundQuantity)) throw new SyncFailure(422);
            $gross=$line['invoiceCents'];$cancel=$cancelled?$gross:0;
            // Net product sales reverse the original invoiced value by approved return quantity.
            // Claim finalPrice can contain buyer-funded discounts and is not the seller's invoice value.
            $refund=intdiv($gross*$refundQuantity+intdiv($quantity,2),$quantity);
            $id=$line['orderNumber'];
            $orders[$id]??=['externalId'=>$id,'createdAt'=>$line['createdAt'],'month'=>substr($line['createdAt'],0,7),'state'=>'Aktarıldı','orders'=>1,'grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'products'=>[]];
            if (substr($orders[$id]['createdAt'],0,10)!==substr($line['createdAt'],0,10)) throw new SyncFailure(422);
            $orders[$id]['grossCents']+=$gross;$orders[$id]['cancelCents']+=$cancel;$orders[$id]['returnCents']+=$refund;
            $orders[$id]['products'][]=['sku'=>$line['sku'],'title'=>$line['title'],'imageUrl'=>null,'quantity'=>$quantity,
                'netQuantity'=>$cancelled?0:$quantity-$refundQuantity,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$refund,'netCents'=>$gross-$cancel-$refund];
        }
        return array_values($orders);
    }
}
