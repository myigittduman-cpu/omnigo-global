<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded read jobs: Pazarama monthly windows, Mirakl one full scan per day. */
final class FinanceAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private string $provider,private ?FinanceClient $reader=null,private ?PazaramaAuthClient $auth=null)
    {if(!in_array($provider,['pazarama','koctas'],true))throw new \InvalidArgumentException('Pazaryeri geçersiz.');}
    public function nextDelay(): int{return $this->delay;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,$this->provider);if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $revision=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));$store=new FinanceStore($this->db);$before=$store->state($companyId,$this->provider);$state=$before?json_decode($before['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(($state['nextRequestAt']??0)>time()){$this->delay=$state['nextRequestAt']-time();return;}
        $zone=new \DateTimeZone('Europe/Istanbul');$today=new \DateTimeImmutable('today',$zone);
        if(($state['revision']??null)!==$revision)unset($state['scan']);
        if(empty($state['scan'])){
            $from=$today->modify('-90 days')->format('Y-m-d');
            $pending=$this->db->one("SELECT MIN(transaction_date) AS first_date FROM finance_entries WHERE company_id=? AND provider=? AND state='pending'",[$companyId,$this->provider]);
            if(!empty($pending['first_date']))$from=min($from,$pending['first_date']);
            $state['scan']=['from'=>$from,'to'=>$today->format('Y-m-d'),'window'=>$from,'entries'=>[],'cursor'=>null,'visited'=>[]];
        }
        $state['revision']=$revision;$scan=$state['scan'];$reader=$this->reader??new FinanceClient();
        if($this->provider==='koctas'){
            // Persist before the request, so a crashed process cannot repeat today's scan.
            $state['nextRequestAt']=time()+86400;$state['phase']='reading';
            $this->commit($companyId,$before,$revision,$state,null);$before=$store->state($companyId,$this->provider);
        }
        $stage='authentication';
        try{
            if($this->provider==='pazarama'){
                $end=min((new \DateTimeImmutable($scan['window'],$zone))->modify('+29 days')->format('Y-m-d'),$scan['to']);
                $token=($this->auth??new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($config),$deadline);PazaramaSellerIdentity::verify($token['accessToken'],$config['sellerId']);
                $stage='read';$data=$reader->pazarama($token['accessToken'],$scan['window'],$end,$deadline);$complete=$end===$scan['to'];
                $stage='dates';foreach($data['entries'] as $entry)if($entry['transactionDate']<$scan['window']||$entry['transactionDate']>$end)throw new SyncFailure(422);
                $scan['window']=(new \DateTimeImmutable($end,$zone))->modify('+1 day')->format('Y-m-d');
            }else{
                $data=$reader->koctas($config,$scan['from'],$scan['cursor'],$deadline);$complete=$data['cursor']===null;
                if(!$complete&&(in_array($data['cursor'],$scan['visited'],true)||count($scan['visited'])>=100))throw new SyncFailure(422);
                if(!$complete)$scan['visited'][]=$data['cursor'];$scan['cursor']=$data['cursor'];
            }
            $stage='unique';foreach($data['entries'] as $entry){if(isset($scan['entries'][$entry['key']]))throw new SyncFailure(422);$scan['entries'][$entry['key']]=$entry;}
            if(count($scan['entries'])>10000)throw new SyncFailure(422);
            $this->delay=$complete?($this->provider==='koctas'?86400:21600):60;
            $state['phase']=$complete?'ready':'syncing';$state['nextRequestAt']=time()+$this->delay;unset($state['failure'],$state['failureStage'],$state['failureHttp'],$state['failureType'],$state['driverCode']);
            if($complete){$state['lastSuccessAt']=gmdate('c');unset($state['scan']);}else $state['scan']=$scan;
            $stage='commit';$this->commit($companyId,$before,$revision,$state,$complete?$scan:null);
        }catch(\Throwable $error){
            $wait=$this->provider==='koctas'?86400:max(60,$error instanceof SyncFailure?$error->retryAfter:0);
            $state['failureStage']=$stage;$state['failureHttp']=$error instanceof SyncFailure?$error->httpStatus:null;$state['failureType']=get_class($error);$state['driverCode']=$error instanceof \PDOException?($error->errorInfo[1]??null):null;$state['phase']='retry';$state['failure']='Finans kayıtları alınamadı; son başarılı aktarım korunuyor.';$state['nextRequestAt']=time()+$wait;
            if($this->provider==='koctas')unset($state['scan']);
            $this->commit($companyId,$before,$revision,$state,null);
            throw new SyncFailure($error instanceof SyncFailure?$error->httpStatus:503,max($wait,$error instanceof SyncFailure?$error->retryAfter:0));
        }finally{unset($token);}
    }
    private function commit(int $company,?array $before,string $revision,array $state,?array $scan): void
    {
        $this->db->transaction(function()use($company,$before,$revision,$state,$scan){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$store=new FinanceStore($this->db);$current=$store->state($company,$this->provider);
            if(($current['version']??0)!==($before['version']??0)||hash('sha256',json_encode($this->vault->get($company,$this->provider),JSON_THROW_ON_ERROR))!==$revision)throw new SyncFailure(409);
            if($scan)$store->publish($company,$this->provider,array_values($scan['entries']),$scan['from'],$scan['to']);
            $store->writeState($company,$this->provider,$state,$current);
        });
    }
}
