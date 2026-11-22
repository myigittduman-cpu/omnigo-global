<?php
declare(strict_types=1);
namespace Omnigo;

/** Raw financial reconciliation sample; never interpret packages as unique orders or net sales. */
final class N11Preview
{
    public static function map(array $packages): array
    {
        $result=[];$sellers=[];
        if(count($packages)>100)throw new SyncFailure(422);
        foreach($packages as $package){
            $number=self::id($package['orderNumber']??null);
            $seller=isset($package['sellerId'])?self::id($package['sellerId']):null;
            if($seller!==null)$sellers[$seller]=true;
            $lines=$package['lines']??null;
            if(!is_array($lines)||!array_is_list($lines)||count($lines)>500||!$lines)throw new SyncFailure(422);
            $invoice=0;$quantity=0;$products=[];
            foreach($lines as $line){
                if(!is_int($line['quantity']??null)||$line['quantity']<1||$line['quantity']>100000)throw new SyncFailure(422);
                // sellerInvoiceAmount is the full line's invoiced amount; never multiply it by quantity.
                $amount=KoctasOrderMapper::cents($line['sellerInvoiceAmount']??null);$invoice+=$amount;$quantity+=$line['quantity'];
                $products[]=['lineId'=>self::id($line['orderLineId']??null),'title'=>self::text($line['productName']??'',300),
                    'sku'=>self::text($line['stockCode']??'',150),'quantity'=>$line['quantity'],'invoiceCents'=>$amount,
                    'status'=>self::text($line['orderItemLineItemStatusName']??'',80)];
            }
            $result[]=['orderNumber'=>$number,'packageId'=>isset($package['id'])?self::id($package['id']):null,
                'sellerId'=>$seller,'status'=>self::text($package['shipmentPackageStatus']??'',80),'invoiceCents'=>$invoice,
                'quantity'=>$quantity,'products'=>$products];
        }
        if(count($sellers)>1)throw new SyncFailure(422);
        return ['packages'=>$result,'sellerId'=>$sellers? (string)array_key_first($sellers):null];
    }
    private static function id(mixed $value): string
    {
        if(!is_string($value)&&!is_int($value))throw new SyncFailure(422);
        if(!preg_match('/^[0-9]{1,40}$/D',(string)$value))throw new SyncFailure(422);
        return (string)$value;
    }
    private static function text(mixed $value,int $limit): string
    {
        if(!is_string($value)||mb_strlen($value)>$limit)throw new SyncFailure(422);
        return $value;
    }
}
