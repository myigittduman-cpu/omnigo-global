<?php
declare(strict_types=1);
namespace Omnigo;
final class OrderDocumentSchema
{
    public static function ensure(Database $db):void
    {
        try{$db->one('SELECT company_id FROM order_documents LIMIT 1');}
        catch(\PDOException $error){
            $code=(int)($error->errorInfo[1]??0);
            if(($db->mysql()&&$code!==1146)||(!$db->mysql()&&!str_contains($error->getMessage(),'no such table')))throw $error;
            self::install($db);
        }
    }
    public static function install(Database $db): void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS order_documents (company_id INT NOT NULL, reference_key CHAR(64) NOT NULL, kind VARCHAR(12) NOT NULL, version INT NOT NULL DEFAULT 1, payload TEXT NOT NULL, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,reference_key,kind), FOREIGN KEY(company_id) REFERENCES companies(id))".$tail);
    }
}
