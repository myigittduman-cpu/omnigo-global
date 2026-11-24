<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only, tenant-scoped product connection and uncounted-order presentation. */
final class InventoryProductState
{
    public function __construct(private Database $db){}
    public function forKeys(int $company,array $keys):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$params=[$company,...$keys];$result=[];
        foreach($this->db->all('SELECT product_key,brand,model FROM inventory_manual_products WHERE company_id=? AND product_key IN ('.$in.')',$params) as $row)$result[$row['product_key']]=['manual'=>true,'brand'=>$row['brand'],'model'=>$row['model']];
        foreach($this->db->all('SELECT product_key,provider,state,reason,match_code,started_at FROM inventory_links WHERE company_id=? AND product_key IN ('.$in.')',$params) as $row)$result[$row['product_key']]['links'][]=['provider'=>$row['provider'],'state'=>$row['state'],'reason'=>$row['reason'],'code'=>$row['match_code'],'startedAt'=>$row['started_at']];
        foreach($this->db->all("SELECT source_product FROM inventory_links WHERE company_id=? AND state='active' AND source_product IN (".$in.')',$params) as $row)$result[$row['source_product']]['linkedSource']=true;
        $card=InventoryStockCard::productKeySql($this->db,'l');
        foreach($this->db->all("SELECT $card product_key,SUM(l.quantity) quantity FROM inventory_pending_lines l WHERE l.company_id=? AND $card IN ($in) GROUP BY $card",$params) as $row)$result[$row['product_key']]['reservedQuantity']=(int)$row['quantity'];
        return $result;
    }
    public function pending(int $company):array
    {
        $card=InventoryStockCard::productKeySql($this->db,'l');
        $rows=$this->db->all(<<<SQL
            SELECT p.product_key AS productKey,p.title,p.sku,SUM(l.quantity) quantity
            FROM inventory_pending_lines l JOIN inventory_products p
              ON p.company_id=l.company_id AND p.product_key=$card
            WHERE l.company_id=? AND p.quantity IS NULL AND TRIM(p.sku)<>''
              AND NOT EXISTS (SELECT 1 FROM inventory_listings c WHERE c.company_id=p.company_id
                AND c.product_key=p.product_key AND c.remote_id NOT LIKE 'order-sku:%')
              AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r
                WHERE r.company_id=p.company_id AND r.product_key=p.product_key)
            GROUP BY p.product_key,p.title,p.sku HAVING SUM(l.quantity)>0 ORDER BY p.title LIMIT 100
            SQL,[$company]);
        return array_map(static fn($row)=>['key'=>$row['productKey'],'title'=>$row['title'],'sku'=>$row['sku'],'quantity'=>(int)$row['quantity']],$rows);
    }
}
