<?php
declare(strict_types=1);
namespace Omnigo;

/** Provider-private derived cursors; no existing record enum or tenant setting is changed. */
final class TrendyolCatalogSchema
{
    public static function install(Database $db):void
    {
        $text=$db->mysql()?'MEDIUMTEXT':'TEXT';$suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS inventory_trendyol_checkpoints (company_id INT PRIMARY KEY,payload $text NOT NULL,version BIGINT NOT NULL DEFAULT 1,updated VARCHAR(35) NOT NULL,FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$suffix);
    }
}
