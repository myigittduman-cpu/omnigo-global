<?php
declare(strict_types=1);
namespace Omnigo;
final class MarketplaceConnectionSchema
{
    public static function install(Database $db): void
    {
        if(!$db->mysql())return;
        $type=$db->one("SHOW COLUMNS FROM records LIKE 'kind'")['Type']??'';
        if(!preg_match('/^enum\((\x27[a-z0-9_]+\x27(?:,\x27[a-z0-9_]+\x27)*)\)$/D',$type,$matches))throw new \RuntimeException('Kayıt şeması doğrulanamadı.');
        $values=explode(',',$matches[1]);$before=$values;
        foreach(['integration_pttavm','sync_pttavm','integration_idefix','sync_idefix','integration_trendyol','sync_trendyol'] as $kind)if(!in_array("'".$kind."'",$values,true))$values[]="'".$kind."'";
        if($values!==$before)$db->run('ALTER TABLE records MODIFY kind ENUM('.implode(',',$values).') NOT NULL');
    }
}
