<?php
declare(strict_types=1);
namespace Omnigo;

final class FinanceSchema
{
    public static function install(Database $db): void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS finance_entries(company_id INT NOT NULL,provider VARCHAR(20) NOT NULL,external_key VARCHAR(64) NOT NULL,transaction_date VARCHAR(10) NOT NULL,expected_date VARCHAR(10) NOT NULL,net_cents BIGINT NOT NULL,state VARCHAR(20) NOT NULL,updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,provider,external_key),FOREIGN KEY(company_id) REFERENCES companies(id))'.$suffix);
        $db->run('CREATE TABLE IF NOT EXISTS finance_sync(company_id INT NOT NULL,provider VARCHAR(20) NOT NULL,payload MEDIUMTEXT NOT NULL,version INT NOT NULL DEFAULT 1,PRIMARY KEY(company_id,provider),FOREIGN KEY(company_id) REFERENCES companies(id))'.$suffix);
    }
}
