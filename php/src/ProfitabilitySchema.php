<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit, additive installation. Capture starts only after every writer is in place. */
final class ProfitabilitySchema
{
    public static function install(Database $db): void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $timeIndex=$db->mysql()?', INDEX(company_id,ordered_at,provider,external_id)':'';
        $priceIndex=$db->mysql()?', INDEX(company_id,product_key,effective_at,version)':'';
        $tables=[
            'profitability_activation (id INT PRIMARY KEY, started_at BIGINT NOT NULL)',
            "profitability_price_versions (company_id INT NOT NULL, product_key CHAR(64) NOT NULL, version BIGINT NOT NULL,
                effective_at BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
                PRIMARY KEY(company_id,product_key,version), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE$priceIndex)",
            "profitability_orders (company_id INT NOT NULL, provider VARCHAR(20) NOT NULL, external_id VARCHAR(180) NOT NULL,
                order_number VARCHAR(180) NOT NULL, ordered_at BIGINT NOT NULL,
                PRIMARY KEY(company_id,provider,external_id), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE$timeIndex)",
            "profitability_lines (company_id INT NOT NULL, provider VARCHAR(20) NOT NULL, external_id VARCHAR(180) NOT NULL,
                line_key CHAR(64) NOT NULL, match_key CHAR(64) NOT NULL, sku VARCHAR(180) NOT NULL, title VARCHAR(1000) NOT NULL,
                quantity INT NOT NULL, unit_purchase_cents BIGINT,
                PRIMARY KEY(company_id,provider,external_id,line_key),
                FOREIGN KEY(company_id,provider,external_id) REFERENCES profitability_orders(company_id,provider,external_id) ON DELETE CASCADE)",
        ];
        foreach($tables as $table)$db->run('CREATE TABLE IF NOT EXISTS '.$table.$tail);
        if(!$db->mysql()){
            $db->run('CREATE INDEX IF NOT EXISTS profitability_order_time ON profitability_orders(company_id,ordered_at,provider,external_id)');
            $db->run('CREATE INDEX IF NOT EXISTS profitability_price_time ON profitability_price_versions(company_id,product_key,effective_at,version)');
        }
        self::installCostHistory($db);
    }

    public static function installCostHistory(Database $db): void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS profitability_cost_rules (company_id INT NOT NULL, product_key CHAR(64) NOT NULL, version BIGINT NOT NULL,
            starts_at BIGINT NOT NULL, amount_cents BIGINT NOT NULL,
            PRIMARY KEY(company_id,product_key,version), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)'.$tail);
        $db->run('CREATE TABLE IF NOT EXISTS profitability_line_products (company_id INT NOT NULL, provider VARCHAR(20) NOT NULL, external_id VARCHAR(180) NOT NULL,
            line_key CHAR(64) NOT NULL, product_key CHAR(64) NOT NULL,
            PRIMARY KEY(company_id,provider,external_id,line_key), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)'.$tail);
    }

    public static function activate(Database $db): void
    {
        // Round up so an order recorded at second resolution can never predate activation.
        $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $db->run($insert.' INTO profitability_activation(id,started_at) VALUES(1,?)',[time()+1]);
    }

    public static function startedAt(Database $db): ?int
    {
        if(!IntegrationImportSchema::tableAvailable($db,'profitability_activation','started_at'))return null;
        $row=$db->one('SELECT started_at FROM profitability_activation WHERE id=1');
        return $row===null?null:(int)$row['started_at'];
    }
}
