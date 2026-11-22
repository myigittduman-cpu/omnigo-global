<?php
declare(strict_types=1);
namespace Omnigo;

/** Add only missing derived jobs for an already enabled, tenant-owned connection. */
final class TrendyolCatalogEnrollment
{
    public function __construct(private Database $db){}

    /** The caller derives enabled from the vault or its guarded connection-save callback. */
    public function ensure(int $company,bool $connectionEnabled):bool
    {
        return (new InventoryCatalogEnrollment($this->db))->ensure($company,'trendyol',$connectionEnabled);
    }
}
