<?php
declare(strict_types=1);
namespace Omnigo;

/** Prices already include campaign discounts. Keep personal data out of sales snapshots. */
final class PazaramaSalesMapper
{
    public static function order(array $raw): array
    {
        $id=self::id($raw['orderId']??null);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',(string)($raw['orderDate']??''),new \DateTimeZone('Europe/Istanbul'));
        if(!$date||$date->format('Y-m-d H:i')!==($raw['orderDate']??null)||!in_array($raw['currency']??null,['TL','TRY'],true))throw new SyncFailure(422);
        $items=$raw['items']??null;
        if(!is_array($items)||!array_is_list($items)||!$items||count($items)>1000)throw new SyncFailure(422);
        $order=['externalId'=>$id,'createdAt'=>$date->format(DATE_ATOM),'month'=>$date->format('Y-m'),'state'=>'Aktarıldı','orders'=>1,'grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'products'=>[]];
        $seen=[];
        foreach($items as $item){
            $line=self::id($item['orderItemId']??null);
            if(isset($seen[$line]))throw new SyncFailure(422);$seen[$line]=true;
            $quantity=$item['quantity']??null;$status=$item['orderItemStatus']??null;
            if(!is_int($quantity)||$quantity<1||$quantity>100000||!in_array($status,[3,6,12,18,13,5,16,19,11,14,7,8,9,10],true))throw new SyncFailure(422);
            $gross=self::money($item['totalPrice']??null);
            $cancel=in_array($status,[6,13],true)?$gross:0;
            $returned=in_array($status,[8,10],true)?$gross:0;
            $product=$item['product']??[];
            $title=self::text($product['name']??null,1000);
            $sellerCode=self::text($product['stockCode']??'',180,true);
            // Preserve the source key used by previous order balances even when it is a barcode.
            $sku=self::text($product['stockCode']??$product['code']??'',180,true);
            $order['grossCents']+=$gross;$order['cancelCents']+=$cancel;$order['returnCents']+=$returned;
            $order['products'][]=['lineId'=>$line,'sku'=>$sku,'sellerStockCode'=>$sellerCode,'title'=>$title,'imageUrl'=>KoctasProductMapper::imageUrl($product['imageURL']??null),'quantity'=>$quantity,'netQuantity'=>($cancel||$returned)?0:$quantity,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$returned,'netCents'=>$gross-$cancel-$returned];
            if($sellerCode===''){
                $sourceId=ShipmentProductCode::normalize($product['code']??null);
                if($sourceId!==''&&strlen($sourceId)<=180)$order['products'][array_key_last($order['products'])]['sourceProductId']=$sourceId;
            }
            $pricing=PazaramaPricing::fromLine($item,$gross);
            if($pricing!==null)$order['products'][array_key_last($order['products'])]['pricing']=$pricing;
        }
        // Do not silently double-discount or multiply the line total by quantity.
        if($order['grossCents']!==KoctasOrderMapper::cents($raw['orderAmount']??null))throw new SyncFailure(422);
        return $order;
    }

    public static function unique(array $orders,string $from,string $through): array
    {
        $seen=[];
        foreach($orders as $order){
            if(strtotime($order['createdAt'])<strtotime($from)||strtotime($order['createdAt'])>strtotime($through))throw new SyncFailure(422);
            $id=$order['externalId'];
            if(isset($seen[$id]))throw new SyncFailure(422);
            $seen[$id]=$order;
        }
        return array_values($seen);
    }
    public static function id(mixed $value): string
    {
        if(!is_string($value)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$value))throw new SyncFailure(422);
        return strtolower($value);
    }
    public static function money(mixed $value): int
    {
        if(!is_array($value)||!in_array($value['currency']??null,['TL','TRY'],true))throw new SyncFailure(422);
        $cents=KoctasOrderMapper::cents($value['value']??null);
        if(isset($value['valueInt'])&&$value['valueInt']!==$cents)throw new SyncFailure(422);
        return $cents;
    }
    public static function text(mixed $value,int $limit,bool $empty=false): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$limit||(!$empty&&trim($value)===''))throw new SyncFailure(422);
        return trim($value);
    }
}
