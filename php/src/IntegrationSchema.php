<?php
declare(strict_types=1);
namespace Omnigo;

final class IntegrationSchema
{
    public static function assertReady(Database $db): void
    {
        if (!$db->mysql()) return;
        $column=$db->one("SHOW COLUMNS FROM records LIKE 'kind'");
        foreach(['integration_koctas','sync_koctas','order_koctas'] as $kind) {
            if (!str_contains($column['Type']??'',"'".$kind."'")) Http::fail(503,'Koçtaş bağlantısı için veritabanı hazırlığı tamamlanmalı. Sistem yöneticisi şema güncellemesini uygulamalı.');
        }
    }

    public static function install(Database $db): void
    {
        if (!$db->mysql()) return;
        $column=$db->one("SHOW COLUMNS FROM records LIKE 'kind'");
        $type=$column['Type']??'';
        $required=['integration_koctas','sync_koctas','order_koctas'];
        $complete=true;
        foreach($required as $kind) if (!str_contains($type,"'".$kind."'")) $complete=false;
        if ($complete) return;
        if (!preg_match('/^enum\((\x27[a-z0-9_]+\x27(?:,\x27[a-z0-9_]+\x27)*)\)$/D',$type,$match)) throw new \RuntimeException('Kayıt şeması doğrulanamadı.');
        $values=explode(',',$match[1]);
        foreach($required as $kind) if (!in_array("'".$kind."'",$values,true)) $values[]="'".$kind."'";
        $db->run('ALTER TABLE records MODIFY kind ENUM('.implode(',',$values).') NOT NULL');
    }
}
