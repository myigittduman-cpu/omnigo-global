<?php
declare(strict_types=1);
namespace Omnigo;

/** Due-date windows are capped at 30 days. Publish only a complete, fenced scan. */
final class N11FinanceAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private ?N11FinanceClient $reader=null) {}
    public function nextDelay(): int{return $this->delay;}
    public static function schedule(Database $db,IntegrationVault $vault): void
    {
        foreach($db->all("SELECT company_id,payload FROM records WHERE kind='sync_n11'") as $row){
            $sync=json_decode($row['payload'],true);$company=(int)$row['company_id'];
            if(empty($vault->get($company,'n11')['enabled']))continue;
            (new SyncQueue($db))->enqueue($company,'n11 Hakediş');
            // Source inconsistencies can resolve later. Retry after a quiet period without
            // publishing an incomplete response or reactivating blocked credentials.
            $db->run("UPDATE jobs SET state='pending',attempts=0,next_run=? WHERE company_id=? AND provider='n11 Hakediş' AND state='failed' AND next_run<=? AND lease_token IS NULL",[time(),$company,time()-1800]);
        }
    }
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'n11');if(empty($config['enabled']))throw new SyncFailure(403);
        $credentials=N11Credentials::fromArray($config);$revision=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
        $store=new FinanceStore($this->db);$before=$store->state($companyId,'n11');$state=$before?json_decode($before['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(($state['nextRequestAt']??0)>time()){$this->delay=$state['nextRequestAt']-time();return;}
        if(($state['revision']??null)!==$revision||($state['scanVersion']??0)!==2)unset($state['scan']);
        $state['scanVersion']=2;
        $zone=new \DateTimeZone('Europe/Istanbul');$today=new \DateTimeImmutable('today',$zone);
        if(empty($state['scan'])){
            $from=$today->modify('first day of last month')->format('Y-m-d');$to=$today->modify('last day of +2 months')->format('Y-m-d');
            $pending=$this->db->one("SELECT MIN(transaction_date) AS first_date FROM finance_entries WHERE company_id=? AND provider='n11' AND state IN ('pending','estimated')",[$companyId]);
            if(!empty($pending['first_date']))$from=min($from,$pending['first_date']);
            $state['scan']=['from'=>$from,'to'=>$to,'window'=>$from,'offset'=>0,'count'=>null,'entries'=>[]];
        }
        $state['revision']=$revision;$scan=$state['scan'];$complete=false;$reader=$this->reader??new N11FinanceClient();
        try{
            for($request=0;$request<3&&time()+3<$deadline;$request++){
                $end=min((new \DateTimeImmutable($scan['window'],$zone))->modify('+29 days')->format('Y-m-d'),$scan['to']);
                $page=$reader->settlements($credentials,$scan['window'],$end,$scan['offset'],$deadline);
                if($scan['count']!==null&&$scan['count']!==$page['count']){unset($state['scan']);throw new SyncFailure(409);}
                $scan['count']=$page['count'];
                foreach($page['entries'] as $entry){if(isset($scan['entries'][$entry['key']])){unset($state['scan']);throw new SyncFailure(409);}$scan['entries'][$entry['key']]=$entry;}
                if(count($scan['entries'])>10000)throw new SyncFailure(422);
                if($page['cursor']!==null)$scan['offset']=$page['cursor'];
                elseif($end===$scan['to']){$complete=true;break;}
                else{$scan['window']=(new \DateTimeImmutable($end,$zone))->modify('+1 day')->format('Y-m-d');$scan['offset']=0;$scan['count']=null;}
            }
            $this->delay=$complete?1800:60;$state['phase']=$complete?'ready':'syncing';$state['nextRequestAt']=time()+$this->delay;
            unset($state['failure'],$state['failureHttp']);
            if($complete){$state['lastSuccessAt']=gmdate('c');$state['coverageFrom']=$scan['from'];$state['coverageTo']=$scan['to'];unset($state['scan']);}else $state['scan']=$scan;
            $this->commit($companyId,$before,$revision,$state,$complete?$scan:null);
        }catch(\Throwable $error){
            $wait=max(60,$error instanceof SyncFailure?$error->retryAfter:0);
            $state['phase']='retry';$state['failure']='Finans kayıtları alınamadı; son başarılı aktarım korunuyor.';$state['failureHttp']=$error instanceof SyncFailure?$error->httpStatus:503;$state['nextRequestAt']=time()+$wait;
            $this->commit($companyId,$before,$revision,$state,null);
            throw new SyncFailure($state['failureHttp'],$wait);
        }
    }
    private function commit(int $company,?array $before,string $revision,array $state,?array $scan):void
    {
        $this->db->transaction(function()use($company,$before,$revision,$state,$scan){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$store=new FinanceStore($this->db);$current=$store->state($company,'n11');
            if(($current['version']??0)!==($before['version']??0)||hash('sha256',json_encode($this->vault->get($company,'n11'),JSON_THROW_ON_ERROR))!==$revision)throw new SyncFailure(409);
            if($scan)$store->publish($company,'n11',array_values($scan['entries']),$scan['from'],$scan['to']);
            $store->writeState($company,'n11',$state,$current);
        });
    }
}
