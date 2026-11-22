<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentSchema
{
    public static function install(Database $db): void
    {
        $id=$db->mysql()?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY';
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS shipment_entries (id $id, company_id INT NOT NULL, city VARCHAR(40) NOT NULL, provider VARCHAR(30) NOT NULL, month VARCHAR(7) NOT NULL, payload MEDIUMTEXT NOT NULL, completed INT NOT NULL DEFAULT 0, invoiced INT NOT NULL DEFAULT 0, version INT NOT NULL DEFAULT 1, created VARCHAR(35) NOT NULL, updated VARCHAR(35) NOT NULL, FOREIGN KEY(company_id) REFERENCES companies(id))".$tail);
        $columns=array_column($db->all($db->mysql()?'SHOW COLUMNS FROM shipment_entries':'PRAGMA table_info(shipment_entries)'),$db->mysql()?'Field':'name');
        if(!in_array('ordered_at',$columns,true))$db->run("ALTER TABLE shipment_entries ADD COLUMN ordered_at VARCHAR(20) NOT NULL DEFAULT ''");
        if(!in_array('cancelled',$columns,true))$db->run('ALTER TABLE shipment_entries ADD COLUMN cancelled INT NOT NULL DEFAULT 0');
        if(!in_array('shipped',$columns,true))$db->run('ALTER TABLE shipment_entries ADD COLUMN shipped INT NOT NULL DEFAULT 0');
        if(!in_array('row_status',$columns,true))$db->run('ALTER TABLE shipment_entries ADD COLUMN row_status VARCHAR(12) NULL DEFAULT NULL');
        foreach(['manual_user_id'=>'INT','create_request'=>'VARCHAR(48)','create_hash'=>'VARCHAR(64)','removed_at'=>'VARCHAR(35)','undo_request'=>'VARCHAR(48)','source_key'=>'VARCHAR(64)'] as $column=>$type){
            if(!in_array($column,$columns,true))$db->run("ALTER TABLE shipment_entries ADD COLUMN $column $type NULL DEFAULT NULL");
        }
        foreach(['create_request','undo_request','source_key'] as $column){
            $name='shipment_unique_'.$column;
            if(!$db->mysql())$db->run("CREATE UNIQUE INDEX IF NOT EXISTS $name ON shipment_entries(company_id,$column)");
            elseif(!$db->one("SHOW INDEX FROM shipment_entries WHERE Key_name=?",[$name]))$db->run("CREATE UNIQUE INDEX $name ON shipment_entries(company_id,$column)");
        }
        if(!$db->mysql())$db->run('CREATE INDEX IF NOT EXISTS shipment_chronology ON shipment_entries(company_id,city,provider,month,ordered_at,id)');
        elseif(!$db->one("SHOW INDEX FROM shipment_entries WHERE Key_name='shipment_chronology'"))$db->run('CREATE INDEX shipment_chronology ON shipment_entries(company_id,city,provider,month,ordered_at,id)');
        if(!$db->mysql())$db->run('CREATE INDEX IF NOT EXISTS shipment_selection ON shipment_entries(company_id,city,provider,month,id)');
        elseif(!$db->one("SHOW INDEX FROM shipment_entries WHERE Key_name='shipment_selection'"))$db->run('CREATE INDEX shipment_selection ON shipment_entries(company_id,city,provider,month,id)');
    }
}
