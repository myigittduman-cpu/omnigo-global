<?php
declare(strict_types=1);
namespace Omnigo;

/** Additive migration only; HTTP reads never create business tables. */
final class OperationSchema
{
    public static function install(Database $db):void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS operation_attention_sources (company_id INT NOT NULL,issue_key VARCHAR(64) NOT NULL,payload MEDIUMTEXT NOT NULL,fingerprint VARCHAR(64) NOT NULL,updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,issue_key),FOREIGN KEY(company_id) REFERENCES companies(id))'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS operation_dossiers (company_id INT NOT NULL,dossier_key VARCHAR(64) NOT NULL,payload MEDIUMTEXT NOT NULL,version INT NOT NULL DEFAULT 1,updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,dossier_key),FOREIGN KEY(company_id) REFERENCES companies(id))'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS operation_attention (company_id INT NOT NULL,issue_key VARCHAR(64) NOT NULL,fingerprint VARCHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,assignee_id INT NULL,note VARCHAR(500) NOT NULL,version INT NOT NULL DEFAULT 1,updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,issue_key),FOREIGN KEY(company_id) REFERENCES companies(id))'.$tail);
    }
}
