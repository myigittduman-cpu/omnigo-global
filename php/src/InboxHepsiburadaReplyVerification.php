<?php
declare(strict_types=1);
namespace Omnigo;

/** Confirm Hepsiburada replies from the provider's conversation history. */
final class InboxHepsiburadaReplyVerification
{
    public const CHANGED_BEFORE_SEND='Konuşma değişti. Son mesajları inceleyip yeni yanıt hazırlayın.';
    public const SOURCE_ANSWERED='source_answered';

    public function __construct(private Database $db) {}

    /** A failed preflight belongs to the current incoming turn only while the provider reports it answered. */
    public static function sourceAnsweredSql(string $job): string
    {
        if(!in_array($job,['reply','j'],true))throw new \LogicException('Geçersiz yanıt sorgusu.');
        return "CASE WHEN t.provider='hepsiburada' AND $job.kind='reply' AND $job.state='failed'
            AND CAST(JSON_EXTRACT(t.payload,'$.questionStatus') AS CHAR)='1'
            AND CAST(JSON_EXTRACT(t.payload,'$.partial') AS CHAR) IN ('0','false')
            AND EXISTS (SELECT 1 FROM inbox_messages q WHERE q.thread_id=t.id AND q.inbound=1)
            AND NOT EXISTS (SELECT 1 FROM inbox_messages q WHERE q.thread_id=t.id AND q.inbound=1
                AND (q.created>=$job.created OR q.created>t.updated))
            THEN 1 ELSE 0 END";
    }

    public static function presentation(array $job,string $provider,bool $sourceAnswered=false): array
    {
        if($provider===HepsiburadaQuestionClient::PROVIDER&&$sourceAnswered&&$job['state']==='failed'
            &&($job['error']??null)===self::CHANGED_BEFORE_SEND){
            $job['state']=self::SOURCE_ANSWERED;
            $job['error']=null;
        }
        if ($provider===HepsiburadaQuestionClient::PROVIDER && $job['state']==='unknown') {
            $job['state']='verifying';
            $job['error']=null;
        }
        return $job;
    }

    public function pollDue(IntegrationVault $vault,int $deadline,?HepsiburadaQuestionClient $client=null): void
    {
        $now=time();
        $jobs=$this->db->all("SELECT j.id,j.company_id,j.thread_id,j.created,t.remote_id
            FROM inbox_jobs j JOIN inbox_threads t ON t.id=j.thread_id AND t.company_id=j.company_id
            WHERE t.provider='hepsiburada' AND j.kind='reply' AND j.state IN ('verifying','unknown')
              AND j.next_run<=? AND (j.lease_until IS NULL OR j.lease_until<?)
            ORDER BY j.next_run,j.created,j.id LIMIT 3",[$now,$now]);
        $client??=new HepsiburadaQuestionClient();
        $gate=new ProviderRequestGate($this->db);
        foreach ($jobs as $job) {
            if(time()+1>=$deadline)break;
            try{
                if(!$gate->run((int)$job['company_id'],'hepsiburada',$deadline,false,
                    fn()=>$this->pollOne($job,$vault,$client,$deadline)))break;
            }catch(ProviderRequestDeferred){}
            catch(SyncFailure $error){if($error->httpStatus===429)break;throw $error;}
        }
    }

    private function pollOne(array $job,IntegrationVault $vault,HepsiburadaQuestionClient $client,int $deadline): bool
    {
        $now=time();
        $token=bin2hex(random_bytes(24));
        $claim=$this->db->run("UPDATE inbox_jobs SET state='verifying',error=NULL,lease_token=?,lease_until=?
            WHERE id=? AND state IN ('verifying','unknown') AND next_run<=? AND (lease_until IS NULL OR lease_until<?)",
            [$token,$deadline+30,$job['id'],$now,$now]);
        if ($claim->rowCount()!==1) return true;
        $age=max(0,$now-(strtotime($job['created'])?:$now));
        $delay=$age<600?60:($age<3600?300:1800);
        try {
            $company=(int)$job['company_id'];
            $config=$vault->get($company,HepsiburadaQuestionClient::PROVIDER);
            if (empty($config['enabled'])) throw new SyncFailure(403);
            $thread=$client->detail($config,$job['remote_id'],min($deadline,time()+5));
            TransactionRetry::run($this->db,function () use ($company,$vault,$config,$job,$token,$thread) {
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                if ($vault->get($company,HepsiburadaQuestionClient::PROVIDER)!==$config
                    || !$this->db->one("SELECT id FROM inbox_jobs WHERE id=? AND state='verifying' AND lease_token=? AND lease_until>?",
                        [$job['id'],$token,time()])) return;
                if (($thread['questionStatus']??null)===HepsiburadaQuestionMapper::UNAVAILABLE) return;
                (new InboxRepository($this->db))->save($company,$thread,HepsiburadaQuestionClient::PROVIDER);
            });
        } catch (SyncFailure $error) {
            $delay=max($delay,$error->retryAfter,in_array($error->httpStatus,[401,403,404,410],true)?1800:0);
            if($error->httpStatus===429)throw $error;
        } catch (\Throwable $error) {
            $delay=max($delay,300);
            error_log('Omnigo Hepsiburada yanıt kontrolü: '.get_class($error));
        } finally {
            $this->db->run("UPDATE inbox_jobs SET next_run=?,lease_token=NULL,lease_until=NULL
                WHERE id=? AND state='verifying' AND lease_token=?",[time()+$delay,$job['id'],$token]);
        }
        return true;
    }

    public function reconcile(int $company,int $threadId,array $thread): void
    {
        if(!in_array($thread['questionStatus']??null,[1,HepsiburadaQuestionMapper::CLOSED],true)||!empty($thread['partial']))return;
        $job=$this->db->one("SELECT id,state,attempts,created,payload FROM inbox_jobs
            WHERE company_id=? AND thread_id=? AND kind='reply' ORDER BY created DESC,id DESC LIMIT 1".$this->db->lock(),
            [$company,$threadId]);
        if(!$job||!in_array($job['state'],['verifying','unknown'],true)||(int)$job['attempts']<1)return;
        $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
        $messageId=self::observedMessageId($payload,$job['created'],$thread['messages']??[]);
        if($messageId===null)return;
        $payload['messageId']=$messageId;
        $update=$this->db->run("UPDATE inbox_jobs SET state='confirmed_sent', error=NULL, payload=?,lease_token=NULL,lease_until=NULL
            WHERE id=? AND company_id=? AND thread_id=? AND kind='reply' AND state IN ('verifying','unknown')",
            [json_encode($payload,JSON_THROW_ON_ERROR),$job['id'],$company,$threadId]);
        if($update->rowCount()!==1)return;
        $this->db->run('INSERT INTO audit(company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?)',
            [$company,'Hepsiburada yanıtı pazaryeri geçmişinde doğrulandı',$threadId,'{"state":"confirmed_sent"}',gmdate('c')]);
    }

    public static function observedMessageId(array $payload,string $created,array $messages): ?string
    {
        $enqueuedAt=strtotime($created);
        $body=$payload['body']??null;
        $receipt=$payload['messageId']??null;
        if($enqueuedAt===false||!is_string($body)||$body==='')return null;
        $observed=null;
        foreach($messages as $message){
            if(($message['sender']??null)!=='SHOP_USER')continue;
            $id=$message['id']??null;
            $sentAt=strtotime($message['created']??'');
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$id)||str_starts_with($id,'local-')||$sentAt===false||$sentAt<$enqueuedAt)continue;
            if(is_string($receipt)?$id!==$receipt:($message['body']??null)!==$body)continue;
            if($observed!==null)return null;
            $observed=$id;
        }
        return $observed;
    }
}
