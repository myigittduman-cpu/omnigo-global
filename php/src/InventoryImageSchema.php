<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit installation only; image work never shares the stock queue. */
final class InventoryImageSchema
{
    public static function install(Database $db):void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $index=$db->mysql()?',KEY inventory_image_due (state,next_run,lease_until)':'';
        $db->run("CREATE TABLE IF NOT EXISTS inventory_image_jobs (company_id INT NOT NULL,product_key VARCHAR(64) NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_run BIGINT NOT NULL DEFAULT 0,lease_until BIGINT NOT NULL DEFAULT 0,lease_token VARCHAR(64),error VARCHAR(200),source_url VARCHAR(2048),updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,product_key)".$index.')'.$suffix);
        if(!$db->mysql())$db->run('CREATE INDEX IF NOT EXISTS inventory_image_due ON inventory_image_jobs(state,next_run,lease_until)');
    }
}
