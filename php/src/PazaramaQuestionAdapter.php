<?php
declare(strict_types=1);
namespace Omnigo;

/** Isolated from sales jobs; unique remote message IDs make retries notification-safe. */
final class PazaramaQuestionAdapter implements ScheduledReadAdapter
{
    private int $delay=60;
    public function __construct(private Database $db,private IntegrationVault $vault,private ?PazaramaAuthClient $auth=null,private ?PazaramaReadClient $reader=null) {}
    public function nextDelay(): int {return $this->delay;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $this->delay=60;$config=$this->vault->get($companyId,'pazarama');
        if(!$config||!($config['enabled']??false))throw new SyncFailure(403);
        $row=$this->state($companyId);$state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        $page=($state['revision']??null)===$config['revision']?($state['page']??1):1;
        try{
            $token=($this->auth??new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($config),$deadline);
            PazaramaSellerIdentity::verify($token['accessToken'],$config['sellerId']);
            $data=($this->reader??new PazaramaReadClient())->questions($token['accessToken'],$page,$deadline);
            if(strcasecmp((string)($data['sellerId']??''),$config['sellerId'])!==0)throw new SyncFailure(403);
            $threads=array_map([PazaramaQuestionMapper::class,'thread'],$data['approvalAnswersByMerchantSearchs']);
            $complete=$page>=max(1,$data['pageResponse']['totalPages']);
            $next=['revision'=>$config['revision'],'page'=>$complete?1:$page+1,'phase'=>$complete?'ready':'syncing','lastPageAt'=>gmdate('c')];
            if($complete)$next['lastSuccessAt']=gmdate('c');elseif(isset($state['lastSuccessAt']))$next['lastSuccessAt']=$state['lastSuccessAt'];
            $this->commit($companyId,$row,$config,$next,$threads);$this->delay=$complete?900:60;
        }catch(\Throwable $error){
            $state['failure']=PazaramaDiagnostics::failure('questions',$error);
            $this->commit($companyId,$row,$config,$state,[]);throw $error;
        }finally{unset($token);}
    }
    private function state(int $company): ?array{return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_pazarama_questions'",[$company]);}
    private function commit(int $company,?array $before,array $config,array $next,array $threads): void
    {
        $this->db->transaction(function()use($company,$before,$config,$next,$threads){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->state($company);$fresh=$this->vault->get($company,'pazarama');
            if(($current['version']??0)!==($before['version']??0)||($fresh['revision']??null)!==$config['revision'])throw new SyncFailure(409);
            $repo=new InboxRepository($this->db);foreach($threads as $thread)$repo->save($company,$thread,'pazarama');
            $payload=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_pazarama_questions',$payload,gmdate('c')]);
        });
    }
}
