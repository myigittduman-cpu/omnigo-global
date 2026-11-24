<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxService
{
    public function __construct(private Database $db) {}
    public function enqueue(int $company,int $user,string $kind,array $payload=[],?int $thread=null,?string $requestId=null): string
    {
        if(!in_array($kind,['scan','detail','reply'],true))throw new \LogicException('Bilinmeyen mesaj işlemi.');
        $id=$requestId??bin2hex(random_bytes(24));
        return $this->db->transaction(function()use($company,$user,$kind,$payload,$thread,$id){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if($old=$this->db->one('SELECT * FROM inbox_jobs WHERE id=?',[$id])){
                $original=json_decode($old['payload'],true);unset($original['messageId']);
                if((int)$old['user_id']!==$user||(int)$old['company_id']!==$company||(int)$old['thread_id']!==(int)$thread||$old['kind']!==$kind||$original!==$payload)Http::fail(409,'İşlem kimliği farklı bir isteğe ait.');
                return $id;
            }
            $where=$thread===null?'thread_id IS NULL':'thread_id='.(int)$thread;
            $active=$this->db->one("SELECT id,state FROM inbox_jobs WHERE company_id=? AND kind=? AND $where AND state IN ('pending','running','verifying','unknown') ORDER BY created DESC LIMIT 1",[$company,$kind]);
            if($active){if($kind==='reply')Http::fail(409,'Bu konuşmada bekleyen veya sonucu doğrulanmamış bir yanıt var. Önce pazaryerini kontrol edin.');return $active['id'];}
            if($kind==='reply'&&$this->db->one('SELECT reply.id FROM inbox_threads t'.InboxStatus::replyJoin()
                ." WHERE t.id=? AND t.company_id=? AND reply.state IN ('failed','accepted')",[$thread,$company])){
                Http::fail(409,'Önce İncele bölümünden önceki yanıtın sonucunu doğrulayın.');
            }
            $provider=$kind==='reply'&&$thread!==null?$this->db->one('SELECT provider FROM inbox_threads WHERE company_id=? AND id=?',[$company,$thread])['provider']??'':'';
            if(InboxReplyPolicy::productQuestions($provider)){
                $sent=$this->db->one("SELECT payload FROM inbox_jobs WHERE company_id=? AND kind='reply' AND $where AND state IN ('sent','confirmed_sent') ORDER BY created DESC,id DESC LIMIT 1",[$company]);
                $accepted=$sent?json_decode($sent['payload'],true):null;
                if($accepted!==null&&($provider==='n11'||(isset($accepted['fingerprint'])&&$accepted['fingerprint']===($payload['fingerprint']??null)))){
                    Http::fail(409,'Bu soruya verilen yanıt pazaryerine zaten iletildi.');
                }
            }
            $last=$this->db->one("SELECT created FROM inbox_jobs WHERE company_id=? AND kind=? AND $where ORDER BY created DESC LIMIT 1",[$company,$kind]);
            if($kind!=='reply'&&$last&&strtotime($last['created'])>time()-60)Http::fail(429,'Yeni kontrol için bir dakika bekleyin.');
            // Keep replacement replies after the earlier attempt even within the same second.
            $created = $kind === 'reply' ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP') : gmdate('c');
            $this->db->run('INSERT INTO inbox_jobs(id,company_id,thread_id,kind,user_id,payload,state,next_run,created) VALUES(?,?,?,?,?,?,?, ?,?)',[$id,$company,$thread,$kind,$user,json_encode($payload,JSON_THROW_ON_ERROR),'pending',time(),$created]);
            if($kind==='reply')$this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$user,$company,'Müşteri yanıtı sıraya alındı',$thread,gmdate('c')]);
            return $id;
        });
    }
}
