<?php
declare(strict_types=1);
namespace Omnigo;

final class FileCenterSchema
{
    public const ARCHIVES = 'file_center_archives';
    public const HIDDEN_PRODUCTS = 'file_center_hidden_products';

    public static function available(Database $db, string $table): bool
    {
        return (bool)$db->one($db->mysql()
            ? 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            : "SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]);
    }

    public static function install(Database $db): void
    {
        $id = $db->mysql() ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $db->mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->run("CREATE TABLE IF NOT EXISTS file_center_archives (
            id $id, company_id INT NOT NULL, owner_id INT NOT NULL, file_key CHAR(64) NOT NULL,
            payload TEXT NOT NULL, updated VARCHAR(35) NOT NULL, removed_at VARCHAR(35),
            UNIQUE(company_id,owner_id,file_key),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$suffix);
        $db->run('CREATE TABLE IF NOT EXISTS file_center_hidden_products (
            company_id INT NOT NULL, product_key CHAR(64) NOT NULL, actor_id INT NOT NULL, removed_at VARCHAR(35) NOT NULL,
            PRIMARY KEY(company_id,product_key),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)'.$suffix);
    }
}
