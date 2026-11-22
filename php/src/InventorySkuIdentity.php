<?php
declare(strict_types=1);
namespace Omnigo;

/** Exact merchant SKU is tenant-owned; supplier GTIN differences are retained as source evidence. */
final class InventorySkuIdentity
{
    public function __construct(private Database $db){}
    public function catalogKey(int $company,string $sku,string $barcode=''):?string
    {
        // A known exact GTIN may use a different merchant SKU. Keep that real model
        // separate from a mistyped supplier code rather than joining unrelated models.
        if(InventoryCatalog::validGtin($barcode)){
            $known=$this->db->all("SELECT p.product_key FROM inventory_products p WHERE p.company_id=? AND p.barcode IN (?,?,?) AND NOT EXISTS(SELECT 1 FROM inventory_links k WHERE k.company_id=p.company_id AND k.source_product=p.product_key AND k.state='active') ORDER BY p.product_key LIMIT 2",[$company,$barcode,str_pad($barcode,14,'0',STR_PAD_LEFT),ltrim($barcode,'0')]);
            if(count($known)===1)return $known[0]['product_key'];
        }
        $sku=mb_strtoupper(trim($sku));if($sku==='')return null;
        $rows=$this->db->all("SELECT DISTINCT COALESCE(k.product_key,l.product_key) product_key,l.sku
            FROM inventory_listings l LEFT JOIN inventory_links k ON k.company_id=l.company_id
            AND k.provider=l.provider AND k.source_product=l.product_key AND k.state='active'
            JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=COALESCE(k.product_key,l.product_key)
            WHERE l.company_id=? AND UPPER(TRIM(l.sku))=? AND UPPER(TRIM(p.sku))=? ORDER BY product_key",[$company,$sku,$sku]);
        // Existing ambiguous history is reconciled separately, never multiplied by a new listing.
        foreach($rows as $row)if(mb_strtoupper(trim($row['sku']))===$sku)return $row['product_key'];
        return null;
    }
    /** Disambiguate existing aliases only by an exact known model token, never a fuzzy title. */
    public function orderKey(int $company,array $keys,string $title):?string
    {
        if(count($keys)===1)return $keys[0];
        if(!$keys||trim($title)==='')return null;
        $in=implode(',',array_fill(0,count($keys),'?'));$matches=[];
        foreach($this->db->all("SELECT product_key,sku FROM inventory_products WHERE company_id=? AND product_key IN ($in)",[$company,...$keys]) as $row){
            $sku=mb_strtoupper(trim($row['sku']));
            if($sku!==''&&preg_match('/(?<![A-Z0-9])'.preg_quote($sku,'/').'(?![A-Z0-9])/u',mb_strtoupper($title)))$matches[]=$row['product_key'];
        }
        return count($matches)===1?$matches[0]:null;
    }
    public function aliasKeys(int $company,string $provider,string $alias):array
    {
        return array_column($this->db->all("SELECT DISTINCT COALESCE(k.product_key,a.product_key) product_key
            FROM inventory_aliases a LEFT JOIN inventory_links k ON k.company_id=a.company_id
            AND k.provider=a.provider AND k.source_product=a.product_key AND k.state='active'
            WHERE a.company_id=? AND a.provider=? AND a.alias_key=?",[$company,$provider,hash('sha256',mb_strtoupper(trim($alias)))]),'product_key');
    }
}
