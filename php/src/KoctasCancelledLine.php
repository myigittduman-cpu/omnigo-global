<?php
declare(strict_types=1);
namespace Omnigo;

/** A fully cancelled Mirakl line can retain its original quantity/price only in cancelations. */
final class KoctasCancelledLine
{
    public static function restore(array $line): array
    {
        if(!in_array($line['order_line_state']??'',['CANCELED','REFUSED'],true)||($line['quantity']??null)!==0)return $line;
        $quantity=0;$amount=0;
        foreach($line['cancelations']??[] as $cancel){
            if(!is_int($cancel['quantity']??null)||$cancel['quantity']<0)throw new SyncFailure(422);
            $quantity+=$cancel['quantity'];$amount+=KoctasOrderMapper::cents($cancel['amount']??null);
        }
        // Missing cancellation history must not manufacture a unit or a price.
        if($quantity===0)return $line;
        $line['quantity']=$quantity;
        if(KoctasOrderMapper::cents($line['price']??null)===0)$line['price']=intdiv($amount,100).'.'.str_pad((string)($amount%100),2,'0',STR_PAD_LEFT);
        return $line;
    }
}
