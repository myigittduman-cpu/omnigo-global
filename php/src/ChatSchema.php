<?php
declare(strict_types=1);
namespace Omnigo;

final class ChatSchema
{
    public static function install(Database $db):void
    {
        $mysql = $db->mysql();
        $id = $mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $tables = [
            'chat_threads' => "id $id, identity_key VARCHAR(64) NOT NULL UNIQUE, company_id INT, title VARCHAR(100) NOT NULL, kind VARCHAR(12) NOT NULL, created BIGINT NOT NULL, updated BIGINT NOT NULL",
            'chat_members' => 'thread_id BIGINT NOT NULL, user_id INT NOT NULL, read_id BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(thread_id,user_id)',
            'chat_messages' => "id $id, thread_id BIGINT NOT NULL, sender_id INT NOT NULL, nonce VARCHAR(64) NOT NULL, body TEXT NOT NULL, created BIGINT NOT NULL, deleted_at BIGINT, UNIQUE(thread_id,sender_id,nonce)",
            'chat_presence' => 'user_id INT PRIMARY KEY, last_seen BIGINT NOT NULL',
            'chat_push_refs' => 'job_id VARCHAR(64) PRIMARY KEY, message_id BIGINT NOT NULL',
        ];
        $indexes = [
            'chat_members' => ['chat_member_user' => 'user_id,thread_id'],
            'chat_messages' => ['chat_thread_message' => 'thread_id,id', 'chat_sender_time' => 'sender_id,created'],
        ];
        foreach ($tables as $table => $columns) {
            $tableIndexes = $indexes[$table] ?? [];
            if ($mysql) {
                foreach ($tableIndexes as $name => $fields) $columns .= ", KEY $name ($fields)";
            }
            $db->pdo->exec("CREATE TABLE IF NOT EXISTS $table ($columns)$suffix");
            if (!$mysql) {
                foreach ($tableIndexes as $name => $fields) $db->pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($fields)");
            }
        }
        self::installThreadDismissals($db);
    }

    public static function installThreadDismissals(Database $db): void
    {
        $suffix = $db->mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->pdo->exec('CREATE TABLE IF NOT EXISTS chat_thread_dismissals (
            thread_id BIGINT NOT NULL, user_id INT NOT NULL, through_id BIGINT NOT NULL,
            PRIMARY KEY(thread_id,user_id)
        )'.$suffix);
    }
}
