<?php
declare(strict_types=1);
namespace Omnigo;

/** Coordinates actual provider requests across the existing queues and optional manual reads. */
final class ProviderRequestGate
{
    public function __construct(private Database $db) {}

    public function run(int $company,string $provider,int $deadline,bool $manual,callable $read): mixed
    {
        [$token,$usableUntil]=$this->slot($company,$provider,$deadline,$manual,true);
        $failure=null;
        try{return $read($usableUntil);}
        catch(\Throwable $error){$failure=$error;throw $error;}
        finally{$this->release($company,$provider,$token,$failure);}
    }

    public static function install(Database $db): void
    {
        $tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS provider_request_gates (
            company_id INT NOT NULL, provider VARCHAR(20) NOT NULL,
            lease_token CHAR(32), lease_until BIGINT NOT NULL DEFAULT 0,
            retry_until BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(company_id,provider),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
    }

    public static function jobProvider(string $job): ?string
    {
        if($job===PazaramaCommissionAdapter::JOB)return 'pazarama';
        if($job===TrendyolClaimAdapter::JOB)return 'trendyol';
        if($job===HepsiburadaQuestionClient::JOB)return 'hepsiburada';
        foreach(CustomerMarketplaces::PROVIDERS as $provider=>$label){
            if(in_array($job,[$label,$label.' Sorular',$label.' Satış',$label.' Hakediş',
                UpcomingPayoutAdapter::JOBS[$provider]??''],true))return $provider;
        }
        return null;
    }

    /** Existing queues can run during additive provisioning; the new action needs the gate. */
    public function acquire(int $company,string $provider,int $deadline,bool $manual=false): ?string
    {
        return $this->slot($company,$provider,$deadline,$manual,false)[0];
    }

    private function slot(int $company,string $provider,int $deadline,bool $manual,bool $useShortGap): array
    {
        if(!IntegrationImportSchema::tableAvailable($this->db,'provider_request_gates','company_id')){
            if($manual)throw new SyncFailure(503,60);
            return [null,$deadline];
        }
        if(!isset(CustomerMarketplaces::PROVIDERS[$provider]))throw new \InvalidArgumentException('Pazaryeri geçersiz.');
        return TransactionRetry::run($this->db,function()use($company,$provider,$deadline,$manual,$useShortGap){
            $now=time();
            if($deadline<=$now)throw new ProviderRequestDeferred($now+1);
            $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $this->db->run("$insert INTO provider_request_gates(company_id,provider) VALUES(?,?)",[$company,$provider]);
            $row=$this->db->one('SELECT lease_until,retry_until FROM provider_request_gates WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,$provider]);
            if(!$row)throw new SyncFailure(404);
            if((int)$row['retry_until']>$now)throw new ProviderRequestDeferred((int)$row['retry_until']);
            if((int)$row['lease_until']>$now)throw new ProviderRequestDeferred(min((int)$row['lease_until'],$now+5));
            if($manual){
                $due=$this->automaticDue($company,$provider,$now);
                if($due!==null&&$due<=$deadline+2){
                    $available=$due-3;
                    if(!$useShortGap||$available-$now<9)throw new ProviderRequestDeferred(max($now+5,$due+5));
                    $deadline=$available;
                }
            }
            $token=bin2hex(random_bytes(16));
            $this->db->run('UPDATE provider_request_gates SET lease_token=?,lease_until=? WHERE company_id=? AND provider=?',
                [$token,$deadline+15,$company,$provider]);
            return [$token,$deadline];
        });
    }

    public function release(int $company,string $provider,?string $token,?\Throwable $failure=null,int $cooldown=0): void
    {
        if($token===null)return;
        $limit=$failure instanceof SyncFailure?$failure->httpStatus===429:($failure instanceof InboxFailure&&$failure->status===429);
        $retry=$limit?time()+max(60,$failure->retryAfter):($cooldown>0?time()+$cooldown:0);
        try{
            $this->db->run('UPDATE provider_request_gates SET lease_token=NULL,lease_until=0,
                retry_until=CASE WHEN retry_until>? THEN retry_until ELSE ? END
                WHERE company_id=? AND provider=? AND lease_token=?',[$retry,$retry,$company,$provider,$token]);
        }catch(\Throwable $error){error_log('Omnigo pazaryeri istek kilidi: '.get_class($error));}
    }

    private function automaticDue(int $company,string $provider,int $now): ?int
    {
        $due=null;
        $accept=static function(mixed $at)use(&$due):void{
            if($at!==null)$due=$due===null?(int)$at:min($due,(int)$at);
        };
        foreach($this->db->all("SELECT provider,next_run FROM jobs WHERE company_id=? AND
            (state IN ('pending','running','failed') OR (state='blocked' AND error='Pazaryeri yetkisi doğrulanamadı.'))",[$company]) as $job){
            if(self::jobProvider($job['provider'])!==$provider)continue;
            if(class_exists(CustomerSetupSelection::class)&&!CustomerSetupSelection::jobEnabled($this->db,$company,$job['provider']))continue;
            $accept($job['next_run']);
        }
        $catalogue=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_jobs','provider')
            && (new InventorySettings($this->db))->marketplaceEnabled($company);
        if($catalogue){
            $accept($this->db->one('SELECT next_run FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',
                [$company,$provider])['next_run']??null);
        }
        if($catalogue&&$provider==='hepsiburada'&&IntegrationImportSchema::tableAvailable($this->db,'inventory_metadata_jobs','provider')){
            $accept($this->db->one("SELECT next_run FROM inventory_metadata_jobs WHERE company_id=? AND provider='hepsiburada'",
                [$company])['next_run']??null);
        }
        if(($provider==='koctas'||in_array($provider,['n11','hepsiburada','pazarama'],true))
            && IntegrationImportSchema::tableAvailable($this->db,'inbox_jobs','company_id')){
            $scans=!class_exists(CustomerSetupSelection::class)||CustomerSetupSelection::hasLive($this->db,$company,'inbox');
            $accept($this->db->one("SELECT MIN(j.next_run) AS due FROM inbox_jobs j LEFT JOIN inbox_threads t
                ON t.id=j.thread_id AND t.company_id=j.company_id
                WHERE j.company_id=? AND COALESCE(t.provider,'koctas')=?
                    AND (j.state IN ('pending','running','verifying') OR (?='hepsiburada' AND j.kind='reply' AND j.state='unknown'))
                    AND (j.kind<>'scan' OR ?=1)",[$company,$provider,$provider,(int)$scans])['due']??null);
        }
        if($provider==='koctas'&&(!class_exists(CustomerSetupSelection::class)
            || CustomerSetupSelection::hasLive($this->db,$company,'returns'))){
            $state=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company]);
            if($state){
                $value=json_decode($state['payload'],true,64,JSON_THROW_ON_ERROR);
                $accept($value['returnRequestJob']['nextRun']??$now);
            }
        }
        return $due;
    }
}
