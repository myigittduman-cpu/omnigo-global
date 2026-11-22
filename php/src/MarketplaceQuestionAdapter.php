<?php
declare(strict_types=1);
namespace Omnigo;

/** One bounded page/detail per leased job; state changes use the company lock and revision check. */
final class MarketplaceQuestionAdapter implements ScheduledReadAdapter
{
    private int $delay=65;
    public function __construct(private Database $db,private IntegrationVault $vault,private string $provider,private N11QuestionClient|IdefixQuestionClient|HepsiburadaQuestionClient|null $client=null)
    {
        if(!in_array($provider,['n11','idefix',HepsiburadaQuestionClient::PROVIDER],true))throw new \InvalidArgumentException('Pazaryeri geçersiz.');
    }
    public function nextDelay(): int{return $this->delay;}
    private function state(int $company): ?array
    {
        if($this->provider===HepsiburadaQuestionClient::PROVIDER)
            return (new FinanceStore($this->db))->state($company,HepsiburadaQuestionClient::STATE_KEY);
        return $this->db->one('SELECT id,payload,version FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider.'_questions']);
    }
    private function revision(?array $configuration): ?string
    {
        if(empty($configuration['enabled']))return null;
        return $configuration['revision']??($this->provider===HepsiburadaQuestionClient::PROVIDER
            ?hash('sha256',json_encode($configuration,JSON_THROW_ON_ERROR)):null);
    }
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,$this->provider);if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $before=$this->state($companyId);$previous=$before?json_decode($before['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $revision=$this->revision($config);if($revision===null)throw new SyncFailure(422);
        $state=($previous['revision']??null)===$revision?$previous:[];$state['revision']=$revision;$this->delay=65;
        try{
            $threads=[];$complete=false;
            if($this->provider==='n11'){
                $client=$this->client??new N11QuestionClient();
                if(!empty($state['pending'])){
                    for($read=0;$read<5&&!empty($state['pending'])&&time()<$deadline-10;$read++){
                        $id=array_shift($state['pending']);$threads[]=$client->detail($config,$id,$deadline);
                    }
                    $complete=empty($state['pending'])&&!empty($state['listed']);
                }else{
                    $page=(int)($state['page']??0);$data=$client->listing($config,$page,$deadline,$state['windowEnd']??null);
                    $state['pending']=$data['ids'];$state['seen']=array_values(array_unique([...($state['seen']??[]),...$data['ids']]));
                    if(count($state['seen'])>20000)throw new SyncFailure(422);
                    $moreHistory=$data['complete']&&empty($state['historyComplete'])&&$data['windowStart']>'2026-11-01';
                    $state['listed']=$data['complete']&&!$moreHistory;$state['page']=$data['complete']?0:$page+1;
                    $state['windowEnd']=$moreHistory?(new \DateTimeImmutable($data['windowStart']))->modify('-1 day')->format('Y-m-d'):$data['windowEnd'];
                    if($state['listed']){
                        // A question disappearing from OPEN needs a fresh detail before changing its status.
                        foreach($this->db->all("SELECT remote_id FROM inbox_threads t WHERE t.company_id=? AND t.provider='n11' AND ".InboxStatus::waiting().' AND '.InboxQuestionWindow::hasCurrentMessageSql(),[$companyId]) as $row)
                            if(!in_array($row['remote_id'],$state['seen'],true))$state['pending'][]=$row['remote_id'];
                        $state['pending']=array_values(array_unique($state['pending']));
                    }
                    $complete=$state['listed']&&!$state['pending'];
                }
            }elseif($this->provider===HepsiburadaQuestionClient::PROVIDER){
                [$state,$threads,$complete]=$this->readHepsiburada($companyId,$config,$state,$deadline);
            }else{
                $stream=$state['stream']??'unanswered';$page=(int)($state['page']??1);$client=$this->client??new IdefixQuestionClient();
                $data=$client->page($config,$stream,$page,$deadline);$threads=$data['threads'];
                if($data['complete']){$state['page']=1;$state['stream']=match($stream){'unanswered'=>'product','product'=>'answered','answered'=>'unanswered'};$complete=$stream==='answered';}
                else $state['page']=$page+1;
            }
            unset($state['failure']);$state['lastPageAt']=gmdate('c');$state['phase']=$complete?'ready':'syncing';
            if($complete){$state['lastSuccessAt']=gmdate('c');$state['historyComplete']=true;unset($state['seen'],$state['pending'],$state['listed'],$state['windowEnd']);$this->delay=300;}
            $this->commit($companyId,$before,$revision,$state,$threads);
        }catch(\Throwable $error){
            $failed=($previous['revision']??null)===$revision?$previous:[];
            $failed['revision']=$revision;$failed['failure']=['code'=>$error instanceof SyncFailure?$error->httpStatus:0];
            $this->commit($companyId,$before,$revision,$failed,[]);
            throw $error;
        }
    }
    private function readHepsiburada(int $company,array $config,array $state,int $deadline): array
    {
        $client=$this->client??new HepsiburadaQuestionClient();
        if (!empty($state['pending'])) {
            $threads=[];
            for($reads=0;$reads<5&&$state['pending']&&time()<$deadline-8;$reads++) {
                $id=array_shift($state['pending']);
                try {
                    $threads[]=$client->detail($config,$id,$deadline);
                } catch (SyncFailure $error) {
                    if(!in_array($error->httpStatus,[404,410],true))throw $error;
                    $threads[]=['id'=>$id,'questionStatus'=>HepsiburadaQuestionMapper::UNAVAILABLE,'messages'=>[]];
                }
            }
            return [$state,$threads,false];
        }

        if (!empty($state['listed'])) {
            $recent=$client->page($config,1,$deadline,answered:true);
            unset($state['listed'],$state['through'],$state['seen'],$state['pending']);
            $state['page']=1;
            return [$state,$recent['threads'],true];
        }

        $page=(int)($state['page']??1);
        $state['through']??=gmdate('c');
        $data=$client->page($config,$page,$deadline,through:$state['through']);
        $state['seen']=array_values(array_unique([...($state['seen']??[]),...array_column($data['threads'],'id')]));
        if(count($state['seen'])>20000)throw new SyncFailure(422);
        if (!$data['complete']) {
            $state['page']=$page+1;
            return [$state,$data['threads'],false];
        }

        $state['listed']=true;
        $seen=array_fill_keys($state['seen'],true);
        $waiting=$this->db->all("SELECT remote_id FROM inbox_threads t WHERE t.company_id=? AND t.provider='hepsiburada' AND ".InboxStatus::waiting().' AND '.InboxQuestionWindow::hasCurrentMessageSql(),[$company]);
        $state['pending']=array_values(array_filter(array_map('strval',array_column($waiting,'remote_id')),fn($id)=>!isset($seen[$id])));
        return [$state,$data['threads'],false];
    }

    private function commit(int $company,?array $before,string $revision,array $state,array $threads): void
    {
        $this->db->transaction(function()use($company,$before,$revision,$state,$threads){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$row=$this->state($company);$fresh=$this->vault->get($company,$this->provider);
            if(($row['version']??0)!==($before['version']??0)||$this->revision($fresh)!==$revision)throw new SyncFailure(409);
            $repo=new InboxRepository($this->db);
            foreach($threads as $thread){
                if($this->provider===HepsiburadaQuestionClient::PROVIDER&&($thread['questionStatus']??null)===HepsiburadaQuestionMapper::UNAVAILABLE){
                    $repo->markHepsiburadaQuestionUnavailable($company,$thread['id']);
                }else{
                    $repo->save($company,$thread,$this->provider);
                }
            }
            if($this->provider===HepsiburadaQuestionClient::PROVIDER){
                (new FinanceStore($this->db))->writeState($company,HepsiburadaQuestionClient::STATE_KEY,$state,$row);
                return;
            }
            $json=json_encode($state,JSON_THROW_ON_ERROR);
            if($row)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$json,gmdate('c'),$row['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_'.$this->provider.'_questions',$json,gmdate('c')]);
        });
    }
    public static function schedule(Database $db,IntegrationVault $vault): void
    {
        $hepsiburadaReady=($db->mysql()?$db->one("SHOW TABLES LIKE 'finance_sync'")
            :$db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='finance_sync'"))&&(new InboxRepository($db))->ready();
        foreach($db->all("SELECT company_id,kind FROM records WHERE kind IN ('integration_n11','integration_idefix','integration_hepsiburada')") as $row){
            $provider=substr($row['kind'],12);$company=(int)$row['company_id'];$config=$vault->get($company,$provider);if(empty($config['enabled']))continue;
            if($provider===HepsiburadaQuestionClient::PROVIDER&&!$hepsiburadaReady)continue;
            $name=$provider.' Sorular';(new SyncQueue($db))->enqueue($company,$name);
        }
    }
}
