<?php
declare(strict_types=1);
namespace Omnigo;

/** Enqueue inside product transactions; all HTTP and image work happens in the CLI worker. */
final class InventoryImageQueue
{
    public function __construct(private Database $db){}
    public function enqueue(int $company,string $key):void
    {
        if(!$this->db->one('SELECT product_key FROM inventory_image_jobs WHERE company_id=? AND product_key=?',[$company,$key]))$this->db->run("INSERT INTO inventory_image_jobs(company_id,product_key,updated) VALUES(?,?,?)",[$company,$key,gmdate('c')]);
    }
    public function manual(int $company,string $key):void
    {
        $this->enqueue($company,$key);
        $this->db->run("UPDATE inventory_image_jobs SET state='manual',lease_until=0,lease_token=NULL,error=NULL,updated=? WHERE company_id=? AND product_key=?",[gmdate('c'),$company,$key]);
    }
    public function claim():?array
    {
        return $this->db->transaction(function(){
            $job=$this->db->one("SELECT q.* FROM inventory_image_jobs q JOIN inventory_products p ON p.company_id=q.company_id AND p.product_key=q.product_key JOIN inventory_jobs j ON j.company_id=q.company_id AND j.enabled=1 WHERE q.state IN ('queued','retry','running') AND q.next_run<=? AND q.lease_until<=? AND p.image_url='' AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=q.company_id AND r.product_key=q.product_key) ORDER BY q.next_run,q.company_id,q.product_key LIMIT 1".$this->db->lock(),[time(),time()]);
            if(!$job)return null;$token=bin2hex(random_bytes(20));
            $this->db->run("UPDATE inventory_image_jobs SET state='running',lease_until=?,lease_token=?,updated=? WHERE company_id=? AND product_key=?",[time()+90,$token,gmdate('c'),$job['company_id'],$job['product_key']]);
            return [...$job,'lease_token'=>$token];
        });
    }
    public function fail(array $job,\Throwable $error):void
    {
        $attempt=min(30,(int)$job['attempts']+1);$wait=min(86400,60*(2**min(11,$attempt)));
        if($error instanceof SyncFailure)$wait=max($wait,min(86400,$error->retryAfter));
        $message=$error instanceof SyncFailure?'Görsel kaynağı yanıtı: '.$error->httpStatus:($error instanceof \DomainException?$error->getMessage():'Ürün görseli yeniden kontrol edilecek.');
        $this->db->run("UPDATE inventory_image_jobs SET state='retry',attempts=?,next_run=?,lease_until=0,lease_token=NULL,error=?,updated=? WHERE company_id=? AND product_key=? AND lease_token=?",[$attempt,time()+$wait,mb_substr($message,0,200),gmdate('c'),$job['company_id'],$job['product_key'],$job['lease_token']]);
    }
    public function run(int $deadline,InventoryImageFile $files,?InventoryImageSource $source=null):array
    {
        $source??=new InventoryImageSource();$result=['saved'=>0,'retry'=>0,'skipped'=>0];
        for($n=0;$n<6&&time()+5<$deadline;$n++){
            $job=$this->claim();if(!$job)break;
            try{
                $product=$this->db->one('SELECT p.*,m.brand,m.model FROM inventory_products p LEFT JOIN inventory_manual_products m ON m.company_id=p.company_id AND m.product_key=p.product_key WHERE p.company_id=? AND p.product_key=?',[$job['company_id'],$job['product_key']]);
                $listings=$this->db->all("SELECT evidence FROM inventory_listings WHERE company_id=? AND product_key=? UNION ALL SELECT l.evidence FROM inventory_links k JOIN inventory_listings l ON l.company_id=k.company_id AND l.product_key=k.source_product AND l.provider=k.provider WHERE k.company_id=? AND k.product_key=? AND k.state='active'",[$job['company_id'],$job['product_key'],$job['company_id'],$job['product_key']]);
                $photo=$source->download($product,$listings,min($deadline,time()+20));
                $saved=(new InventoryAutomaticImage($this->db,$files))->save($job,$photo['bytes'],$photo['source']);$result[$saved?'saved':'skipped']++;
            }catch(\Throwable $error){$this->fail($job,$error);$result['retry']++;}
        }
        return $result;
    }
}
