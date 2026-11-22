<?php
declare(strict_types=1);
namespace Omnigo;

final class AccountSettingsSchema
{
    public static function install(Database $db): void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS user_preferences (user_id BIGINT NOT NULL, profile_key CHAR(64) NOT NULL, payload TEXT NOT NULL, version INT NOT NULL, updated BIGINT NOT NULL, PRIMARY KEY(user_id,profile_key))'.$suffix);
        $db->run('CREATE TABLE IF NOT EXISTS password_change_challenges (user_id BIGINT PRIMARY KEY, token_hash CHAR(64) NOT NULL, session_token CHAR(64) NOT NULL, credential_tag CHAR(64) NOT NULL, password_hash VARCHAR(255) NOT NULL, code_hash CHAR(64) NOT NULL, expires BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0, sent_at BIGINT NOT NULL)'.$suffix);
        $db->run('CREATE TABLE IF NOT EXISTS application_preferences (preference_key VARCHAR(64) PRIMARY KEY, preference_value VARCHAR(255) NOT NULL, version BIGINT NOT NULL, updated BIGINT NOT NULL)'.$suffix);
        $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $legacyDisabled=is_file(dirname(__DIR__).'/storage/operation-mail.disabled');
        $db->run("$insert INTO application_preferences(preference_key,preference_value,version,updated) VALUES('operation_mail_enabled',?,1,?)",[$legacyDisabled?'0':'1',time()]);
    }
}
