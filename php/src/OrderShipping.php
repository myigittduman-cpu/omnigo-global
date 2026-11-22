<?php
declare(strict_types=1);
namespace Omnigo;

/** Shipping is distinct from preparation, cancellation and delivery. Unknown codes stay unknown. */
final class OrderShipping
{
    public static function state(string $provider,mixed $status): string
    {
        $status=(string)$status;
        return match($provider){
            'n11'=>match($status){'Created','Picking'=>'pending','Shipped'=>'shipped','Delivered'=>'delivered','Cancelled','UnSupplied'=>'cancelled',default=>'unknown'},
            'Koçtaş'=>match($status){'WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING'=>'pending','SHIPPED','TO_COLLECT'=>'shipped','RECEIVED','CLOSED'=>'delivered','CANCELED','REFUSED'=>'cancelled',default=>'unknown'},
            // Keep the existing DeliveryMapper in-transit group consistent across both menus.
            'Pazarama'=>match($status){'3','12','18'=>'pending','5','16','19'=>'shipped','11'=>'delivered','14'=>'undelivered','6','13'=>'cancelled',default=>'unknown'},
            'idefix'=>match($status){'created','shipment_ready','shipment_picking','shipment_invoiced'=>'pending','shipment_in_cargo'=>'shipped','shipment_delivered','shipment_approved'=>'delivered','shipment_cancelled','shipment_unsupplied','cancelled','canceled','unsupplied'=>'cancelled',default=>'unknown'},
            default=>'unknown',
        };
    }
    public static function line(string $provider,array $line,array $order=[]): string
    {
        $cancel=OrderCancellation::line($provider,$line,$order);
        if($cancel['quantity']>0&&$cancel['cancelledQuantity']===$cancel['quantity'])return 'cancelled';
        $status=match($provider){
            'n11'=>$line['orderItemLineItemStatusName']??$order['shipmentPackageStatus']??'',
            'Koçtaş'=>$line['order_line_state']??$order['order_state']??'',
            'Pazarama'=>$line['orderItemStatus']??'',
            'idefix'=>$order['status']??'',
            default=>'',
        };
        return self::state($provider,$status);
    }
    public static function products(array $order,string $state): array
    {
        $found=[];
        foreach($order['details']['products']??[] as $product){
            $quantity=(int)($product['quantity']??0)-(int)($product['cancelledQuantity']??0);
            if($quantity<1)continue;
            $shipping=$product['shippingState']??self::state($order['provider'],$product['status']??'');
            if($shipping==='unknown'&&$order['provider']==='idefix'){
                $shipping=$order['shippingState']??match($order['deliveryStatus']??$order['status']??''){'Kargoda'=>'shipped',default=>'unknown'};
            }
            if($shipping===$state)$found[]=['title'=>$product['title'],'sku'=>$product['sku']??'','quantity'=>$quantity];
        }
        return $found;
    }
    public static function ageSeconds(array $order,int $now): ?int
    {
        $value=$order['createdAt']??'';
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))return null;
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}catch(\Exception){return null;}
        if(\DateTimeImmutable::getLastErrors()!==false)return null;
        return $now-$date->getTimestamp();
    }
}
