<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceStockSchema
{
    public static function installAccess(Database $db):void
    {
        if(IntegrationImportSchema::tableAvailable($db,'marketplace_model_access','enabled'))return;
        $db->run('CREATE TABLE IF NOT EXISTS marketplace_model_access (company_id INT PRIMARY KEY,enabled INT NOT NULL)'.($db->mysql()?' ENGINE=InnoDB':''));
    }
    public static function install(Database $db):void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $indexes=$db->mysql()?',INDEX marketplace_stock_due(state,next_run,lease_until),INDEX marketplace_stock_listing(company_id,listing_key,state)':'';
        $db->run("CREATE TABLE IF NOT EXISTS marketplace_stock_requests (company_id INT NOT NULL,request_id VARCHAR(80) NOT NULL,product_key VARCHAR(64) NOT NULL,listing_key VARCHAR(64) NOT NULL,sku VARCHAR(180) NOT NULL,remote_id VARCHAR(180) NOT NULL,quantity BIGINT NOT NULL,source_quantity BIGINT NOT NULL,connection_version BIGINT NOT NULL,request_hash VARCHAR(64) NOT NULL,state VARCHAR(20) NOT NULL,receipt_id VARCHAR(100),attempts INT NOT NULL DEFAULT 0,next_run BIGINT NOT NULL DEFAULT 0,lease_until BIGINT NOT NULL DEFAULT 0,lease_token VARCHAR(64),error VARCHAR(500),actor_id INT NOT NULL,created VARCHAR(35) NOT NULL,updated VARCHAR(35) NOT NULL,PRIMARY KEY(company_id,request_id)$indexes)".$tail);
        if(!$db->mysql()){
            $db->run('CREATE INDEX IF NOT EXISTS marketplace_stock_due ON marketplace_stock_requests(state,next_run,lease_until)');
            $db->run('CREATE INDEX IF NOT EXISTS marketplace_stock_listing ON marketplace_stock_requests(company_id,listing_key,state)');
        }
    }
}
