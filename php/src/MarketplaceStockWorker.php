<?php
declare(strict_types=1);
namespace Omnigo;

/** At most one leased stock write per tenant; ambiguous submissions are never blindly retried. */
final class MarketplaceStockWorker
{
    public function __construct(private Database $db,private MarketplaceStockPolicy $policy,private MarketplaceStockGateway $gateway,private \Closure $configuration,private ?MarketplaceStockPilot $pilot=null,private ?\Closure $pilotGateway=null){}
    public function tick(int $deadline):bool
    {
        if(time()+3>=$deadline)return false;
        $job=TransactionRetry::run($this->db,function(){
            $job=$this->db->one("SELECT * FROM marketplace_stock_requests WHERE state IN ('queued','sending','accepted') AND next_run<=? AND lease_until<=? ORDER BY created,request_id LIMIT 1",[time(),time()]);
            if(!$job)return null;
            $company=(int)$job['company_id'];$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if($this->db->one("SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND lease_until>?",[$company,time()]))return null;
            $token=bin2hex(random_bytes(16));
            $changed=$this->db->run("UPDATE marketplace_stock_requests SET lease_until=?,lease_token=? WHERE company_id=? AND request_id=? AND lease_until<=?",[time()+65,$token,$company,$job['request_id'],time()]);
            if($changed->rowCount()!==1)return null;
            return [...$job,'lease_token'=>$token];
        });
        if(!$job)return false;
        $company=(int)$job['company_id'];$state=$job['state'];$posting=false;
        try{
            $origin=MarketplaceStockJob::origin($job['request_id']);
            $provider=$origin??'hepsiburada';
            $this->validate($job,$origin);
            $configuration=($this->configuration)($company,$provider);
            if(!is_array($configuration)||empty($configuration['enabled']))throw new \DomainException('Pazaryeri bağlantısı kapalı; stok gönderilmedi.');
            $gateway=$origin===null?$this->gateway:($this->pilotGateway?($this->pilotGateway)($provider):MarketplaceStockJob::gateway($provider));
            if($state==='queued'){
                if(strtotime($job['created'])<time()-900)throw new \DomainException('Stok gönderim onayı zaman aşımına uğradı. Güncel adetle yeniden deneyin.');
                $listing=$gateway->read($configuration,$job['sku'],$deadline);$this->identity($job,$listing);
                if($origin!==null&&($listing['saleActive']??null)!==true)throw new \DomainException('Pazaryerinde ilan satışa açık olarak doğrulanamadı.');
                if($listing['quantity']!==(int)$job['source_quantity'])throw new \DomainException('Pazaryeri stoğu onaydan sonra değişti. Yeni değer gönderilmedi; güncel stoğu kontrol edin.');
                if($listing['quantity']===(int)$job['quantity']){$this->finish($job,'succeeded');return true;}
                // Commit sending before I/O. A crash after this point resumes with readback only.
                $this->validate($job,$origin);
                $this->update($job,['state'=>'sending','error'=>null]);$state='sending';
                $posting=true;$receipt=$gateway->submit($configuration,$listing,(int)$job['quantity'],$deadline);$posting=false;
                $this->update($job,['state'=>'accepted','receipt_id'=>$receipt,'next_run'=>time()+5,'attempts'=>0,'error'=>null]);return true;
            }
            if($state==='accepted'){
                $status=$gateway->status($configuration,(string)$job['receipt_id'],$deadline);
                if($status==='failed'){$this->finish($job,'failed','Pazaryeri stok güncellemesini reddetti. Mağaza panelinden kontrol edin.');return true;}
                if($status!=='done'){
                    if(strtotime($job['created'])<time()-900){$this->finish($job,'uncertain','Pazaryeri sonucu henüz kesinleşmedi. Yeniden dene yalnız önceki talebin sonucunu kontrol eder; ikinci gönderim yapılmaz.');return true;}
                    $this->update($job,['next_run'=>time()+15]);return true;
                }
            }
            $listing=$gateway->read($configuration,$job['sku'],$deadline);$this->identity($job,$listing);
            if($listing['quantity']===(int)$job['quantity'])$this->finish($job,'succeeded');
            elseif($origin!==null&&$state==='accepted'&&strtotime($job['created'])>time()-900){
                // The marketplace job may complete before its catalog exposes the new stock.
                $attempt=(int)$job['attempts']+1;
                $this->update($job,['attempts'=>$attempt,'next_run'=>time()+min(120,15*(2**min($attempt,3))),'error'=>'Pazaryerinin güncel katalog adedi bekleniyor.']);
            }else $this->finish($job,$state==='sending'||$origin!==null?'uncertain':'failed','Güncel pazaryeri stoğu: '.$listing['quantity'].' adet. Gönderim sonucu beklenen adetle eşleşmedi; tekrar gönderilmedi.');
        }catch(MarketplaceStockFailure $error){
            if($origin!==null&&$state==='sending'&&!$posting&&!$error->retryable)$this->finish($job,'uncertain','Gönderim sonucu pazaryerinden doğrulanamadı. Önceki işlem tekrar kontrol edilebilir.');
            elseif($error->uncertain||($state==='sending'&&!$error->retryable))$this->update($job,['state'=>'sending','next_run'=>time()+15,'error'=>'Gönderim yanıtı kesinleşmedi; yalnız pazaryeri adedi kontrol ediliyor.']);
            elseif($error->retryable&&(int)$job['attempts']<5)$this->update($job,['state'=>$state==='sending'&&$posting?'queued':$state,'attempts'=>(int)$job['attempts']+1,'next_run'=>time()+max($error->retryAfter,min(600,15*(2**(int)$job['attempts']))),'error'=>'Pazaryeri geçici olarak yanıt vermiyor. Kontrollü yeniden deneme bekleniyor.']);
            else $this->finish($job,in_array($state,['sending','accepted'],true)?'uncertain':'failed','Pazaryeri yanıtı doğrulanamadı. Yeniden denemede önce eski talep kontrol edilir.');
        }catch(\DomainException|\OutOfBoundsException|\InvalidArgumentException $error){$this->finish($job,in_array($state,['sending','accepted'],true)?'uncertain':'failed',$error->getMessage());}
        catch(\Throwable $error){$this->finish($job,in_array($state,['sending','accepted'],true)?'uncertain':'failed','Stok işlemi güvenle tamamlanamadı. Yeni gönderimden önce pazaryerini kontrol edin.');error_log('Omnigo pazaryeri stok görevi: '.get_class($error));}
        finally{$this->db->run('UPDATE marketplace_stock_requests SET lease_until=0,lease_token=NULL WHERE company_id=? AND request_id=? AND lease_token=?',[$company,$job['request_id'],$job['lease_token']]);}
        return true;
    }
    private function validate(array $job,?string $origin):void
    {
        $company=(int)$job['company_id'];
        $enabled=$origin===null?$this->policy->enabled($company):($this->pilot&&MarketplaceStockJob::allowed($this->db,$this->pilot,$company));
        if(!$enabled)throw new \DomainException('Pazaryeri yönetimi kapatıldı; yeni stok gönderilmedi.');
        $actor=$this->db->one('SELECT id,role FROM users WHERE id=?',[(int)$job['actor_id']]);
        if(!$actor||$actor['role']!=='admin'||!(new Security($this->db,[]))->active((int)$actor['id']))throw new \DomainException('İşlemi onaylayan yönetici hesabı artık yetkili değil.');
        $provider=$origin??'hepsiburada';
        $connection=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        if(!$connection||(int)$connection['version']!==(int)$job['connection_version'])throw new \DomainException('Mağaza bağlantısı değişti. Önce yeni bağlantıyı kontrol edin.');
        $columns=$origin==='hepsiburada'?'remote_id,sku,listing_key,evidence':'remote_id,sku';
        $listing=$this->db->one("SELECT $columns FROM inventory_listings WHERE company_id=? AND product_key=? AND listing_key=? AND provider=?",[$company,$job['product_key'],$job['listing_key'],MarketplaceStockJob::CHANNELS[$provider]]);
        $destination=$listing&&$origin==='hepsiburada'?HepsiburadaStockIdentity::catalogSku($listing):($listing['remote_id']??null);
        if($destination!==$job['remote_id']||$listing['sku']!==$job['sku']||$this->db->one('SELECT product_key FROM inventory_removed_products WHERE company_id=? AND product_key=?',[$company,$job['product_key']]))throw new \DomainException('Ürün eşleşmesi değişti. Stok gönderilmedi.');
        if($origin!==null&&$job['state']==='queued'){
            $target=(new ActiveMarketplaceListings($this->db))->writableTarget($company,$job['product_key'],$job['listing_key']);
            $targetDestination=$target['catalogSku']??$target['remoteId']??null;
            if($targetDestination!==$job['remote_id']||$target['sku']!==$job['sku']||$target['provider']!==MarketplaceStockJob::CHANNELS[$origin]||$target['quantity']!==(int)$job['source_quantity'])throw new \DomainException('İlanın satış durumu, adedi veya eşleşmesi onaydan sonra değişti. Stok gönderilmedi.');
        }
    }
    private function identity(array $job,array $listing):void
    {
        $matches=MarketplaceStockJob::origin($job['request_id'])==='hepsiburada'?HepsiburadaStockIdentity::matchesRead($job,$listing):in_array($job['remote_id'],$listing['remoteIds'],true);
        if($listing['merchantSku']!==$job['sku']||!$matches)throw new \DomainException('Canlı ürün kimliği kayıtlı ürünle eşleşmiyor. Stok gönderilmedi.');
    }
    private function update(array $job,array $values):void
    {
        $values['updated']=gmdate('c');$sets=implode(',',array_map(static fn($field)=>$field.'=?',array_keys($values)));
        $changed=$this->db->run('UPDATE marketplace_stock_requests SET '.$sets.' WHERE company_id=? AND request_id=? AND lease_token=?',[...array_values($values),(int)$job['company_id'],$job['request_id'],$job['lease_token']]);
        if($changed->rowCount()!==1)throw new \RuntimeException('Stok görevinin sahipliği değişti.');
    }
    private function finish(array $job,string $state,?string $error=null):void
    {
        $this->update($job,['state'=>$state,'error'=>$error,'next_run'=>0]);
        if($state==='succeeded'){
            // Ask the existing GET-only importer to refresh its snapshot. Never rewrite local pools.
            $provider=MarketplaceStockJob::origin($job['request_id'])??'hepsiburada';
            $this->db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=? AND provider=?',[(int)$job['company_id'],$provider]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[(int)$job['actor_id'],(int)$job['company_id'],MarketplaceStockJob::CHANNELS[$provider].' stok adedi doğrulandı',0,json_encode(['productKey'=>$job['product_key'],'sku'=>$job['sku'],'quantity'=>(int)$job['quantity'],'requestId'=>$job['request_id']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c')]);
        }
    }
}
