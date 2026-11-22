<?php
declare(strict_types=1);
namespace Omnigo;
final class IntegrationAlertSchema
{
    public static function install(Database $db): void
    {
        $columns=$db->mysql()?array_column($db->all('SHOW COLUMNS FROM order_notifications'),'Field'):array_column($db->all('PRAGMA table_info(order_notifications)'),'name');
        if(!in_array('event_type',$columns,true))$db->run("ALTER TABLE order_notifications ADD event_type VARCHAR(16) NOT NULL DEFAULT 'order'");
        if(!in_array('payload',$columns,true))$db->run('ALTER TABLE order_notifications ADD payload TEXT NULL');
        if(!$db->mysql())return;
        $type=$db->one("SHOW COLUMNS FROM records LIKE 'kind'")['Type']??'';
        if(!preg_match('/^enum\((\x27[a-z0-9_]+\x27(?:,\x27[a-z0-9_]+\x27)*)\)$/D',$type,$match))throw new \RuntimeException('Kayıt şeması doğrulanamadı.');
        $values=explode(',',$match[1]);if(!in_array("'integration_health'",$values,true))$db->run("ALTER TABLE records MODIFY kind ENUM(".implode(',',$values).",'integration_health') NOT NULL");
    }
}
