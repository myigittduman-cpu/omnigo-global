<?php
declare(strict_types=1);
namespace Omnigo;

/** Alerts follow verified combined stock, not stale snapshots or an individual marketplace. */
final class InventoryTotalMonitor
{
    public function __construct(private Database $db){}
    public function refresh(int $company):void
    {
        $mode=(new InventorySettings($this->db))->preferences($company)['mode'];
        $rows=$this->db->all("SELECT p.product_key,p.quantity FROM inventory_products p WHERE p.company_id=? AND TRIM(p.sku)<>'' AND ".InventoryStockCard::primaryProductSql($this->db)." AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=p.company_id AND r.product_key=p.product_key)",[$company]);
        foreach(array_chunk($rows,100) as $batch){
            $snapshots=(new InventorySourceStock($this->db))->forKeys($company,array_column($batch,'product_key'));
            $shared=$mode==='horoz'?(new InventorySharedStock($this->db))->forKeys($company,array_column($batch,'product_key')):[];
            foreach($batch as $row){
                $quantity=InventoryTotals::calculate($mode,$row['quantity']===null?null:(int)$row['quantity'],$snapshots[$row['product_key']]??[],$shared[$row['product_key']]??[])['verifiedTotalQuantity'];
                if($quantity===null)continue;
                $this->observe($company,$row['product_key'],$quantity);
            }
        }
    }
    public function observe(int $company,string $key,int $quantity):void
    {
        $this->db->transaction(function()use($company,$key,$quantity){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if((new InventoryVisibility($this->db))->removed($company,$key))return;
            $old=$this->db->one('SELECT quantity,cycle_no FROM inventory_total_state WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$key]);
            if(!$old){$this->db->run('INSERT INTO inventory_total_state(company_id,product_key,quantity) VALUES(?,?,?)',[$company,$key,$quantity]);return;}
            $previousQuantity=(int)$old['quantity'];
            if($previousQuantity===$quantity)return;
            $soldOut=$previousQuantity>0&&$quantity<=0;
            $restocked=$previousQuantity<=0&&$quantity>0;
            $cycle=(int)$old['cycle_no']+(int)$soldOut;
            $this->db->run('UPDATE inventory_total_state SET quantity=?,cycle_no=? WHERE company_id=? AND product_key=?',[$quantity,$cycle,$company,$key]);
            if(!$soldOut&&!$restocked)return;
            // A queued opposite transition no longer describes the verified stock. Both changes commit together.
            $this->db->run("UPDATE inventory_alerts SET state='suppressed' WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key]);
            $this->db->run('INSERT INTO inventory_alerts(company_id,event_key,product_key,quantity,created) VALUES(?,?,?,?,?)',
                [$company,bin2hex(random_bytes(32)),$key,$quantity,gmdate('c')]);
        });
    }
}
