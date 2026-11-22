<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliverySchema
{
    public static function install(Database $db): void
    {
        OrderNotificationSchema::install($db);
        $id=$db->mysql()?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY';
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS delivery_orders (id $id, company_id INT NOT NULL, provider VARCHAR(30) NOT NULL, external_id VARCHAR(180) NOT NULL, city VARCHAR(40) NOT NULL, active INT NOT NULL, payload MEDIUMTEXT NOT NULL, source_hash VARCHAR(64) NOT NULL, version INT NOT NULL DEFAULT 1, document_id INT NULL, updated VARCHAR(35) NOT NULL, UNIQUE(company_id,provider,external_id), FOREIGN KEY(company_id) REFERENCES companies(id))".$tail);
        $db->run("CREATE TABLE IF NOT EXISTS delivery_documents (id $id, company_id INT NOT NULL, request_id VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, city VARCHAR(40) NOT NULL, delivery_date VARCHAR(10) NOT NULL, order_count INT NOT NULL, payload MEDIUMTEXT NOT NULL, created_by INT NOT NULL, created VARCHAR(35) NOT NULL, UNIQUE(company_id,request_id), FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(created_by) REFERENCES users(id))".$tail);
        if(!$db->mysql())$db->run('CREATE INDEX IF NOT EXISTS delivery_city ON delivery_orders(company_id,city,active)');
        elseif(!$db->one("SHOW INDEX FROM delivery_orders WHERE Key_name='delivery_city'"))$db->run('CREATE INDEX delivery_city ON delivery_orders(company_id,city,active)');
    }
}
