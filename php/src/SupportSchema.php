<?php
declare(strict_types=1);
namespace Omnigo;

final class SupportSchema
{
    public static function migrate(Database $db): void
    {
        $identity=$db->mysql()?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id $identity, company_id INT NOT NULL, created_by INT NOT NULL,
            request_id VARCHAR(48) NOT NULL, subject VARCHAR(150) NOT NULL,
            category VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL,
            created VARCHAR(35) NOT NULL, updated VARCHAR(35) NOT NULL,
            UNIQUE(company_id,created_by,request_id),
            FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(created_by) REFERENCES users(id)
        )$engine");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
            id $identity, ticket_id INT NOT NULL, user_id INT NOT NULL,
            request_id VARCHAR(48) NOT NULL, sender_role VARCHAR(10) NOT NULL,
            body TEXT NOT NULL, created VARCHAR(35) NOT NULL,
            UNIQUE(ticket_id,user_id,request_id),
            FOREIGN KEY(ticket_id) REFERENCES support_tickets(id), FOREIGN KEY(user_id) REFERENCES users(id)
        )$engine");
        if($db->mysql()) {
            foreach(['support_tickets'=>['support_company_updated','company_id,updated'],'support_messages'=>['support_thread_messages','ticket_id,id']] as $table=>$index) {
                if(!$db->one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$index[0]])) $db->pdo->exec("CREATE INDEX {$index[0]} ON $table ({$index[1]})");
            }
        } else {
            $db->pdo->exec('CREATE INDEX IF NOT EXISTS support_company_updated ON support_tickets(company_id,updated)');
            $db->pdo->exec('CREATE INDEX IF NOT EXISTS support_thread_messages ON support_messages(ticket_id,id)');
        }
    }
}
