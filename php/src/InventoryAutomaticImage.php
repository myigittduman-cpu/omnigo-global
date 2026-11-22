<?php
declare(strict_types=1);
namespace Omnigo;

/** Fill-only transaction. Manual photos/removals and stock writes always win. */
final class InventoryAutomaticImage
{
    public function __construct(private Database $db,private InventoryImageFile $files){}
    public function save(array $job,string $safe,string $source):bool
    {
        $company=(int)$job['company_id'];$key=$job['product_key'];$file=$this->files->write($company,InventoryImageFile::normalize($safe));$saved=false;
        try{
            $saved=$this->db->transaction(function()use($job,$source,$company,$key,$file){
                $this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $product=(new InventoryStock($this->db))->product($company,$key);
                $active=$this->db->one('SELECT * FROM inventory_image_jobs WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$key]);
                if(!$active||$active['state']!=='running'||!hash_equals((string)$active['lease_token'],$job['lease_token'])||(int)$active['lease_until']<=time())return false;
                if($product['image_url']!==''){$this->finish($job,'done',$source);return false;}
                (new InventoryVisibility($this->db))->requireVisible($company,$key);
                if($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key]))throw new \DomainException('Bekleyen stok kaydından sonra görsel eklenecek.');
                $this->db->run('UPDATE inventory_products SET image_url=?,version=version+1 WHERE company_id=? AND product_key=?',['inventory-image:'.$file,$company,$key]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,NULL,?,?,?)',[$company,'Ürün görseli otomatik kaydedildi',json_encode(['productKey'=>$key,'hasImage'=>false]),json_encode(['productKey'=>$key,'hasImage'=>true,'source'=>$source],JSON_UNESCAPED_SLASHES),gmdate('c')]);
                $this->finish($job,'done',$source);return true;
            });
            return $saved;
        }finally{if(!$saved)$this->files->remove($company,$file);}
    }
    private function finish(array $job,string $state,string $source):void
    {
        $this->db->run('UPDATE inventory_image_jobs SET state=?,source_url=?,error=NULL,lease_until=0,lease_token=NULL,updated=? WHERE company_id=? AND product_key=? AND lease_token=?',[$state,$source,gmdate('c'),$job['company_id'],$job['product_key'],$job['lease_token']]);
    }
}
