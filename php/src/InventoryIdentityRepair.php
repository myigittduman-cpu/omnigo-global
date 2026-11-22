<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit maintenance plans only. No API route, stock adjustment or marketplace write. */
final class InventoryIdentityRepair
{
    private const BLOCKED=['inventory_ledger','inventory_commands','inventory_change_events','inventory_alerts','inventory_manual_products','inventory_pending_lines','inventory_purchase_prices','inventory_removed_products','inventory_shared_stock','marketplace_stock_requests'];
    private const BACKUP=['inventory_products','inventory_listings','inventory_aliases','inventory_balances','inventory_image_jobs','inventory_total_state'];
    public function __construct(private Database $db){}

    public function apply(int $company,array $expected,array $merges,array $renames,callable $backup):array
    {
        return $this->db->transaction(function()use($company,$expected,$merges,$renames,$backup){
            $this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \DomainException('Müşteri bulunamadı.');
            $keys=array_keys($expected);if(!$keys||count($keys)>30)throw new \DomainException('Düzeltme planı geçersiz.');
            $in=implode(',',array_fill(0,count($keys),'?'));$args=[$company,...$keys];$snapshot=[];
            foreach(self::BACKUP as $table)$snapshot[$table]=$this->db->all("SELECT * FROM $table WHERE company_id=? AND product_key IN ($in)",$args);
            $products=array_column($snapshot['inventory_products'],null,'product_key');
            foreach($expected as $key=>$identity){$row=$products[$key]??null;if(!$row||$row['sku']!==$identity['sku']||$row['barcode']!==$identity['barcode']||$row['quantity']!==null)throw new \DomainException('Ürün kimliği veya manuel stok değişmiş.');}
            foreach(self::BLOCKED as $table)if($this->db->one("SELECT product_key FROM $table WHERE company_id=? AND product_key IN ($in) LIMIT 1",$args))throw new \DomainException('Elle işlenen ürün otomatik birleştirilemez: '.$table);
            if($this->db->one("SELECT product_key FROM inventory_links WHERE company_id=? AND (product_key IN ($in) OR source_product IN ($in)) LIMIT 1",[$company,...$keys,...$keys]))throw new \DomainException('Ürün için farklı bir eşleştirme var.');
            foreach($snapshot['inventory_image_jobs'] as $job)if(($job['lease_until']??0)>time())throw new \DomainException('Ürün görseli işleniyor; sonra tekrar deneyin.');
            $before=$this->totals($company);
            foreach($merges as $source=>$target)if($source===$target||!isset($expected[$source],$expected[$target])||isset($merges[$target]))throw new \DomainException('Birleştirme hedefi geçersiz.');
            foreach($renames as $key=>$sku)if(!isset($expected[$key])||isset($merges[$key])||!preg_match('/^[A-Z0-9.-]{3,40}$/D',$sku))throw new \DomainException('Model düzeltmesi geçersiz.');
            $backup(['company'=>$company,'at'=>gmdate('c'),'rows'=>$snapshot,'merges'=>$merges,'renames'=>$renames]);
            foreach($merges as $source=>$target){
                $aliases=$this->db->all('SELECT * FROM inventory_aliases WHERE company_id=? AND product_key=?',[$company,$source]);
                foreach($aliases as $alias)if(!$this->db->one('SELECT product_key FROM inventory_aliases WHERE company_id=? AND provider=? AND alias_key=? AND product_key=?',[$company,$alias['provider'],$alias['alias_key'],$target]))$this->db->run('INSERT INTO inventory_aliases(company_id,provider,alias_key,product_key) VALUES(?,?,?,?)',[$company,$alias['provider'],$alias['alias_key'],$target]);
                foreach(['inventory_listings','inventory_balances'] as $table)$this->db->run("UPDATE $table SET product_key=? WHERE company_id=? AND product_key=?",[$target,$company,$source]);
                if($products[$target]['image_url']===''&&$products[$source]['image_url']!==''){
                    $this->db->run('UPDATE inventory_products SET image_url=? WHERE company_id=? AND product_key=?',[$products[$source]['image_url'],$company,$target]);
                    $products[$target]['image_url']=$products[$source]['image_url'];
                }
                foreach(['inventory_aliases','inventory_image_jobs','inventory_total_state','inventory_products'] as $table)$this->db->run("DELETE FROM $table WHERE company_id=? AND product_key=?",[$company,$source]);
            }
            foreach($renames as $key=>$sku)$this->db->run('UPDATE inventory_products SET sku=?,version=version+1 WHERE company_id=? AND product_key=?',[$sku,$company,$key]);
            if($this->totals($company)!==$before)throw new \RuntimeException('Stok veya satış hareketleri değişti; işlem geri alındı.');
            $result=['merged'=>count($merges),'renamed'=>count($renames),'listingAndBalanceTotalsPreserved'=>true];
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,NULL,?,?,?)',[$company,'Ürün kimlikleri düzeltildi (sistem bakımı)',json_encode(['keys'=>$keys]),json_encode($result),gmdate('c')]);
            return $result;
        });
    }
    private function totals(int $company):array
    {
        return ['listings'=>$this->db->one('SELECT COUNT(*) n,COALESCE(SUM(source_quantity),0) quantity FROM inventory_listings WHERE company_id=?',[$company]),'balances'=>$this->db->one('SELECT COUNT(*) n,COALESCE(SUM(ordered),0) ordered,COALESCE(SUM(cancelled),0) cancelled,COALESCE(SUM(returned),0) returned,COALESCE(SUM(consumed),0) consumed,COALESCE(SUM(invoice_cents),0) invoice FROM inventory_balances WHERE company_id=?',[$company])];
    }
}
