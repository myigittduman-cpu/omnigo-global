<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit additive tables; unknown tables still stop a backup rather than disappear. */
final class BackupSchemaExtension
{
    public const TABLES=['integration_import_runs','account_profiles','account_audit','inventory_removed_products','inventory_catalog_progress','inventory_metadata_jobs','inventory_total_state','inventory_tracking','inventory_pending_lines','inventory_manual_products','inventory_links','inventory_line_tracking','inventory_catalog_pages','inventory_catalog_jobs','inventory_aliases','inventory_products','inventory_product_positions','inventory_purchase_tax','inventory_trendyol_checkpoints','inventory_listings','inventory_balances','inventory_ledger','inventory_alerts','inventory_jobs','inventory_issues','inventory_commands','inventory_image_jobs','inventory_shared_stock','inventory_command_context','inventory_purchase_prices','inventory_price_requests','inventory_change_events','operation_dossiers','operation_attention','operation_attention_sources','marketplace_admin_lock','marketplace_admin_grants','chat_threads','chat_members','chat_messages','chat_presence','chat_push_refs','chat_thread_dismissals',
        'profitability_activation','profitability_price_versions','profitability_orders','profitability_lines','profitability_cost_rules','profitability_line_products','upcoming_payout_reviews',
        FileCenterSchema::ARCHIVES,FileCenterSchema::HIDDEN_PRODUCTS];

    /** A restore keeps business history while requiring explicit resumption of stock jobs. */
    public static function restoredRow(string $table,array $row):array
    {
        if($table==='marketplace_admin_grants')$row['expires']=0;
        if($table==='inventory_jobs')$row=[...$row,'enabled'=>0,'lease_until'=>0,'lease_token'=>null,'cursor_id'=>0,'error'=>'Geri yükleme sonrası stok aktarımını kontrol edip yeniden başlatın.'];
        if($table==='inventory_metadata_jobs')$row=[...$row,'lease_until'=>0,'lease_token'=>null];
        if($table==='inventory_catalog_progress')$row=[...$row,'lease_until'=>0,'lease_token'=>null];
        if($table==='inventory_image_jobs')$row=[...$row,'state'=>$row['state']==='running'?'retry':$row['state'],'lease_until'=>0,'lease_token'=>null];
        if($table==='inventory_commands'&&$row['state']==='pending')$row=[...$row,'state'=>'failed','result'=>json_encode(['error'=>'Yedekten gelen bekleyen stok işlemi uygulanmadı. Güncel miktarı kontrol ederek yeniden kaydedin.'],JSON_THROW_ON_ERROR)];
        if($table==='inventory_change_events'&&$row['state']==='pending')$row['state']='cancelled';
        return $row;
    }
}


