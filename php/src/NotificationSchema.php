<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationSchema
{
    public static function migrate(Database $db): void
    {
        OrderNotificationSchema::install($db);
        IntegrationAlertSchema::install($db);
        $engine=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS notification_states (
            user_id INT NOT NULL,company_id INT NOT NULL,event_type VARCHAR(16) NOT NULL,event_id INT NOT NULL,
            is_read INT NOT NULL DEFAULT 0,dismissal_id VARCHAR(48) NULL,dismissed_at VARCHAR(35) NULL,
            PRIMARY KEY(user_id,company_id,event_type,event_id),
            FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(company_id) REFERENCES companies(id)
        )$engine");
    }
}
