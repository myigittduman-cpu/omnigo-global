<?php
declare(strict_types=1);
namespace Omnigo;

/** Stock-only projection; canonical financial/delivery snapshots remain read-only. */
final class InventoryOrderLines
{
    public static function normalize(string $provider,array $order,array $packages,ApprovedReturnIndex $returns):array
    {
        $groups=[];$delivery=[];$seen=[];
        foreach($order['products']??[] as $p){
            $sku=trim((string)($p['sku']??''));$q=$p['quantity']??null;$net=$p['netQuantity']??null;
            if($sku===''||!is_int($q)||$q<1||!is_int($net)||$net<0||$net>$q)throw new \DomainException('Siparişin stok kodu veya net adedi doğrulanamadı.');
            $groups[$sku]??=['ordered'=>0,'net'=>0,'gross'=>0,'cancelled'=>0,'returned'=>0,'title'=>$p['title']??$sku];
            $g=&$groups[$sku];$g['ordered']+=$q;$g['net']+=$net;
            $amount=$p['grossCents']??null;$g['gross']=is_int($amount)&&$g['gross']!==null?$g['gross']+$amount:null;
            // Financial full cancellations are unambiguous; partial quantities come from delivery/claims.
            if(($p['cancelCents']??0)>0&&($p['returnCents']??0)===0)$g['cancelled']+=$q-$net;
            else $g['returned']+=$q-$net;
            unset($g);
        }
        foreach($packages as $package){
            $products=$package['products']??[];
            $result=$returns->lines(['details'=>['products'=>$products],...array_intersect_key($package,array_flip(['externalId','orderNumber']))]);
            foreach($result['issues'] as $issue)if($issue!=='Tamamlanmış iade taraması henüz yok.')throw new \DomainException($issue);
            foreach($products as $p){
                $id=(string)($p['lineId']??'');$sku=trim((string)($p['sku']??''));if($id===''||$sku==='')throw new \DomainException('Sipariş ürün satırı kimliği eksik.');
                $q=(int)($p['quantity']??0);$cancel=(int)($p['cancelledQuantity']??$package['cancellation']['lines'][$id]['cancelledQuantity']??0);
                $shipping=$p['shippingState']??OrderShipping::state($provider,$p['status']??'');
                if(in_array($shipping,['cancelled','undelivered'],true))$cancel=$q;
                $returned=max((int)($p['refundedQuantity']??0),(int)($result['lines'][$id]['returnedQuantity']??0));
                if($q<1||$cancel<0||$returned<0||$cancel>$q||$returned>$q)throw new \DomainException('İptal/iade adedi doğrulanamadı.');
                $returned=min($returned,$q-$cancel);$net=$q-$cancel-$returned;
                $open=in_array($shipping,['pending','shipped'],true)?$net:0;
                $unknown=!in_array($shipping,['pending','shipped','delivered','cancelled','undelivered'],true)?$net:0;
                // Repeated n11 packages may retain different textual states after a full cancellation.
                $fingerprint=json_encode([$sku,$q,$cancel,$returned,$open,$unknown],JSON_THROW_ON_ERROR);
                if(isset($seen[$id])){if($seen[$id]!==$fingerprint)throw new \DomainException('Sipariş paketlerinde çelişkili ürün satırı var.');continue;}$seen[$id]=$fingerprint;
                $delivery[$sku]??=['ordered'=>0,'cancelled'=>0,'returned'=>0,'open'=>0,'unknown'=>0];
                $delivery[$sku]['ordered']+=$q;$delivery[$sku]['cancelled']+=$cancel;$delivery[$sku]['returned']+=$returned;$delivery[$sku]['open']+=$open;$delivery[$sku]['unknown']+=$unknown;
            }
        }
        $lines=[];
        foreach($groups as $sku=>$g){
            $d=$delivery[$sku]??null;
            if($d&&$d['ordered']!==$g['ordered'])throw new \DomainException('Sipariş ve teslimat ürün adetlerinin eşitlenmesi bekleniyor.');
            $cancel=max($g['cancelled'],$d['cancelled']??0);$returned=max($g['returned'],$d['returned']??0);
            $returned=min($returned,$g['ordered']-$cancel);
            $lines[]=['provider'=>$provider,'lineKey'=>hash('sha256',$provider.':'.$order['externalId'].':'.$sku),'sku'=>(string)$sku,'title'=>$g['title'],'orderNumber'=>(string)$order['externalId'],'placedAt'=>$order['createdAt'],'ordered'=>$g['ordered'],'cancelled'=>$cancel,'returned'=>$returned,'invoiceCents'=>$g['gross'],'openQuantity'=>$d['open']??0,'unknownQuantity'=>$d['unknown']??($g['ordered']-$cancel-$returned)];
        }
        if(!$lines&&($order['orders']??0)>0)throw new \DomainException('Sipariş ürünleri eksik.');
        return $lines;
    }
}
