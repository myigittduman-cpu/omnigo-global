<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentStatus
{
    public const VALUES=['','cancelled','delivered','shipped','invoiced','completed','review'];
    // Null denotes a legacy row. An explicit empty value clears its manual colour.
    public const SQL="COALESCE(row_status,CASE WHEN cancelled=1 THEN 'cancelled' WHEN shipped=1 THEN 'shipped' ELSE '' END)";
    public static function resolve(array $row): string
    {
        return $row['row_status']??(!empty($row['cancelled'])?'cancelled':(!empty($row['shipped'])?'shipped':''));
    }
}
