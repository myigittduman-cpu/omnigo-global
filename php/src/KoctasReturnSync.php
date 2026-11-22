<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent, durable RT11 cursor in the existing sync row; no order-job failure propagation. */
final class KoctasReturnSync
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?\Closure $request=null){}
    public function runDue(int $company,int $deadline): bool
    {
        if($deadline<time()+5)return false;
        if(class_exists(CustomerSetupSelection::class)&&!CustomerSetupSelection::hasLive($this->db,$company,'returns'))return false;
        try{
            return (new ProviderRequestGate($this->db))->run($company,'koctas',$deadline,false,fn()=>$this->read($company,$deadline));
        }catch(ProviderRequestDeferred){return false;}
        catch(SyncFailure $error){if($error->httpStatus===429)return true;throw $error;}
    }

    private function read(int $company,int $deadline): bool
    {
        if($deadline<time()+5)return false;
        $config=$this->vault->get($company,'koctas');if(!$config||empty($config['enabled']))return false;
        $revision=$this->revision($config);$token=bin2hex(random_bytes(16));$started=time();
        $job=$this->db->transaction(function()use($company,$token,$deadline,$started,$revision){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$row=$this->row($company);if(!$row)return null;
            $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$job=$state['returnRequestJob']??[];
            if(($job['nextRun']??0)>$started||($job['leaseUntil']??0)>$started)return null;
            if(($job['revision']??null)!==$revision)unset($job['scan']);
            $job=[...$job,'revision'=>$revision,'token'=>$token,'leaseUntil'=>$deadline+30,'nextRun'=>$started+300,'attempts'=>($job['attempts']??0)+1];
            $state['returnRequestJob']=$job;$this->save($row['id'],$state);return $job;
        });
        if(!$job)return false;
        $scan=$job['scan']??['from'=>$config['startDate'],'through'=>gmdate('c'),'items'=>[],'page'=>0,'tokens'=>[]];
        try{
            $query=isset($scan['next'])?['page_token'=>$scan['next']]:['shop_id'=>$config['shopId'],'limit'=>100,'sort'=>'date_created,ASC','return_creation_date_from'=>$scan['from'],'return_creation_date_to'=>$scan['through']];
            $result=$this->request?($this->request)('/api/returns',$query,$deadline):(new KoctasHttpClient($config['apiKey']))->get('/api/returns',$query,$deadline);
            $page=KoctasReturnMapper::page($result);$scan['page']++;
            if($scan['page']>1000||count($scan['items'])+count($page['items'])>20000)throw new SyncFailure(422);
            array_push($scan['items'],...$page['items']);
            if($page['next']!==null){
                $hash=hash('sha256',$page['next']);if(isset($scan['tokens'][$hash]))throw new SyncFailure(422);
                $scan['tokens'][$hash]=true;$scan['next']=$page['next'];
            }
            $this->finish($company,$token,$revision,function(array &$state)use($scan,$page,$started){
                if($page['next']===null){
                    ReturnRequestSnapshot::publish($state,$scan['items'],$scan['from'],$scan['through']);unset($state['returnRequestJob']['scan']);
                }else $state['returnRequestJob']['scan']=$scan;
                $state['returnRequestJob']['nextRun']=max(time()+1,$started+($page['next']===null?900:300));
                $state['returnRequestJob']['attempts']=0;
            });
        }catch(\Throwable $error){
            $retry=$error instanceof SyncFailure?$error->retryAfter:0;$status=$error instanceof SyncFailure?$error->httpStatus:0;
            $delay=max(300,min(3600,300*(2**min(4,$job['attempts']-1))),$retry);
            $this->finish($company,$token,$revision,function(array &$state)use($delay,$status){
                $state['returnRequestJob']['nextRun']=time()+$delay;
                if($status===422||$status===400)unset($state['returnRequestJob']['scan']);
                $state['returnRequestError']=['at'=>gmdate('c'),'message'=>'Koçtaş iade talepleri alınamadı; son doğrulanan kayıtlar korunuyor.','httpStatus'=>$status];
            });
            if($status===429)throw $error;
        }
        return true;
    }
    private function finish(int $company,string $token,string $revision,\Closure $apply): void
    {
        $this->db->transaction(function()use($company,$token,$revision,$apply){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$row=$this->row($company);if(!$row)return;
            $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);if(($state['returnRequestJob']['token']??null)!==$token)return;
            $config=$this->vault->get($company,'koctas');
            if($config&&!empty($config['enabled'])&&$this->revision($config)===$revision)$apply($state);
            else unset($state['returnRequestJob']['scan']);
            unset($state['returnRequestJob']['token'],$state['returnRequestJob']['leaseUntil']);$this->save($row['id'],$state);
        });
    }
    private function row(int $company): ?array{return $this->db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company]);}
    private function save(int $id,array $state): void{$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),gmdate('c'),$id]);}
    private function revision(array $config): string{return hash('sha256',json_encode([$config['shopId'],$config['apiKey'],$config['startDate']],JSON_THROW_ON_ERROR));}
}
