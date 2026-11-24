<?php
declare(strict_types=1);
namespace Omnigo;

/** Exact merchant SKU is the only identity used to select a product card. */
final class InventorySkuIdentity
{
    public function __construct(private Database $db){}

    public function catalogKey(int $company,string $sku):?string
    {
        $code=InventoryLiveSkuIdentity::normalize($sku);
        if($code==='')return null;
        // The final PHP comparison is exact even under an accent-insensitive database collation.
        $unicode=preg_match('/[^\x20-\x7e]/',$sku)===1;
        $where=$unicode?'':' AND (UPPER(TRIM(p.sku))=? OR TRIM(p.sku)=?)';
        $parameters=$unicode?[$company]:[$company,$code,trim($sku)];
        $rows=$this->db->all("SELECT p.product_key,p.sku FROM inventory_products p WHERE p.company_id=?$where
            ORDER BY CASE WHEN p.quantity IS NULL THEN 1 ELSE 0 END,p.product_key",$parameters);
        foreach($rows as $row)if(InventoryLiveSkuIdentity::normalize($row['sku'])===$code)return $row['product_key'];
        return null;
    }
}
