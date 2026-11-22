<?php
declare(strict_types=1);
namespace Omnigo;

/** Presentation order is separate from quantities, listings and import cursors. */
final class InventoryProductPosition
{
    public static function install(Database $db): void
    {
        $identity=$db->mysql()?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $collation=$db->mysql()?$db->one("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND COLUMN_NAME='product_key'")['COLLATION_NAME']:null;
        if($db->mysql()&&(!is_string($collation)||!preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation)))throw new \RuntimeException('inventory_collation_unavailable');
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE '.$collation:'';
        if(!self::available($db))$db->run('CREATE TABLE inventory_product_positions (
            position_no '.$identity.',company_id INT NOT NULL,product_key VARCHAR(64) NOT NULL,
            UNIQUE(company_id,product_key)
        )'.$suffix);
        // Preserve the current initial order; later products append, even when a SKU changes.
        $sql='INSERT INTO inventory_product_positions(company_id,product_key)
            SELECT p.company_id,p.product_key FROM inventory_products p
            WHERE 1=1
            ORDER BY p.company_id,CASE WHEN TRIM(p.sku)=\'\' THEN 1 ELSE 0 END,p.sku,p.product_key';
        $sql.=$db->mysql()?' ON DUPLICATE KEY UPDATE product_key=VALUES(product_key)'
            :' ON CONFLICT(company_id,product_key) DO NOTHING';
        $db->run($sql);
    }

    public static function available(Database $db): bool
    {
        try{$db->one('SELECT position_no FROM inventory_product_positions LIMIT 1');return true;}
        catch(\PDOException $error){
            $code=(int)($error->errorInfo[1]??0);
            if(($db->mysql()&&$code===1146)||(!$db->mysql()&&str_contains($error->getMessage(),'no such table: inventory_product_positions')))return false;
            throw $error;
        }
    }

    public static function remember(Database $db,int $company,string $key): void
    {
        if(!self::available($db))return;
        $conflict=$db->mysql()?' ON DUPLICATE KEY UPDATE product_key=VALUES(product_key)'
            :' ON CONFLICT(company_id,product_key) DO NOTHING';
        $db->run('INSERT INTO inventory_product_positions(company_id,product_key) VALUES(?,?)'.$conflict,[$company,$key]);
    }
}
