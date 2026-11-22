<?php
declare(strict_types=1);
namespace Omnigo;

/** Identity is never inferred from a title. Only one exact, tenant-local listing can match. */
final class InventoryMetadataWriter
{
    public function __construct(private Database $db){}
    public function apply(int $company,array $item):bool
    {
        $write=function()use($company,$item):bool{
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            return $this->applyLocked($company,$item);
        };
        return $this->db->pdo->inTransaction()?$write():$this->db->transaction($write);
    }

    private function applyLocked(int $company,array $item):bool
    {
        $sku=InventoryLiveSkuIdentity::load($this->db,$company);
        if(!$sku&&($barcode=InventoryBarcodeIdentity::load($this->db,$company)))return $barcode->applyHepsiburadaMetadata($item);
        if($sku){
            $key=$sku->key($item['sku']);
            $listings=array_filter($this->listings($company,$key),static function(array $row)use($item):bool{
                $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
                return !empty($evidence['skuConfirmed'])
                    && InventoryLiveSkuIdentity::normalize($row['sku'])===InventoryLiveSkuIdentity::normalize($item['sku'])
                    && (($evidence['sourceCatalogId']??'')===''||$evidence['sourceCatalogId']===$item['hbSku']);
            });
            if(!$listings)return false;
        }else{
            $catalog=new InventoryCatalog($this->db);$keys=[];
            foreach(array_unique([$item['sku'],$item['hbSku'],$item['barcode']??'']) as $code)if($code!==''){$key=$catalog->matchSku($company,'Hepsiburada',$code);if($key!==null)$keys[$key]=true;}
            if(count($keys)!==1)return false;
            $key=array_key_first($keys);
            $listings=$this->listings($company,$key);
        }
        $product=$this->db->one('SELECT * FROM inventory_products WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$key]);
        if(!$product||$this->db->one('SELECT product_key FROM inventory_manual_products WHERE company_id=? AND product_key=?',[$company,$key]))return false;
        if(!$sku&&InventoryCatalog::validGtin($product['barcode'])&&InventoryCatalog::validGtin($item['barcode'])&&str_pad($product['barcode'],14,'0',STR_PAD_LEFT)!==str_pad($item['barcode'],14,'0',STR_PAD_LEFT))return false;
        // Catalogue details must not move a row, change quantities/prices or remap a saved identity.
        if(str_starts_with($product['title'],'Stok kodu:')||$product['title']===$product['sku'])
            $this->db->run('UPDATE inventory_products SET title=?,version=version+1 WHERE company_id=? AND product_key=?',[$item['title'],$company,$key]);
        if($product['barcode']===''&&InventoryCatalog::validGtin($item['barcode']))$this->db->run('UPDATE inventory_products SET barcode=? WHERE company_id=? AND product_key=?',[$item['barcode'],$company,$key]);
        foreach($listings as $listing){
            $evidence=json_decode($listing['evidence'],true,32,JSON_THROW_ON_ERROR);
            $evidence['metadataTitle']=$item['title'];$evidence['metadataAt']=gmdate('c');
            if($sku&&InventoryCatalog::validGtin($item['barcode']))$evidence['metadataBarcode']=$item['barcode'];
            $evidence['imageCandidates']=array_slice(array_values(array_unique([...($evidence['imageCandidates']??[]),...$item['images']])),0,5);
            if($item['brand']!=='')$evidence['imageBrand']=$item['brand'];
            $this->db->run("UPDATE inventory_listings SET evidence=? WHERE company_id=? AND provider='Hepsiburada' AND listing_key=?",[json_encode($evidence,JSON_THROW_ON_ERROR),$company,$listing['listing_key']]);
        }
        if($product['image_url']===''&&$item['images']){
            (new InventoryImageQueue($this->db))->enqueue($company,$key);
            $this->db->run("UPDATE inventory_image_jobs SET next_run=0 WHERE company_id=? AND product_key=? AND state IN ('queued','retry')",[$company,$key]);
        }
        return true;
    }

    private function listings(int $company,string $key):array
    {
        return $this->db->all("SELECT listing_key,sku,evidence FROM inventory_listings WHERE company_id=? AND provider='Hepsiburada' AND product_key=?",[$company,$key]);
    }
    public function pending(int $company):int
    {
        return (int)$this->db->one("SELECT COUNT(*) n FROM inventory_products p WHERE p.company_id=? AND (p.title LIKE 'Stok kodu:%' OR p.title=p.sku) AND EXISTS (SELECT 1 FROM inventory_listings l WHERE l.company_id=p.company_id AND l.product_key=p.product_key AND l.provider='Hepsiburada' AND l.source_quantity>0 AND JSON_EXTRACT(l.evidence,'$.saleActive')=1)",[$company])['n'];
    }
}
