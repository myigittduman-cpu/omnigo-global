<?php
declare(strict_types=1);
namespace Omnigo;

/** Reporting projection only. Invoice values and marketplace snapshots remain unchanged. */
final class UndeliveredSales
{
    public static function apply(string $provider,array $order,array $packages): array
    {
        $source=[];
        foreach($packages as $package){
            if(!empty($package['details']['superseded']))continue;
            foreach($package['details']['products']??[] as $product){
                $id=(string)($product['lineId']??'');
                $key=$id!==''?'id:'.$id:'sku:'.($product['sku']??'');
                if($key==='sku:')continue;
                // Duplicate packages must not double the quantity. Conflicting statuses are not guessed.
                if(isset($source[$key])&&$source[$key]!==$product){$source[$key]=null;continue;}
                if(!array_key_exists($key,$source))$source[$key]=$product;
            }
        }
        $products=$order['products']??[];$delta=0;
        $skuCounts=array_count_values(array_map(fn($p)=>(string)($p['sku']??''),$products));
        foreach($products as $index=>$product){
            $id=(string)($product['lineId']??'');$match=$id!==''?($source['id:'.$id]??null):null;
            if($id===''&&($skuCounts[$product['sku']??'']??0)===1){
                $matches=array_values(array_filter($source,fn($p)=>$p!==null&&($p['sku']??'')!==''&&($p['sku']??'')===($product['sku']??'')));
                if(count($matches)===1)$match=$matches[0];
            }
            // Financial snapshots which already carry a validated shipping state need no fallback match.
            $line=$match??$product;
            $state=$line['shippingState']??OrderShipping::state($provider,$line['status']??'');
            if($state!=='undelivered')continue;
            $quantity=$product['quantity']??null;$excluded=$line['quantity']??null;
            if(!is_int($quantity)||!is_int($excluded)||$quantity<1||$excluded<1||$excluded>$quantity)continue;
            $gross=$product['grossCents'];$cancel=$product['cancelCents'];$returns=$product['returnCents'];
            if(min($gross,$cancel,$returns)<0||$cancel+$returns>$gross)continue;
            $target=intdiv($gross*$excluded+intdiv($quantity,2),$quantity);
            $extra=min($gross-$cancel-$returns,max(0,$target-$cancel-$returns));
            if($extra===0)continue;
            $products[$index]['cancelCents']=$cancel+$extra;
            $products[$index]['netCents']=$gross-$cancel-$returns-$extra;
            if(($product['netQuantity']??null)!==null)$products[$index]['netQuantity']=min($product['netQuantity'],$quantity-$excluded);
            if(isset($product['cancelledQuantity']))$products[$index]['cancelledQuantity']=min($quantity,max($product['cancelledQuantity'],$excluded-(int)($product['refundedQuantity']??0)));
            $delta+=$extra;
        }
        // Inconsistent source financial totals are never silently rewritten.
        if($delta===0||$delta>max(0,$order['grossCents']-$order['cancelCents']-$order['returnCents']))return $order;
        return [...$order,'products'=>$products,'cancelCents'=>$order['cancelCents']+$delta,'netCents'=>$order['grossCents']-$order['cancelCents']-$order['returnCents']-$delta,'operationalCancelCents'=>$delta];
    }

    public static function dashboard(array $result,string $month):array
    {
        // These three channels already build monthly/today values from the projected periods.
        $projected=['idefix','PTT AVM','Trendyol'];
        foreach($result['marketplaceSales']['channels'] as $item){
            if(empty($item['available'])||in_array($item['channel'],$projected,true))continue;
            $delta=$item['periods']['month']['operationalCancelCents']??0;
            foreach($result['sales'] as &$sale){
                if($sale['channel']===$item['channel']&&$sale['month']===$month&&!empty($sale['integration']))$sale['cancelCents']+=$delta;
            }
            unset($sale);
            foreach($result['salesToday']['channels'] as &$today)if($today['channel']===$item['channel']&&$today['netCents']!==null)$today['netCents']-=$item['periods']['today']['operationalCancelCents']??0;
            unset($today);
        }
        return $result;
    }
}
