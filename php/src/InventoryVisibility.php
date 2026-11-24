<?php
declare(strict_types=1);
namespace Omnigo;

/** Removing a row never deletes its source listings, stock history or orders. */
final class InventoryVisibility
{
    public function __construct(private Database $db) {}

    public function removed(int $company, string $key): bool
    {
        return (bool)$this->db->one('SELECT product_key FROM inventory_removed_products WHERE company_id=? AND product_key=?', [$company,$key]);
    }

    public function requireVisible(int $company, string $key): void
    {
        if (InventoryStockCard::primaryKey($this->db,$company,$key)!==$key)throw new \DomainException('Stok kartı güncellendi. Listeyi yenileyip tekrar deneyin.');
        if ($this->removed($company,$key)) throw new \DomainException('Bu ürün tablodan kaldırıldı. Önce ürünü geri alın.');
    }

    public function change(int $company, array $input, int $actor): array
    {
        $action=$input['action']??null;
        $products=$input['products']??[['productKey'=>$input['productKey']??null,'version'=>$input['version']??null]];
        if (!in_array($action,['remove','restore'],true)||!is_array($products)||!array_is_list($products)||!count($products)||count($products)>200) {
            throw new \InvalidArgumentException('İşlemi ve seçili ürünleri kontrol edin. En fazla 200 ürün seçilebilir.');
        }
        $seen=[];
        foreach($products as $item) {
            if (!is_array($item)) throw new \InvalidArgumentException('Seçili ürün bilgisini kontrol edin.');
            $key=$item['productKey']??null; $version=$item['version']??null;
            if (!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||isset($seen[$key])||!is_int($version)||$version<1) {
                throw new \InvalidArgumentException('Ürün ve sürüm bilgisini kontrol edin.');
            }
            $seen[$key]=true;
        }
        return $this->db->transaction(function() use($company,$products,$action,$actor) {
            $this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            // Validate the complete selection before changing any row; failures roll back the batch.
            foreach($products as $item) {
            $key=$item['productKey']; $version=$item['version'];
            $product=(new InventoryStock($this->db))->product($company,$key);
            if (InventoryStockCard::primaryKey($this->db,$company,$key)!==$key||(int)$product['version']!==$version) throw new \DomainException('Ürün güncellendi. Listeyi yenileyip tekrar deneyin.');
            if ($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key])) {
                throw new \DomainException('Bekleyen stok işlemi tamamlandıktan sonra tekrar deneyin.');
            }
            if ($this->db->one("SELECT product_key FROM inventory_links WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key])) {
                throw new \DomainException('Ürünün pazaryeri eşleştirmesi tamamlandıktan sonra tekrar deneyin.');
            }
            }
            foreach($products as $item) {
            $key=$item['productKey'];
            $removed=$this->removed($company,$key);
            if ($action==='remove'&&!$removed) {
                $this->db->run('INSERT INTO inventory_removed_products(company_id,product_key,removed_at,actor_id) VALUES(?,?,?,?)',[$company,$key,gmdate('c'),$actor]);
                $this->db->run("UPDATE inventory_alerts SET state='suppressed' WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key]);
            } elseif ($action==='restore'&&$removed) {
                $this->db->run('DELETE FROM inventory_removed_products WHERE company_id=? AND product_key=?',[$company,$key]);
                // The first visible source quantity is a baseline, independent of the list restoration.
                $this->db->run('DELETE FROM inventory_total_state WHERE company_id=? AND product_key=?',[$company,$key]);
            }
            if (($action==='remove')!==$removed) {
                $this->db->run('UPDATE inventory_products SET version=version+1 WHERE company_id=? AND product_key=?',[$company,$key]);
            }
            }
            return ['removed'=>$action==='remove','count'=>count($products)];
        });
    }
}
