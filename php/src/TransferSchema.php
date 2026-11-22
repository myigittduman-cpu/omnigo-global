<?php
declare(strict_types=1);
namespace Omnigo;

/** Additive migration. Existing kinds, orders and manual shipment rows are retained. */
final class TransferSchema
{
    public const KINDS=['shipment_auto_since','sync_n11_questions','sync_idefix_questions'];
    public static function install(Database $db): void
    {
        if(!$db->mysql())return;
        $type=$db->one("SHOW COLUMNS FROM records LIKE 'kind'")['Type']??'';
        if(!preg_match('/^enum\((\x27[a-z0-9_]+\x27(?:,\x27[a-z0-9_]+\x27)*)\)$/D',$type,$match))throw new \RuntimeException('Kayıt şeması doğrulanamadı.');
        $values=explode(',',$match[1]);$added=[];
        foreach(self::KINDS as $kind)if(!in_array("'$kind'",$values,true))$added[]="'$kind'";
        if($added)$db->run('ALTER TABLE records MODIFY kind ENUM('.implode(',',[...$values,...$added]).') NOT NULL');
    }
}
