<?php
declare(strict_types=1);
namespace Omnigo;

/** One user-triggered page/reply per job. No periodic polling of Mirakl M10/M11. */
final class InboxWorker
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?\Closure $clientFactory=null) {}
    public function runOne(?int $now=null): bool
    {
        if(!(new InboxRepository($this->db))->ready())return false;
        $now??=time();$job=$this->claim($now);if(!$job)return false;
        $provider=null;$gate=new ProviderRequestGate($this->db);$token=null;$failure=null;
        try{
            $actor=$this->db->one("SELECT u.role,COALESCE(c.active,1) AS active FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.id=?",[$job['user_id']]);
            if(!$actor||$actor['role']!=='admin'||!(int)$actor['active'])throw new InboxFailure(403);
            $repo=new InboxRepository($this->db);$local=$job['thread_id']?$repo->thread((int)$job['thread_id']):null;
            if($job['thread_id']&&(!$local||(int)$local['company_id']!==(int)$job['company_id']))throw new InboxFailure(404);
            $provider=$local['provider']??'koctas';
            $company=(int)$job['company_id'];
            if($job['kind']==='scan'&&class_exists(CustomerSetupSelection::class)
                && !CustomerSetupSelection::hasLive($this->db,$company,'inbox'))throw new InboxFailure(403);
            if($job['kind']==='reply'&&class_exists(CustomerSetupSelection::class)
                && !CustomerSetupSelection::hasManual($this->db,$company,'questionReply'))throw new InboxFailure(403);
            $pazarama=$provider==='pazarama';
            $productQuestions=InboxReplyPolicy::productQuestions($provider);
            if(!InboxReplyPolicy::supported($provider))throw new InboxFailure(409);
            $config=$this->vault->get((int)$job['company_id'],$provider);if(!$config||empty($config['enabled']))throw new InboxFailure(401);
            $deadline=time()+75;
            $token=$gate->acquire($company,$provider,$deadline);
            $client=$this->clientFactory?($this->clientFactory)($config):match($provider){
                'hepsiburada'=>new HepsiburadaQuestionClient(),
                'n11'=>new N11QuestionClient(),
                'idefix'=>new IdefixQuestionClient(),
                'trendyol'=>new TrendyolQuestionClient(),
                'pazarama'=>new PazaramaInboxClient($config),
                default=>new KoctasInboxClient($config['apiKey'],(int)$config['shopId']),
            };
            $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
            if($job['kind']==='scan'){
                if(($payload['updatedSince']??null)!==InboxQuestionWindow::START)$payload=['updatedSince'=>InboxQuestionWindow::START];
                $result=$client->threads($payload['cursor']??null);
                if(!is_array($result['data']??null))throw new InboxFailure(502);
                $this->assertLease($job);
                foreach($result['data'] as $raw){$thread=InboxMapping::thread($raw);if($thread)$repo->save((int)$job['company_id'],$thread);}
                $cursor=$result['next_page_token']??null;
                if($cursor!==null){
                    if(!is_string($cursor)||strlen($cursor)>2000||in_array($cursor,$payload['visited']??[],true)||count($payload['visited']??[])>=100)throw new InboxFailure(422);
                    $payload['visited'][]=$cursor;$payload['cursor']=$cursor;
                    $this->finish($job,'pending',null,$now+1,$payload,true);return true;
                }
                $this->finish($job,'done',null,$now);return true;
            }
            $local=$repo->thread((int)$job['thread_id']);if(!$local||(int)$local['company_id']!==(int)$job['company_id'])throw new InboxFailure(404);
            $thread=$this->loadThread($client,$provider,$config,$local['remote_id'],$deadline);
            if(!$thread||$thread['id']!==$local['remote_id'])throw new InboxFailure(422);
            $this->assertLease($job);$repo->save((int)$job['company_id'],$thread,$provider);
            if($job['kind']==='detail'){$this->finish($job,'done',null,$now);return true;}
            // Refresh immediately before sending: never answer a stale snapshot or a different recipient.
            if(!InboxReplyPolicy::mayReply($provider,$thread)||$thread['recipients']!==$payload['recipients']||!hash_equals($thread['fingerprint'],$payload['fingerprint'])){
                $this->finish($job,'failed',InboxHepsiburadaReplyVerification::CHANGED_BEFORE_SEND,$now);return true;
            }
            $this->assertLease($job);
            if($provider!=='koctas'&&!empty($payload['attachments']))throw new InboxFailure(422);
            $files=empty($payload['attachments'])?[]:InboxAttachments::local()->load((int)$job['company_id'],(int)$job['thread_id'],(int)$job['user_id'],$payload['attachments'],true);
            try{
                $sent=$this->sendReply($client,$provider,$config,$thread,$payload['body'],$files,$deadline,$job);
            }catch(InboxFailure $error){
                $answered=$error->answeredQuestion;
                if($provider!==HepsiburadaQuestionClient::PROVIDER||($answered['id']??null)!==$thread['id'])throw $error;
                // The final provider read observed an answer between the two preflight checks. No POST took place.
                $this->db->transaction(function()use($repo,$provider,$company,$config,$answered,$job,$now){
                    $this->assertLease($job);
                    if($this->vault->get($company,$provider)!==$config)throw new InboxFailure(409);
                    $repo->save($company,$answered,$provider);
                    $this->finish($job,'failed',InboxHepsiburadaReplyVerification::CHANGED_BEFORE_SEND,$now);
                });
                return true;
            }
            if(($sent['thread_id']??null)!==$thread['id']||($pazarama||$productQuestions?($sent['accepted']??false)!==true:!is_string($sent['message_id']??null)))throw new InboxFailure(502,0,true);
            // Save the acknowledgement before any follow-up reads. An uncertain send is never retried.
            $payload['messageId']=$sent['message_id']??null;
            if($provider===HepsiburadaQuestionClient::PROVIDER){
                $this->finish($job,'verifying',null,time(),$payload);
                return true;
            }
            if($provider==='trendyol'){
                $thread['replyNeeded']=false;
                $thread['questionStatus']=2;
                $thread['updated']=gmdate('c');
                $this->db->transaction(function()use($job,$payload,$thread,$company,$repo){
                    $this->assertLease($job);
                    $repo->save($company,$thread,'trendyol');
                    $this->finish($job,'accepted',null,time(),$payload);
                });
                return true;
            }
            $this->finish($job,'sent',null,$now,$payload);
            $thread['replyNeeded']=false;$thread['updated']=gmdate('c');
            if($pazarama||$productQuestions)$thread['localReplyState']='sent';
            $message=['id'=>'local-'.$job['id'],'sender'=>'SHOP_USER','body'=>$payload['body'],'created'=>gmdate('c'),'attachments'=>count($files)];
            $thread['messages'][]=$message;
            $thread['fingerprint']=hash('sha256',json_encode($thread,JSON_THROW_ON_ERROR));
            $repo->save((int)$job['company_id'],$thread,$provider);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$job['user_id'],$job['company_id'],'Müşteri yanıtı pazaryerine iletildi',$job['thread_id'],gmdate('c')]);
        }catch(ProviderRequestDeferred $delay){
            $job['attempts']=max(0,$job['attempts']-1);
            $this->finish($job,'pending',$job['error'],max($now+1,$delay->retryAt));
        }catch(\Throwable $e){
            $error=$e instanceof InboxFailure?$e:new InboxFailure(503,0,$job['kind']==='reply');
            $failure=$error;
            if($job['kind']==='reply'&&$provider===HepsiburadaQuestionClient::PROVIDER&&$error->uncertain){
                $this->finish($job,'verifying',null,time()+max(0,$error->retryAfter));
                return true;
            }
            $retryRead=$job['kind']!=='reply'&&(in_array($error->status,[408,425,429],true)||($error->status>=500&&$error->status<=599));
            $state=$retryRead?'pending':($error->uncertain?'unknown':((in_array($error->status,[401,403,404,409,422],true)||($error->status>=400&&$error->status<500&&$error->status!==429)||$job['attempts']>=5)?'failed':'pending'));
            $message=$state==='unknown'?'Gönderim sonucu doğrulanamadı. Tekrar gönderilmedi; pazaryerindeki konuşmayı kontrol edin.':($state==='pending'?'Bağlantı bekleniyor. Yeniden deneme planlandı.':'İşlem tamamlanamadı. Bağlantı yetkisini veya konuşmayı kontrol edin.');
            $this->finish($job,$state,$message,$now+max($error->retryAfter,min($retryRead&&$error->status!==429?300:3600,30*2**min(7,$job['attempts']))+random_int(0,10)));
        }finally{
            if($provider!==null)$gate->release((int)$job['company_id'],$provider,$token,$failure);
        }
        return true;
    }

    private function loadThread(object $client,string $provider,array $config,string $id,int $deadline): ?array
    {
        if(InboxReplyPolicy::productQuestions($provider)){
            try{return $client->detail($config,$id,$deadline);}
            catch(SyncFailure $error){throw new InboxFailure($error->httpStatus,$error->retryAfter);}
        }
        $result=$client->thread($id);
        return $provider==='pazarama'?$result:InboxMapping::thread($result);
    }

    private function sendReply(object $client,string $provider,array $config,array $thread,string $body,array $files,int $deadline,array $job): array
    {
        $check=fn()=>$this->assertLease($job);
        return match($provider){
            'hepsiburada'=>$client->reply($config,$thread['id'],$body,$thread['fingerprint'],$deadline,$check),
            'n11','trendyol'=>$client->reply($config,$thread['id'],$body,$deadline,$check),
            'idefix'=>$client->reply($config,$thread['id'],$body,$thread['fingerprint'],$deadline,$check),
            default=>$files?$client->reply($thread['id'],$body,$thread['recipients'],$files):$client->reply($thread['id'],$body,$thread['recipients']),
        };
    }
    private function claim(int $now): ?array
    {
        return $this->db->transaction(function()use($now){
            $this->db->run("UPDATE inbox_jobs SET state='verifying',error=NULL,next_run=?,lease_token=NULL,lease_until=NULL
                WHERE state='running' AND kind='reply' AND lease_until<? AND EXISTS (
                    SELECT 1 FROM inbox_threads t WHERE t.id=inbox_jobs.thread_id AND t.company_id=inbox_jobs.company_id AND t.provider='hepsiburada'
                )",[$now,$now]);
            $this->db->run("UPDATE inbox_jobs SET state='unknown',error='Gönderim kesildi. Pazaryerinden kontrol edilmeden tekrar gönderilmeyecek.',lease_token=NULL,lease_until=NULL WHERE state='running' AND kind='reply' AND lease_until<?",[$now]);
            $this->db->run("UPDATE inbox_jobs SET state='pending',lease_token=NULL,lease_until=NULL WHERE state='running' AND kind<>'reply' AND lease_until<?",[$now]);
            $job=$this->db->one("SELECT * FROM inbox_jobs WHERE state='pending' AND next_run<=? ORDER BY created,id LIMIT 1".$this->db->lock(),[$now]);
            if(!$job)return null;$job['lease_token']=bin2hex(random_bytes(24));$job['attempts']=(int)$job['attempts']+1;
            $this->db->run("UPDATE inbox_jobs SET state='running',attempts=?,lease_until=?,lease_token=? WHERE id=?",[$job['attempts'],$now+120,$job['lease_token'],$job['id']]);return $job;
        });
    }
    private function assertLease(array $job): void
    {
        if(!$this->db->one("SELECT id FROM inbox_jobs WHERE id=? AND lease_token=? AND state='running' AND lease_until>?",[$job['id'],$job['lease_token'],time()]))throw new InboxFailure(409);
    }
    private function finish(array $job,string $state,?string $error,int $next,?array $payload=null,bool $reset=false): void
    {
        $this->db->run('UPDATE inbox_jobs SET state=?,error=?,next_run=?,attempts=?,payload=?,lease_token=NULL,lease_until=NULL WHERE id=? AND lease_token=?',[$state,$error,$next,$reset?0:$job['attempts'],$payload===null?$job['payload']:json_encode($payload,JSON_THROW_ON_ERROR),$job['id'],$job['lease_token']]);
    }
}
