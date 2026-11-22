<?php
declare(strict_types=1);
namespace Omnigo;

/** Reuse approved inventory photography, matched by exact SKU inside one tenant. */
final class SalesProductImages
{
    public function __construct(private Database $db){}
    public function attach(int $company,string $provider,array $products):array
    {
        $codes=array_values(array_unique(array_filter(array_map(static fn($p)=>mb_strtoupper(trim($p['sku']??'')),$products))));
        if(!$codes)return $products;
        $matches=[];
        foreach(array_chunk($codes,200) as $chunk){
            $in=implode(',',array_fill(0,count($chunk),'?'));
            try{
                $rows=$this->db->all('SELECT sku,product_key,image_url FROM inventory_products WHERE company_id=? AND UPPER(TRIM(sku)) IN ('.$in.')',[$company,...$chunk]);
                $listings=$this->db->all('SELECT l.sku,p.product_key,p.image_url FROM inventory_listings l JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key WHERE l.company_id=? AND l.provider=? AND UPPER(TRIM(l.sku)) IN ('.$in.')',[$company,$provider,...$chunk]);
            }catch(\PDOException $e){
                if($e->getCode()==='42S02'||str_contains($e->getMessage(),'no such table: inventory_'))return $products;
                throw $e;
            }
            foreach([...$rows,...$listings] as $row)$matches[mb_strtoupper(trim($row['sku']))][$row['product_key']]=$row;
        }
        foreach($products as &$product){
            $rows=$matches[mb_strtoupper(trim($product['sku']??''))]??[];
            if(count($rows)!==1)continue;
            $row=reset($rows);$url=InventoryImages::url($company,$row['product_key'],$row['image_url']);
            if($url)$product['imageUrl']=str_replace('/api/inventory/image?','/api/sales/product-image?',$url);
        }
        unset($product);return $products;
    }
    public function read(int $company,string $product):string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$product))Http::fail(404,'Ürün görseli bulunamadı.');
        // A photo GET must never acquire InventoryStock's FOR UPDATE row lock.
        $row=$this->db->one('SELECT image_url FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$product]);
        if(!$row||!preg_match('/^inventory-image:([a-f0-9]{48})$/D',$row['image_url'],$match))Http::fail(404,'Ürün görseli bulunamadı.');
        return (new InventoryImageFile(dirname(__DIR__).'/storage/inventory-images'))->read($company,$match[1]);
    }
}
