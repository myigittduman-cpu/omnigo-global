<?php
declare(strict_types=1);
namespace Omnigo;

final class TableCatalogReplay
{
    /** Called under the catalogue transaction, only after this exact listing was returned by the provider. */
    public static function restore(Database $db,int $company,string $table,string $key,int $requestedAt): int
    {
        if(!in_array($table,['inventory','fileCenter'],true)||!$db->pdo->inTransaction())throw new \LogicException('Katalog aktarım kapsamı geçersiz.');
        $source=$table==='inventory'?'inventory_removed_products':'file_center_hidden_products';
        if(!IntegrationImportSchema::tableAvailable($db,$source,'product_key'))return 0;
        $mark=$db->one("SELECT removed_at FROM $source WHERE company_id=? AND product_key=?".$db->lock(),[$company,$key]);
        $at=NewRecordBoundary::epoch($mark['removed_at']??null);
        if(!$at||$at>=$requestedAt)return 0;
        if($table==='inventory'){
            foreach(['inventory_commands','inventory_links'] as $pending){
                if($db->one("SELECT product_key FROM $pending WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key]))
                    throw new ProviderRequestDeferred(time()+65);
            }
            $db->run('DELETE FROM inventory_total_state WHERE company_id=? AND product_key=?',[$company,$key]);
            $db->run('UPDATE inventory_products SET version=version+1 WHERE company_id=? AND product_key=?',[$company,$key]);
        }
        return $db->run("DELETE FROM $source WHERE company_id=? AND product_key=? AND removed_at=?",[$company,$key,$mark['removed_at']])->rowCount();
    }
}
