<?php
declare(strict_types=1);
namespace Omnigo;

final class RecentOrderSchema
{
    public static function install(Database $db): void
    {
        $id=$db->mysql()?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY';
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        // Operational orders are separate from completed sales/returns snapshots.
        $db->run("CREATE TABLE IF NOT EXISTS recent_orders (id $id,company_id INT NOT NULL,provider VARCHAR(20) NOT NULL,external_id VARCHAR(180) NOT NULL,payload MEDIUMTEXT NOT NULL,updated VARCHAR(35) NOT NULL,UNIQUE(company_id,provider,external_id),FOREIGN KEY(company_id) REFERENCES companies(id))".$tail);
    }
    public static function available(Database $db): bool
    {
        return (bool)($db->mysql()?$db->one("SHOW TABLES LIKE 'recent_orders'"):$db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='recent_orders'"));
    }
}
