<?php
declare(strict_types=1);
namespace Omnigo;

final class OrderNotificationSchema
{
    public static function install(Database $db): void
    {
        $engine=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $id=$db->mysql()?'INT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS order_notification_sources (
            company_id INT NOT NULL,provider VARCHAR(32) NOT NULL,started_at INT NOT NULL,
            PRIMARY KEY(company_id,provider),FOREIGN KEY(company_id) REFERENCES companies(id)
        )$engine");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS order_notifications (
            id $id,company_id INT NOT NULL,provider VARCHAR(32) NOT NULL,order_key VARCHAR(64) NOT NULL,
            created VARCHAR(35) NOT NULL,event_type VARCHAR(16) NOT NULL DEFAULT 'order',payload TEXT NULL,UNIQUE(company_id,provider,order_key),
            FOREIGN KEY(company_id) REFERENCES companies(id)
        )$engine");
    }
}
