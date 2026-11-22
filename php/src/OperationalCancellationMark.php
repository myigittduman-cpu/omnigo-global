<?php
declare(strict_types=1);
namespace Omnigo;

/** A visual warning on existing operational rows never grants access to cancellation figures. */
final class OperationalCancellationMark
{
    public static function remainingOrder(array $order): bool
    {
        if(($order['cancellation']['cancelledQuantity']??0)>0)return true;
        foreach($order['details']['products']??[] as $line)
            if(($line['cancelledQuantity']??0)>0||($line['shippingState']??null)==='cancelled')return true;
        return false;
    }

    public static function shipments(array $page,bool $seeFigures): array
    {
        foreach($page['items'] as &$row){
            $mark=$row['cancellationMark']??self::shipmentMark($row,$row['marketplaceCancellation']??[]);
            if($mark===null)continue;
            if($mark==='cancelled'&&!$seeFigures){
                // A fully cancelled line's original price and quantity would disclose the cancelled figures.
                $row=array_intersect_key($row,array_flip(['id','version','orderNumber','orderedAt','city','month','provider',
                    'customerName','customerNameSource','recipientName','recipientEmail','phone','deliveryAddress','province',
                    'district','productCode','productName','invoiceName','invoiceAddress','customerType','documentUpdated']));
                $row['customerReadOnly']=true;
                $row['documentRestricted']=true;
            }
            $row['cancellationMark']=$mark;
        }
        unset($row);
        return $page;
    }

    public static function shipmentMark(array $row,array $source): ?string
    {
        if(($row['displayRowStatus']??'')==='cancelled'||($row['rowStatus']??'')==='cancelled'||!empty($row['cancelled']))return 'cancelled';
        $cancelled=$source['cancelledQuantity']??null;
        if(!is_numeric($cancelled)||$cancelled<=0)return null;
        $quantity=$source['quantity']??null;
        $full=in_array($source['partial']??null,[false,0,'0','false'],true)
            ||(is_numeric($quantity)&&$quantity>0&&$cancelled>=$quantity);
        return $full?'cancelled':'partial';
    }
}
