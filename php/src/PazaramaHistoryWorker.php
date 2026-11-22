<?php
declare(strict_types=1);
namespace Omnigo;

/** One official read per run, for an administrator-requested archive interval. */
final class PazaramaHistoryWorker
{
    // Prefer scheduled work for a full live polling cycle before competing for one shared slot.
    private const PRIORITY_WAIT_SECONDS=90;
    private PazaramaHistoryJobStore $jobs;

    public function __construct(private Database $db,private IntegrationVault $vault,private PazaramaHistoryImporter $importer,
        private ?PazaramaAuthClient $auth=null,private ?PazaramaReadClient $reader=null)
    {
        $this->jobs=new PazaramaHistoryJobStore($db);
    }

    private function claim(): ?array
    {
        $company=$this->jobs->due(time());
        if($company===null)return null;
        return TransactionRetry::run($this->db,function()use($company){
            $job=$this->jobs->get($company,true);
            if(!$job||!in_array($job['state'],['queued','running'],true)||(int)$job['next_run']>time())return null;
            $job['token']=bin2hex(random_bytes(16));
            $job['state']='running';
            $job['next_run']=time()+120;
            $job['updated_at']=gmdate('c');
            $this->jobs->save($job);
            return $job;
        });
    }

    private function checkVersion(array $job): void
    {
        $row=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_pazarama'".$this->db->lock(),[$job['company_id']]);
        if((int)($row['version']??0)!==(int)$job['connection_version'])throw new SyncFailure(409);
    }

    private function finish(array $job,array $expected,array $items,string $nextDay,int $nextPage,?string $hash): void
    {
        TransactionRetry::run($this->db,function()use($job,$expected,$items,$nextDay,$nextPage,$hash){
            $company=(int)$job['company_id'];
            $owned=$this->jobs->get($company,true);
            if(!$owned||$owned['state']!=='running'||$owned['token']!==$job['token'])return;
            $this->checkVersion($job);
            $this->importer->page($company,$items,$expected);
            $missing=$this->importer->coverage($company,$expected)['missing'];
            $state=$missing===0?'verified':($nextDay>=$job['until_date']?'incomplete':'queued');
            $this->jobs->save([...$owned,'cursor_day'=>$nextDay,'page_no'=>$nextPage,'state'=>$state,'attempts'=>0,
                'next_run'=>time()+30,'slot_wait_from'=>time(),'page_hash'=>$hash,'error_code'=>null,'token'=>null,'updated_at'=>gmdate('c')]);
        });
    }

    private function retry(array $job,\Throwable $error): void
    {
        $attempts=(int)$job['attempts'];
        if($error instanceof ProviderRequestDeferred){
            $next=$error->retryAt;
            $state='queued';
        }else{
            $attempts++;
            $status=$error instanceof SyncFailure?$error->httpStatus:503;
            $retryAfter=$error instanceof SyncFailure?$error->retryAfter:0;
            $state=($status===429||$status===408||$status>=500)&&$attempts<8?'queued':'failed';
            $next=time()+max($retryAfter,min(3600,30*(1<<min($attempts,7))));
        }
        $code=$status??null;
        TransactionRetry::run($this->db,function()use($job,$state,$attempts,$next,$code){
            $owned=$this->jobs->get((int)$job['company_id'],true);
            if(!$owned||$owned['state']!=='running'||$owned['token']!==$job['token'])return;
            $this->jobs->save([...$owned,'state'=>$state,'attempts'=>$attempts,'next_run'=>$next,
                'error_code'=>$code,'token'=>null,'updated_at'=>gmdate('c')]);
        });
    }

    public function runOne(int $deadline): bool
    {
        if($deadline<time()+9||!$this->jobs->available())return false;
        $job=$this->claim();
        if(!$job)return false;
        try {
            $company=(int)$job['company_id'];
            $expected=$this->importer->expected($company,$job['from_date'],$job['until_date']);
            if(!$expected)throw new SyncFailure(409);
            $this->checkVersion($job);
            $config=$this->vault->get($company,'pazarama');
            if(!($config['enabled']??false))throw new SyncFailure(403);
            $start=$job['cursor_day'].'T00:00';
            $through=(new \DateTimeImmutable($job['until_date'],new \DateTimeZone('Europe/Istanbul')))->modify('-1 day')->format('Y-m-d').'T23:59';
            $end=PazaramaDateWindow::end($start,$through);
            $page=(int)$job['page_no'];
            $waitingSince=$job['slot_wait_from']??strtotime($job['requested_at']);
            $preferLive=time()-(int)$waitingSince<self::PRIORITY_WAIT_SECONDS;
            if(!$preferLive){
                if(!IntegrationImportSchema::tableAvailable($this->db,'provider_request_gates','company_id'))throw new SyncFailure(503,60);
                $deadline=min($deadline,time()+12);
            }
            $items=(new ProviderRequestGate($this->db))->run($company,'pazarama',$deadline,$preferLive,function(int $until)use($config,$start,$end,$page){
                $credentials=PazaramaCredentials::fromArray($config);
                $token=($this->auth??new PazaramaAuthClient())->authenticate($credentials,$until);
                PazaramaSellerIdentity::verify($token['accessToken'],$credentials->sellerId);
                return ($this->reader??new PazaramaReadClient())->orders($token['accessToken'],$start,$end,$page,$until);
            });
            $full=count($items)===20;
            $hash=null;
            if($full){
                $ids=array_column($items,'orderId');
                if(count($ids)!==20||count(array_unique($ids))!==20||$page>=10000)throw new SyncFailure(422);
                sort($ids,SORT_STRING);
                $hash=hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
                if($hash===$job['page_hash'])throw new SyncFailure(422);
            }
            $this->finish($job,$expected,$items,$full?$job['cursor_day']:substr(PazaramaDateWindow::next($end),0,10),$full?$page+1:1,$hash);
        }catch(\Throwable $error){$this->retry($job,$error);}
        return true;
    }
}
