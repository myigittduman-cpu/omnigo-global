<?php
declare(strict_types=1);
namespace Omnigo;

/** Encrypted local corrections never alter marketplace IDs, lifecycle, or sales totals. */
final class DeliveryCorrections
{
    public const CONTACT_MAP=['buyerName'=>'customerName','recipientName'=>'recipientName','phone'=>'phone','address'=>'deliveryAddress','city'=>'province','district'=>'district'];
    public static function apply(array $value,array $corrections): array
    {
        if(!$corrections)return $value;
        $value['manualCorrections']=$corrections;
        foreach(self::CONTACT_MAP as $key=>$target)if(array_key_exists($key,$corrections)){
            $corrected=$key==='city'?DeliveryMapper::city($corrections[$key]):$corrections[$key];
            if($key!=='recipientName')$value[$key]=$corrected;
            $value['details'][$target]=$key==='city'?$corrections[$key]:$corrected;
            foreach($value['shipmentItems']??[] as $index=>$item)$value['shipmentItems'][$index]['payload'][$target]=$corrected;
        }
        if(isset($corrections['orderNumber'])&&empty($value['orderNumber']))$value['orderNumber']=$corrections['orderNumber'];
        $matched=[];
        foreach($value['details']['products']??[] as $index=>$product){
            $id=(string)($product['lineId']??'');$title=$corrections['productTitle:'.$id]??null;$quantity=$corrections['productQuantity:'.$id]??null;
            // Source mappers retain line order. Consume each active line once, even when
            // two missing titles/SKUs are identical; cancelled lines are not operational products.
            $eligible=match($value['provider']){
                'n11'=>in_array($product['status']??'',['Created','Picking','Shipped'],true),
                'Pazarama'=>in_array((string)($product['status']??''),['3','12','18','5','16','19'],true),
                'Koçtaş'=>in_array($product['status']??'',['WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING','SHIPPED','TO_COLLECT'],true),
                default=>($product['cancelledQuantity']??0)<($product['quantity']??0),
            };
            foreach($value['products']??[] as $i=>$candidate)if($eligible&&!isset($matched[$i])&&($candidate['sku']??'')===($product['sku']??'')&&($candidate['title']??'')===($product['title']??'')){
                $matched[$i]=true;
                if($title!==null)$value['products'][$i]['title']=$title;
                if($quantity!==null&&($candidate['quantity']??0)<1)$value['products'][$i]['quantity']=(int)$quantity;
                break;
            }
            if($title===null&&$quantity===null)continue;
            if($title!==null)$value['details']['products'][$index]['title']=$title;
            if($quantity!==null&&($product['quantity']??0)<1)$value['details']['products'][$index]['quantity']=(int)$quantity;
            $identity=$value['provider']==='Pazarama'?$value['externalId']:$value['orderNumber'];
            $key=hash('sha256',json_encode([$identity,$id],JSON_THROW_ON_ERROR));
            foreach($value['shipmentItems']??[] as $i=>$item)if($item['key']===$key){if($title!==null)$value['shipmentItems'][$i]['payload']['productName']=$title;if($quantity!==null&&($item['payload']['quantity']??0)<1)$value['shipmentItems'][$i]['payload']['quantity']=(int)$quantity;}
        }
        return DeliveryMapper::finish($value);
    }
}
