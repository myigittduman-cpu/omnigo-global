<?php
declare(strict_types=1);
namespace Omnigo;

final class ManagementSchema
{
    // Additive migration: existing accounts and commercial records are untouched.
    public static function install(Database $db): void
    {
        $text = $db->mysql() ? 'MEDIUMTEXT' : 'TEXT';
        foreach ([
            "CREATE TABLE IF NOT EXISTS company_profiles (company_id INT PRIMARY KEY, payload TEXT NOT NULL, logo $text, version INT NOT NULL DEFAULT 1)",
            'CREATE TABLE IF NOT EXISTS account_controls (user_id INT PRIMARY KEY, active INT NOT NULL DEFAULT 1)',
            'CREATE TABLE IF NOT EXISTS password_resets (token_hash VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, expires BIGINT NOT NULL)',
            "CREATE TABLE IF NOT EXISTS mail_outbox (id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, payload $text, state VARCHAR(20) NOT NULL, attempts INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL, lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), created VARCHAR(35) NOT NULL)",
        ] as $sql) $db->run($sql.($db->mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    }
}
