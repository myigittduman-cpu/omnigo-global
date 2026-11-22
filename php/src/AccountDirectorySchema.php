<?php
declare(strict_types=1);
namespace Omnigo;

final class AccountDirectorySchema
{
    public static function ensure(Database $db):void
    {
        try{$db->one('SELECT user_id FROM account_profiles LIMIT 1');$db->one('SELECT id FROM account_audit LIMIT 1');return;}catch(\PDOException $error){if(!in_array((string)$error->getCode(),['42S02','HY000'],true))throw $error;}
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS account_profiles (user_id BIGINT PRIMARY KEY, avatar MEDIUMTEXT, version INT NOT NULL DEFAULT 1, deleted_at BIGINT)'.$suffix);
        $id=$db->mysql()?'BIGINT AUTO_INCREMENT':'INTEGER';
        $db->run("CREATE TABLE IF NOT EXISTS account_audit (id $id PRIMARY KEY, actor_id BIGINT NOT NULL, target_id BIGINT NOT NULL, action VARCHAR(160) NOT NULL, created BIGINT NOT NULL)".$suffix);
    }
}
