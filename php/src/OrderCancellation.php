<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit marketplace cancellation only: missing, delivered and returned are not cancellations. */
final class OrderCancellation
{
    public static function line(string $provider,array $line,array $order=[]): array
    {
        if($provider==='Koçtaş')$line=KoctasCancelledLine::restore($line);
        $quantity=$provider==='idefix'?1:max(0,(int)($line['quantity']??0));
        $cancelled=match($provider){
            'n11'=>in_array($line['orderItemLineItemStatusName']??'',['Cancelled','UnSupplied'],true)||in_array($order['shipmentPackageStatus']??'',['Cancelled','UnSupplied'],true),
            'Pazarama'=>in_array($line['orderItemStatus']??null,[6,13],true),
            'Koçtaş'=>in_array($line['order_line_state']??'',['CANCELED','REFUSED'],true)||in_array($order['order_state']??'',['CANCELED','REFUSED'],true),
            'idefix'=>in_array($line['itemStatus']??'',['cancelled','canceled','unsupplied'],true)||in_array($order['status']??'',['shipment_cancelled','shipment_unsupplied'],true),
            default=>false,
        };
        $count=$cancelled?$quantity:0;
        if($provider==='Koçtaş'&&!$cancelled)foreach($line['cancelations']??[] as $entry)$count+=max(0,(int)($entry['quantity']??0));
        if($count>$quantity)throw new SyncFailure(422);
        return ['quantity'=>$quantity,'cancelledQuantity'=>$count,'partial'=>$count>0&&$count<$quantity];
    }
    public static function delivery(array $value,array $raw): array
    {
        $provider=$value['provider'];$lines=match($provider){'n11'=>$raw['lines']??[],'Koçtaş'=>$raw['order_lines']??[],default=>$raw['items']??[]};
        $summary=[];$cancelledProducts=[];
        foreach($lines as $index=>$line){
            $state=self::line($provider,$line,$raw);
            $id=(string)($line['orderLineId']??$line['order_line_id']??$line['orderItemId']??$line['id']??$index);
            $summary[$id]=$state;
            if($state['cancelledQuantity']>0)$cancelledProducts[]=['lineId'=>$id,'title'=>DeliveryMapper::text($line['productName']??$line['product_title']??$line['product']['name']??'',500),'quantity'=>$state['cancelledQuantity']];
        }
        $state=self::combine($summary);$value['cancellation']=[...$state,'lines'=>$summary];$value['cancelledProducts']=$cancelledProducts;
        $value['deliveryStatus']=$value['status'];
        if($state['cancelledQuantity']>0&&!$state['partial'])$value['status']='İptal edildi';
        if($state['cancelledQuantity']>0&&!$state['partial']){$value['active']=false;$value['products']=[];}
        return $value;
    }
    public static function combine(array $lines): array
    {
        $quantity=array_sum(array_column($lines,'quantity'));$cancelled=array_sum(array_column($lines,'cancelledQuantity'));
        return ['quantity'=>$quantity,'cancelledQuantity'=>$cancelled,'partial'=>$cancelled>0&&$cancelled<$quantity];
    }
    public static function packageSummary(array $packages): array
    {
        $lines=[];
        foreach($packages as $package)foreach($package['cancellation']['lines']??[] as $id=>$line){
            if(!isset($lines[$id])||$line['cancelledQuantity']>$lines[$id]['cancelledQuantity'])$lines[$id]=$line;
        }
        return self::combine($lines);
    }
}
