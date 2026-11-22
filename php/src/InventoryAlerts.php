<?php
declare(strict_types=1);
namespace Omnigo;

/** A threshold crossing is delivered once, through the existing device queue. */
final class InventoryAlerts
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function publish(int $company): int
    {
        (new InventoryChangeEvents($this->db))->publish($company,$this->cipher);
        (new InventoryTotalMonitor($this->db))->refresh($company);
        return $this->db->transaction(function()use($company){
            $rows=$this->db->all("SELECT * FROM inventory_alerts WHERE company_id=? AND state='pending' ORDER BY created LIMIT 30".$this->db->lock(),[$company]);$count=0;
            foreach($rows as $row){
                $product=$this->db->one('SELECT title,sku,quantity FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$row['product_key']]);
                if(!$product||(new InventoryVisibility($this->db))->removed($company,$row['product_key'])){$this->finish($company,$row['event_key'],'suppressed');continue;}
                $snapshots=(new InventorySourceStock($this->db))->forKeys($company,[$row['product_key']]);
                $mode=(new InventorySettings($this->db))->preferences($company)['mode'];
                $shared=$mode==='horoz'?(new InventorySharedStock($this->db))->forKeys($company,[$row['product_key']]):[];
                $total=InventoryTotals::calculate($mode,$product['quantity']===null?null:(int)$product['quantity'],$snapshots[$row['product_key']]??[],$shared[$row['product_key']]??[])['verifiedTotalQuantity'];
                if($total===null)continue;
                $restocked=(int)$row['quantity']>0;
                if(($total>0)!==$restocked){$this->finish($company,$row['event_key'],'suppressed');continue;}
                $detail=['events'=>[$restocked?'inventory_restocked':'inventory_low'],'section'=>'inventory','stockMode'=>$mode,'productTitle'=>$product['title'],'sku'=>$product['sku'],'quantity'=>$total,'productKey'=>$row['product_key'],'audience'=>'admin'];
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(NULL,?,?,?,?,?)',[$company,'inventory.alert',0,'null',gmdate('c')]);$id=$this->db->insertId();
                $this->db->run('UPDATE audit SET after_value=? WHERE id=?',[$this->cipher->seal($company,'operation-mail:'.$id,$detail),$id]);
                (new OperationalNotifications($this->db))->publish($company,'operation',$id,'portal',$detail);
                $this->finish($company,$row['event_key'],'sent');$count++;
            }
            return $count;
        });
    }
    private function finish(int $company,string $key,string $state):void{$this->db->run('UPDATE inventory_alerts SET state=? WHERE company_id=? AND event_key=?',[$state,$company,$key]);}
}
