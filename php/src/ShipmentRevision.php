<?php
declare(strict_types=1);
namespace Omnigo;

final class ShipmentRevision
{
    public static function read(Database $db,int $company): string
    {
        // All shipment writes and imports append an audit event in their transaction.
        // The company/id index makes this check independent of the shipment count.
        return (string)($db->one('SELECT id FROM audit WHERE company_id=? ORDER BY id DESC LIMIT 1',[$company])['id']??'0');
    }
}
