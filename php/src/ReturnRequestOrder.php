<?php
declare(strict_types=1);
namespace Omnigo;

/** A refresh timestamp must never make an old or undated request look new. */
final class ReturnRequestOrder
{
    public static function compare(array $left,array $right): int
    {
        return self::timestamp($right)<=>self::timestamp($left)
            ?:strcmp(($left['providerKey']??'').':'.$left['id'],($right['providerKey']??'').':'.$right['id']);
    }
    private static function timestamp(array $row): int
    {
        $value=$row['createdAt']??null;
        if(!is_string($value)||trim($value)==='')return PHP_INT_MIN;
        $stamp=strtotime($value);
        return $stamp===false?PHP_INT_MIN:$stamp;
    }
}
