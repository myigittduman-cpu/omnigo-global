<?php
declare(strict_types=1);
namespace Omnigo;

/** Search/export use POST but only read data; all shipment mutations are admin-only. */
final class ShipmentWriteAccess
{
    public static function requireAllowed(array $user, string $path, string $method): void
    {
        if ($method === 'GET' || ($method === 'POST' && in_array($path,
            ['/api/shipments/search','/api/shipments/export'], true))) return;
        Security::admin($user);
    }
}
