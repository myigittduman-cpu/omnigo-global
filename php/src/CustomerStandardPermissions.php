<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared role snapshots. A saved account override does not modify these defaults. */
final class CustomerStandardPermissions
{
    private const MANAGER = [
        'overview' => ['view'],
        'overviewSales' => ['view'],
        'overviewTop' => ['view'],
        'overviewWork' => ['view'],
        'channels' => ['view'],
        'inventory' => ['view', 'viewPurchasePrice', 'editPurchasePrice', 'viewSales', 'recordStoreSale'],
        'analytics' => ['view', 'export'],
        'payments' => ['view'],
        'support' => ['view', 'create', 'reply'],
        'shipments' => ['view', 'selectRows', 'invoice', 'documents', 'upload', 'replaceDocuments', 'export'],
        'deliveries' => ['view', 'selectDocuments', 'export'],
        'shipmentInvoices' => ['view', 'documents', 'upload', 'replaceDocuments'],
    ];

    private const LOGISTICS = [
        'overviewTop' => ['view'],
        'overviewWork' => ['view'],
        'inventory' => ['view', 'editStock', 'recordStoreSale'],
        'shipments' => ['view', 'selectRows', 'invoice', 'documents', 'upload', 'replaceDocuments', 'export'],
        'deliveries' => ['view', 'selectDocuments', 'export'],
        'shipmentInvoices' => ['view', 'documents', 'upload', 'replaceDocuments'],
    ];

    public static function grants(): array
    {
        return self::snapshot(self::MANAGER);
    }

    public static function logistics(): array
    {
        return self::snapshot(self::LOGISTICS, CustomerAccountRoles::LOGISTICS);
    }

    private static function snapshot(array $selections, ?string $role=null): array
    {
        $grants = [];
        foreach (CustomerPermissionCatalog::pages() as $page => $definition) {
            $grants[$page] = array_fill_keys($definition['actions'], false);
        }
        foreach ($selections as $page => $actions) {
            foreach ($actions as $action) $grants[$page][$action] = true;
        }
        return CustomerPermissionCatalog::validateGrants(CustomerTableVisibility::withStatusDefaults($grants, []), $role);
    }
}
