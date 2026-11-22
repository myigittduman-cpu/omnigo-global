<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit migration only: never installs tables as a side effect of an HTTP read. */
final class InventorySchema
{
    public static function installVerifiedPasses(Database $db):void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS inventory_catalog_verified_passes (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, started_at BIGINT NOT NULL, completed_at BIGINT NOT NULL, PRIMARY KEY(company_id,provider))'.$suffix);
    }
    public static function install(Database $db): void
    {
        self::installVerifiedPasses($db);
        $text=$db->mysql()?'LONGTEXT':'TEXT';
        $tables=[
            "inventory_metadata_jobs (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, page_no INT NOT NULL DEFAULT 0, state VARCHAR(20) NOT NULL DEFAULT 'queued', next_run BIGINT NOT NULL DEFAULT 0, attempts INT NOT NULL DEFAULT 0, error VARCHAR(200), mapped_items INT NOT NULL DEFAULT 0, last_success VARCHAR(35), last_fingerprint VARCHAR(64), lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), PRIMARY KEY(company_id,provider))",
            "inventory_shared_stock (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, adjustment BIGINT NOT NULL DEFAULT 0, manual_quantity BIGINT, PRIMARY KEY(company_id,product_key))",
            "inventory_command_context (company_id INT NOT NULL, request_id VARCHAR(80) NOT NULL, stock_mode VARCHAR(20) NOT NULL, source_version VARCHAR(64), PRIMARY KEY(company_id,request_id))",
            "inventory_purchase_prices (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, amount_cents BIGINT NOT NULL, version BIGINT NOT NULL DEFAULT 1, actor_id INT NOT NULL, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,product_key))",
            "inventory_price_requests (company_id INT NOT NULL, request_id VARCHAR(80) NOT NULL, request_hash VARCHAR(64) NOT NULL, result $text NOT NULL, PRIMARY KEY(company_id,request_id))",
            "inventory_change_events (company_id INT NOT NULL, event_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, actor_id INT NOT NULL, payload $text NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', created VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,event_key))",
            "inventory_removed_products (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, removed_at VARCHAR(35) NOT NULL, actor_id INT NOT NULL, PRIMARY KEY(company_id,product_key))",
            "inventory_catalog_progress (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'queued', started_at BIGINT NOT NULL DEFAULT 0, completed_at BIGINT, heartbeat BIGINT NOT NULL DEFAULT 0, pages_done INT NOT NULL DEFAULT 0, items_done INT NOT NULL DEFAULT 0, total_pages INT, previous_pages INT, unknown_quantities INT NOT NULL DEFAULT 0, lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), PRIMARY KEY(company_id,provider))",
            "inventory_total_state (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, quantity BIGINT NOT NULL, cycle_no BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(company_id,product_key))",
            "inventory_tracking (company_id INT PRIMARY KEY, started_at VARCHAR(35) NOT NULL)",
            "inventory_pending_lines (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, line_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, quantity BIGINT NOT NULL, PRIMARY KEY(company_id,provider,line_key))",
            "inventory_manual_products (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, brand VARCHAR(180) NOT NULL, model VARCHAR(180) NOT NULL, request_hash VARCHAR(64) NOT NULL, PRIMARY KEY(company_id,product_key))",
            "inventory_links (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, provider VARCHAR(32) NOT NULL, source_product VARCHAR(64), match_code VARCHAR(180) NOT NULL, state VARCHAR(20) NOT NULL, reason VARCHAR(500) NOT NULL, requested_at VARCHAR(35) NOT NULL, started_at VARCHAR(35), PRIMARY KEY(company_id,product_key,provider), UNIQUE(company_id,provider,source_product))",
            "inventory_line_tracking (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, line_key VARCHAR(64) NOT NULL, chargeable INT NOT NULL, PRIMARY KEY(company_id,provider,line_key))",
            "inventory_catalog_pages (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, partition_no INT NOT NULL, page_no INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, PRIMARY KEY(company_id,provider,partition_no,page_no))",
            "inventory_catalog_jobs (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, page_no INT NOT NULL DEFAULT 0, partition_no INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL DEFAULT 0, attempts INT NOT NULL DEFAULT 0, last_success VARCHAR(35), error VARCHAR(200), PRIMARY KEY(company_id,provider))",
            "inventory_aliases (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, alias_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, PRIMARY KEY(company_id,provider,alias_key,product_key))",
            "inventory_products (company_id INT NOT NULL, product_key VARCHAR(64) NOT NULL, title VARCHAR(500) NOT NULL, sku VARCHAR(180) NOT NULL, barcode VARCHAR(32) NOT NULL, image_url VARCHAR(2048) NOT NULL, quantity BIGINT, baseline_at VARCHAR(35), version BIGINT NOT NULL DEFAULT 1, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,product_key))",
            "inventory_listings (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, listing_key VARCHAR(64) NOT NULL, remote_id VARCHAR(180) NOT NULL, product_key VARCHAR(64) NOT NULL, sku VARCHAR(180) NOT NULL, evidence $text NOT NULL, source_quantity BIGINT, observed_at VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,provider,listing_key))",
            "inventory_balances (company_id INT NOT NULL, provider VARCHAR(32) NOT NULL, line_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, order_number VARCHAR(180) NOT NULL, sequence_no BIGINT NOT NULL, source_hash VARCHAR(64) NOT NULL, ordered BIGINT NOT NULL, cancelled BIGINT NOT NULL, returned BIGINT NOT NULL, consumed BIGINT NOT NULL, invoice_cents BIGINT, placed_at VARCHAR(35) NOT NULL, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,provider,line_key))",
            "inventory_ledger (company_id INT NOT NULL, event_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, kind VARCHAR(32) NOT NULL, delta BIGINT NOT NULL, quantity_after BIGINT NOT NULL, request_hash VARCHAR(64) NOT NULL, actor_id INT, note VARCHAR(500) NOT NULL, created VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,event_key))",
            "inventory_alerts (company_id INT NOT NULL, event_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, quantity BIGINT NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', created VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,event_key))",
            "inventory_jobs (company_id INT PRIMARY KEY, enabled INT NOT NULL DEFAULT 0, cursor_id BIGINT NOT NULL DEFAULT 0, sequence_no BIGINT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL DEFAULT 0, lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), attempts INT NOT NULL DEFAULT 0, last_success VARCHAR(35), error VARCHAR(200))",
            "inventory_issues (company_id INT NOT NULL, issue_key VARCHAR(64) NOT NULL, provider VARCHAR(32) NOT NULL, order_number VARCHAR(180) NOT NULL, message VARCHAR(500) NOT NULL, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,issue_key))",
            "inventory_commands (company_id INT NOT NULL, request_id VARCHAR(80) NOT NULL, product_key VARCHAR(64) NOT NULL, expected_version BIGINT NOT NULL, mode VARCHAR(16) NOT NULL, amount BIGINT NOT NULL, actor_id INT NOT NULL, note VARCHAR(500) NOT NULL, request_hash VARCHAR(64) NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', result $text, created VARCHAR(35) NOT NULL, updated VARCHAR(35) NOT NULL, PRIMARY KEY(company_id,request_id))",
        ];
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        foreach($tables as $table)$db->run('CREATE TABLE IF NOT EXISTS '.$table.$suffix);
        InventoryImageSchema::install($db);
        TrendyolCatalogSchema::install($db);
        InventoryProductPosition::install($db);
        InventoryPurchaseTax::install($db);
    }
}
