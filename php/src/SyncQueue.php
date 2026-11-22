<?php
declare(strict_types=1);
namespace Omnigo;

interface ReadOnlyAdapter
{
    /** Implementations must use HTTP timeouts and idempotent upserts per source record. */
    public function syncReadOnly(int $companyId, int $deadline): void;
}
class SyncFailure extends \RuntimeException
{
    public function __construct(public int $httpStatus, public int $retryAfter = 0) { parent::__construct('Kaynak verisi alınamadı.'); }
}
final class SyncQueue
{
    public function __construct(private Database $db, private array $adapters = [],private ?\Closure $onFailure=null) {}
    public function enqueue(int $company, string $provider): void
    {
        if(PaymentPolicy::automaticJob($provider))return;
        if (!in_array($provider,[...Repository::CHANNELS,'Pazarama Sorular','n11 Sorular','idefix Sorular',HepsiburadaQuestionClient::JOB,TrendyolQuestionClient::JOB,TrendyolClaimAdapter::JOB,'Pazarama Hakediş','Koçtaş Hakediş','Hepsiburada Satış','Hepsiburada Hakediş','n11 Hakediş','idefix Hakediş',PazaramaCommissionAdapter::JOB,...array_values(UpcomingPayoutAdapter::JOBS)],true)) Http::fail(400,'Pazaryeri geçersiz.');
        $sql = $this->db->mysql() ? 'INSERT IGNORE INTO jobs(company_id,provider,next_run) VALUES(?,?,?)' : 'INSERT OR IGNORE INTO jobs(company_id,provider,next_run) VALUES(?,?,?)';
        $this->db->run($sql,[$company,$provider,time()]);
    }
    public function runOne(?int $now = null,?array $providers=null,?int $deadline=null,array $exclude=[]): bool
    {
        $now ??= time();
        $job = $this->db->transaction(function() use($now,$providers,$exclude) {
            // Failed read jobs keep a slow recovery probe; disabled integrations remain disabled.
            $this->db->run("UPDATE jobs SET state='pending' WHERE lease_token IS NULL AND next_run<=? AND (state='failed' OR (state='blocked' AND error='Pazaryeri yetkisi doğrulanamadı.'))",[$now]);
            // Leases prevent overlapping cron runs; stale jobs receive a new fencing token.
            $this->db->run("UPDATE jobs SET state='pending',lease_token=NULL,lease_until=NULL WHERE state='running' AND lease_until<?",[$now]);
            if($providers===[])return null;
            $filter=" AND provider NOT LIKE '% Hakediş'".($providers===null?'':' AND provider IN ('.implode(',',array_fill(0,count($providers),'?')).')');
            if($exclude)$filter.=' AND provider NOT IN ('.implode(',',array_fill(0,count($exclude),'?')).')';
            $position=null;
            do{
                $after=$position===null?'':' AND (next_run>? OR (next_run=? AND id>?))';
                $job=$this->db->one("SELECT * FROM jobs WHERE state='pending' AND next_run<=?".$filter.$after
                    ." ORDER BY next_run,id LIMIT 1".$this->db->lock(),[$now,...($providers??[]),...$exclude,...($position??[])]);
                if(!$job)return null;
                $position=[$job['next_run'],$job['next_run'],$job['id']];
            }while(class_exists(CustomerSetupSelection::class)
                && !CustomerSetupSelection::jobEnabled($this->db,(int)$job['company_id'],$job['provider']));
            $job['lease_token'] = bin2hex(random_bytes(32));
            $this->db->run("UPDATE jobs SET state='running',lease_token=?,lease_until=?,attempts=attempts+1 WHERE id=?",[$job['lease_token'],$now+300,$job['id']]);
            $job['attempts'] = (int)$job['attempts']+1;
            return $job;
        });
        if (!$job) return false;
        $adapter = $this->adapters[$job['provider']] ?? null;
        if (!$adapter instanceof ReadOnlyAdapter) {
            $this->finish($job,'blocked',$now,'Pazaryeri bağlantısı henüz yapılandırılmadı.');
            return true;
        }
        $startedAt=max($now,time());
        try {
            $company=(int)$job['company_id'];
            $until=min($now+120,$deadline??PHP_INT_MAX);
            $provider=ProviderRequestGate::jobProvider($job['provider']);
            $read=fn()=>$adapter->syncReadOnly($company,$until);
            if($provider===null)$read();
            else (new ProviderRequestGate($this->db))->run($company,$provider,$until,false,$read);
            $delay=$adapter instanceof ScheduledReadAdapter?max(60,min(86400,$adapter->nextDelay())):900;
            $nextRun=$adapter instanceof FixedCadenceReadAdapter?max($startedAt+$delay,time()+1):max($now,time())+$delay;
            $this->finish($job,'pending',$nextRun,null,true);
        } catch (ProviderRequestDeferred $delay) {
            // Waiting for a shared slot is not a source failure and does not consume an attempt.
            $this->db->run("UPDATE jobs SET state='pending',next_run=?,attempts=?,lease_until=NULL,lease_token=NULL
                WHERE id=? AND lease_token=?",
                [max($now+1,$delay->retryAt),max(0,$job['attempts']-1),$job['id'],$job['lease_token']]);
        } catch (\Throwable $error) {
            $status = $error instanceof SyncFailure ? $error->httpStatus : 0;
            $blocked = in_array($status,[401,403],true);
            // Outages must recover without resaving credentials. Invalid data and access errors
            // still require attention; transient failures keep a bounded, slower retry cadence.
            $transient = in_array($status,[408,409,425,429],true) || ($status>=500 && $status<=599);
            $state = $blocked ? 'blocked' : ($transient?'pending':($job['attempts']>=5?'failed':'pending'));
            $backoff = min(3600,30*(2**min(7,$job['attempts']-1))+random_int(0,15));
            if($transient&&$status!==429)$backoff=min(300,$backoff);
            if(!$transient)$backoff=max(1800,$backoff);
            // Operational orders keep probing after an outage; explicit provider Retry-After wins.
            if($transient&&$status!==429&&$adapter instanceof FixedCadenceReadAdapter)$backoff=60;
            if($transient&&$status!==429&&$adapter instanceof TransientRetryReadAdapter)$backoff=max(60,min(300,$adapter->transientRetryDelay()));
            $delay = max($backoff,$error instanceof SyncFailure?$error->retryAfter:0);
            $message = $blocked ? 'Pazaryeri yetkisi doğrulanamadı.' : ($state==='failed'?'Veri alınamadı; kontrollü yeniden deneme sürdürülecek.':'Veri alınamadı; yeniden deneme planlandı.');
            $this->finish($job,$state,max($now,time())+$delay,$message);
            // Capture even an outage that recovers before the next general monitoring tick.
            // Notification failures must never alter the order cursor, retry or lease result.
            if($this->onFailure)try{($this->onFailure)((int)$job['company_id']);}
            catch(\Throwable $notificationError){error_log('Omnigo bağlantı bildirimi: '.get_class($notificationError));}
        }
        return true;
    }
    private function finish(array $job, string $state, int $nextRun, ?string $error, bool $reset = false): void
    {
        $result=$this->db->run('UPDATE jobs SET state=?,next_run=?,error=?,attempts=?,lease_until=NULL,lease_token=NULL WHERE id=? AND lease_token=?',[$state,$nextRun,$error,$reset?0:$job['attempts'],$job['id'],$job['lease_token']]);
        if($result->rowCount()===1)try{(new IntegrationImportReconciler($this->db))->check((int)$job['company_id']);}
        catch(\Throwable $noticeError){error_log('Omnigo aktarım raporu: '.get_class($noticeError));}
    }
}

