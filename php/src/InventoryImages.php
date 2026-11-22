<?php
declare(strict_types=1);
namespace Omnigo;

/** Tenant and version checks are independent of the inventory import pipeline. */
final class InventoryImages
{
    public function __construct(private Database $db,private InventoryImageFile $files) {}

    private static function key(string $reference): ?string
    {
        return preg_match('/^inventory-image:([a-f0-9]{48})$/D',$reference,$m)?$m[1]:null;
    }

    public static function url(int $company,string $product,string $reference): string
    {
        $key=self::key($reference);
        return $key?'/api/inventory/image?'.http_build_query(['company'=>$company,'productKey'=>$product,'v'=>$key]):'';
    }

    public function read(int $company,string $product): string
    {
        $row=(new InventoryStock($this->db))->product($company,$product);$key=self::key($row['image_url']);
        if(!$key)throw new \OutOfBoundsException('Ürünün görseli yok.');
        return $this->files->read($company,$key);
    }

    public function change(int $company,string $product,int $version,?string $bytes,int $actor): array
    {
        if($version<1)throw new \InvalidArgumentException('Ürün sürümünü kontrol edin.');
        (new InventoryStock($this->db))->product($company,$product);
        $safe=$bytes===null?null:InventoryImageFile::normalize($bytes);
        $newKey=$safe===null?null:$this->files->write($company,$safe);$oldKey=null;
        try{
            $result=$this->db->transaction(function()use($company,$product,$version,$newKey,$actor,&$oldKey){
                $this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $row=(new InventoryStock($this->db))->product($company,$product);
                (new InventoryVisibility($this->db))->requireVisible($company,$product);
                if((int)$row['version']!==$version)throw new \DomainException('Ürün güncellendi. Listeyi yenileyip tekrar deneyin.');
                if($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND product_key=? AND state='pending'",[$company,$product]))throw new \DomainException('Bekleyen stok işlemi tamamlanınca tekrar deneyin.');
                $oldKey=self::key($row['image_url']);$reference=$newKey?'inventory-image:'.$newKey:'';
                $this->db->run('UPDATE inventory_products SET image_url=?,version=version+1 WHERE company_id=? AND product_key=?',[$reference,$company,$product]);
                (new InventoryImageQueue($this->db))->manual($company,$product);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,NULL,?,?,?)',[$actor,$company,$newKey?'Stok ürün görseli kaydedildi':'Stok ürün görseli kaldırıldı',json_encode(['productKey'=>$product,'hasImage'=>$oldKey!==null]),json_encode(['productKey'=>$product,'hasImage'=>$newKey!==null]),gmdate('c')]);
                return ['imageUrl'=>self::url($company,$product,$reference),'version'=>$version+1];
            });
        }catch(\Throwable $e){if($newKey)$this->files->remove($company,$newKey);throw $e;}
        if($oldKey)$this->files->remove($company,$oldKey);
        return $result;
    }
}
