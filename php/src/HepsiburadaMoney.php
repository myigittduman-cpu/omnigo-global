<?php
declare(strict_types=1);
namespace Omnigo;

/** API line totals can have fractional kurus; round the total once, never each unit. */
final class HepsiburadaMoney
{
    public static function cents(mixed $amount):int
    {
        if(!is_int($amount)&&!is_float($amount)&&!is_string($amount))throw new SyncFailure(422);
        if(is_float($amount)&&(!is_finite($amount)||$amount<0||$amount>=10000000000))throw new SyncFailure(422);
        $text=is_float($amount)?sprintf('%.12F',$amount):(string)$amount;
        if(!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,12}))?$/D',$text,$match))throw new SyncFailure(422);
        $fraction=str_pad($match[2]??'',3,'0');
        return (int)$match[1]*100+(int)substr($fraction,0,2)+((int)$fraction[2]>=5?1:0);
    }
}
