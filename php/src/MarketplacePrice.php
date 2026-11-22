<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplacePrice
{
    public static function cents(mixed $amount):?int
    {
        if(!is_numeric($amount)||!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D',(string)$amount))return null;
        $parts=explode('.',(string)$amount);
        return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    }
}
