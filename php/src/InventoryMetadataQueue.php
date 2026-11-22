<?php
declare(strict_types=1);
namespace Omnigo;

/** One bounded metadata page per tick, resumable after errors; never writes source stock. */
final class InventoryMetadataQueue
{
    public function __construct(private Database $db,private IntegrationVault $vault){}
    public function tick(int $company,int $deadline):void
    {
        if(time()+6>=$deadline||!(new InventorySettings($this->db))->marketplaceEnabled($company))return;
        if(!$this->db->one("SELECT provider FROM inventory_catalog_jobs WHERE company_id=? AND provider='hepsiburada'",[$company]))return;
        $config=$this->vault->get($company,'hepsiburada');if(empty($config['enabled']))return;
        try{
            (new ProviderRequestGate($this->db))->run($company,'hepsiburada',$deadline,false,fn()=>$this->read($company,$config,$deadline));
        }catch(ProviderRequestDeferred){}
        catch(SyncFailure $error){if($error->httpStatus!==429)throw $error;}
    }

    private function read(int $company,array $config,int $deadline):void
    {
        $job=$this->claim($company);if(!$job)return;
        try{
            $response=(new HepsiburadaProductMetadataClient())->page($config,(int)$job['page_no'],$deadline);
            if($config!==$this->vault->get($company,'hepsiburada'))throw new \DomainException('Bağlantı değişti; ürün bilgileri güncel bağlantıyla yeniden alınacak.');
            $this->commit($company,$job,HepsiburadaProductMetadataPage::normalize($response,(int)$job['page_no']));
        }catch(\Throwable $error){
            $this->fail($company,$job,$error);
            if($error instanceof SyncFailure&&$error->httpStatus===429)throw $error;
        }
    }
    public function claim(int $company):?array
    {
        return $this->db->transaction(function()use($company){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))return null;
            $job=$this->db->one("SELECT * FROM inventory_metadata_jobs WHERE company_id=? AND provider='hepsiburada'".$this->db->lock(),[$company]);
            if(!$job){$this->db->run("INSERT INTO inventory_metadata_jobs(company_id,provider) VALUES(?,'hepsiburada')",[$company]);$job=$this->db->one("SELECT * FROM inventory_metadata_jobs WHERE company_id=? AND provider='hepsiburada'",[$company]);}
            if($job['state']==='complete'&&(new InventoryMetadataWriter($this->db))->pending($company)>0)$job['next_run']=0;
            if((int)$job['lease_until']>time()||(int)$job['next_run']>time())return null;
            $token=bin2hex(random_bytes(20));$this->db->run("UPDATE inventory_metadata_jobs SET state='running',lease_token=?,lease_until=? WHERE company_id=? AND provider='hepsiburada'",[$token,time()+60,$company]);
            return [...$job,'lease_token'=>$token];
        });
    }
    public function commit(int $company,array $job,array $page):void
    {
        $this->db->transaction(function()use($company,$job,$page){
            $row=$this->db->one("SELECT * FROM inventory_metadata_jobs WHERE company_id=? AND provider='hepsiburada'".$this->db->lock(),[$company]);
            if(!$row||!hash_equals((string)$row['lease_token'],$job['lease_token'])||(int)$row['lease_until']<=time())throw new \DomainException('Ürün bilgisi görevi devredildi.');
            if((int)$job['page_no']>0&&$page['items']&&$row['last_fingerprint']===$page['fingerprint'])throw new \DomainException('Ürün servisi aynı sayfayı tekrarladı.');
            $writer=new InventoryMetadataWriter($this->db);$mapped=0;foreach($page['items'] as $item)$mapped+=(int)$writer->apply($company,$item);
            $pending=$writer->pending($company);$next=$page['next'];$state=$next?'running':($pending?'incomplete':'complete');
            $message=!$next&&$pending?'Bazı stoklu ürünlerin adları henüz doğrulanamadı; kaynak ürün bilgileri ve kod eşleşmeleri yeniden kontrol edilecek.':null;
            $this->db->run("UPDATE inventory_metadata_jobs SET page_no=?,state=?,next_run=?,attempts=0,error=?,mapped_items=?,last_success=?,last_fingerprint=?,lease_until=0,lease_token=NULL WHERE company_id=? AND provider='hepsiburada'",[$next?(int)$job['page_no']+1:0,$state,time()+($next?10:($pending?900:1800)),$message,((int)$job['page_no']===0?0:(int)$row['mapped_items'])+$mapped,$next?$row['last_success']:gmdate('c'),$page['fingerprint'],$company]);
        });
    }
    public function fail(int $company,array $job,\Throwable $error):void
    {
        $attempt=min(12,(int)$job['attempts']+1);$wait=min(3600,30*2**min(7,$attempt));
        if($error instanceof SyncFailure)$wait=max($wait,min(3600,$error->retryAfter));
        $message=$error instanceof SyncFailure?'Ürün bilgisi servisi yanıtı: '.$error->httpStatus:($error instanceof \DomainException?$error->getMessage():'Ürün bilgisi yeniden denenecek.');
        $this->db->run("UPDATE inventory_metadata_jobs SET state='retry',attempts=?,next_run=?,error=?,lease_until=0,lease_token=NULL WHERE company_id=? AND provider='hepsiburada' AND lease_token=?",[$attempt,time()+$wait,mb_substr($message,0,200),$company,$job['lease_token']]);
    }
    public static function status(Database $db,int $company):array
    {
        $row=$db->one("SELECT state,error,last_success FROM inventory_metadata_jobs WHERE company_id=? AND provider='hepsiburada'",[$company]);
        return ['pending'=>(new InventoryMetadataWriter($db))->pending($company),'state'=>$row['state']??'queued','error'=>$row['error']??null,'lastSuccess'=>$row['last_success']??null];
    }
}
