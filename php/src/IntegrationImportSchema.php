<?php
declare(strict_types=1);
namespace Omnigo;

/** Release-time additive migration. Workers and read routes never create tables. */
final class IntegrationImportSchema
{
    public static function available(Database $db):bool
    {return self::tableAvailable($db,'integration_import_runs','run_id');}
    public static function tableAvailable(Database $db,string $table,string $column):bool
    {
        if(!preg_match('/^[a-z_]+$/D',$table)||!preg_match('/^[a-z_]+$/D',$column))throw new \LogicException('Geçersiz tablo alanı.');
        try{$db->one("SELECT $column FROM $table LIMIT 1");return true;}
        catch(\PDOException $error){
            if(($db->mysql()&&(int)($error->errorInfo[1]??0)===1146)||(!$db->mysql()&&str_contains($error->getMessage(),'no such table')))return false;
            throw $error;
        }
    }
    public static function ensure(Database $db):void{if(!self::available($db))self::install($db);}
    public static function install(Database $db):void
    {
        $text=$db->mysql()?'LONGTEXT':'TEXT';$tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS integration_import_runs (run_id CHAR(32) PRIMARY KEY, company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, credential_version INT NOT NULL, credential_revision VARCHAR(64) NOT NULL, state VARCHAR(20) NOT NULL, started_at VARCHAR(35) NOT NULL, updated_at VARCHAR(35) NOT NULL, finished_at VARCHAR(35), baseline $text NOT NULL, report $text NOT NULL, failure_notice_id BIGINT, success_notice_id BIGINT, UNIQUE(company_id,provider,credential_version), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
    }
}
