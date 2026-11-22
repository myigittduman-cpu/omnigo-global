<?php
declare(strict_types=1);
namespace Omnigo;

/** Same unique product/order match as N11SalesMapper; names and prices are never identities. */
final class N11ReturnIdentity
{
    public static function requests(array $claims,array $packages): array
    {
        $index=[];
        foreach($packages as $package){
            if(($package['status']??'')==='Unpacked')continue;
            foreach($package['products']??[] as $product){
                if(($product['status']??'')==='Unpacked')continue;
                $index[$package['orderNumber']][(string)$product['productId']][(string)$product['lineId']]=true;
            }
        }
        return array_map(static function($claim)use($index){
            $item=ReturnRequestMapper::n11($claim);$lines=$index[$claim['orderNumber']][(string)$claim['productId']]??[];
            if(count($lines)===1)$item['orderLineId']=(string)array_key_first($lines);
            return $item;
        },$claims);
    }
}
