<?php
declare(strict_types=1);
namespace Omnigo;

final class AdminMfaSchema
{
    public static function install(Database $db): void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS admin_mfa_accounts (user_id INT PRIMARY KEY,email VARCHAR(200) NOT NULL,enabled INT NOT NULL DEFAULT 0,updated BIGINT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id))'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS admin_mfa_challenges (token_hash VARCHAR(64) PRIMARY KEY,user_id INT NOT NULL,purpose VARCHAR(12) NOT NULL,code_hash VARCHAR(64) NOT NULL,credential_tag VARCHAR(64) NOT NULL,origin VARCHAR(200) NOT NULL,session_token VARCHAR(64),persistent INT NOT NULL DEFAULT 0,expires BIGINT NOT NULL,attempts INT NOT NULL DEFAULT 0,sent_at BIGINT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id))'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS admin_mfa_sessions (token VARCHAR(64) PRIMARY KEY,user_id INT NOT NULL,expires BIGINT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id))'.$tail);
    }
}
