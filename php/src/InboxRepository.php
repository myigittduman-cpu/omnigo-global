<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxRepository
{
    public function __construct(private Database $db) {}
    public function ready(): bool
    {
        try {$this->db->one('SELECT id FROM inbox_threads LIMIT 1');return true;}catch(\PDOException){return false;}
    }
    public function save(int $company,array $thread,string $provider='koctas'): int
    {
        if(!in_array($provider,['koctas','pazarama','n11','idefix',HepsiburadaQuestionClient::PROVIDER,TrendyolQuestionClient::PROVIDER],true))throw new \InvalidArgumentException('Pazaryeri geçersiz.');
        $save=function()use($company,$thread,$provider){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT * FROM inbox_threads WHERE company_id=? AND provider=? AND remote_id=?",[$company,$provider,$thread['id']]);
            if(InboxStatus::value($thread)==='rejected'){
                if($row)$this->removeRejected((int)$row['id']);
                return (int)($row['id']??0);
            }
            $customerAt=InboxQuestionWindow::latestCustomerMessage($thread);
            $waiting=InboxStatus::value($thread)==='waiting';
            if(!$row&&(($waiting&&(!InboxQuestionWindow::contains($customerAt)||!empty($thread['archived'])))
                ||($provider==='koctas'&&(!$waiting||count($thread['recipients']??[])!==1||!empty($thread['partial'])))))return 0;
            $payload=$thread;unset($payload['messages']);
            if($customerAt!==null)$payload['latestCustomerAt']=$customerAt;
            $json=json_encode($payload,JSON_THROW_ON_ERROR);
            if($row){$id=(int)$row['id'];$previous=json_decode($row['payload'],true);$confirmed=($previous['localReplyState']??null)==='sent'&&in_array($thread['questionStatus']??null,[1,2],true);if($confirmed||strcmp($thread['updated'],$row['updated'])>=0)$this->db->run('UPDATE inbox_threads SET payload=?,updated=? WHERE id=?',[$json,$thread['updated'],$id]);}
            else{$this->db->run("INSERT INTO inbox_threads(company_id,provider,remote_id,payload,updated) VALUES(?,?,?,?,?)",[$company,$provider,$thread['id'],$json,$thread['updated']]);$id=$this->db->insertId();}
            $hepsiburada=$provider===HepsiburadaQuestionClient::PROVIDER;
            foreach($this->db->all("SELECT id,remote_id,payload FROM inbox_messages WHERE thread_id=? AND remote_id LIKE 'local-%'",[$id]) as $local){
                $cached=json_decode($local['payload'],true);
                if($hepsiburada&&empty($cached['sourceMessageId'])){
                    $job=$this->db->one("SELECT payload FROM inbox_jobs WHERE id=? AND company_id=? AND thread_id=? AND kind='reply' AND state='sent'",
                        [substr($local['remote_id'],6),$company,$id]);
                    $receipt=$job===null?null:json_decode($job['payload'],true);
                    $cached['sourceMessageId']=$receipt['messageId']??null;
                }
                foreach($thread['messages'] as $message){
                    if($message['sender']!=='SHOP_USER'||str_starts_with($message['id'],'local-'))continue;
                    $matches=$hepsiburada
                        ?$message['id']===($cached['sourceMessageId']??null)
                        :$message['body']===($cached['body']??null);
                    if($matches){$this->db->run('DELETE FROM inbox_messages WHERE id=?',[$local['id']]);break;}
                }
            }
            $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            foreach($thread['messages'] as $m){
                if($hepsiburada&&isset($m['sourceMessageId'])&&str_starts_with($m['id'],'local-')
                    &&$this->db->one('SELECT id FROM inbox_messages WHERE thread_id=? AND remote_id=?',[$id,$m['sourceMessageId']]))continue;
                $this->db->run("$insert INTO inbox_messages(thread_id,remote_id,inbound,payload,created) VALUES(?,?,?,?,?)",[$id,$m['id'],$m['sender']==='CUSTOMER_USER'?1:0,json_encode($m,JSON_THROW_ON_ERROR),$m['created']]);
            }
            if($hepsiburada)(new InboxHepsiburadaReplyVerification($this->db))->reconcile($company,$id,$thread);
            return $id;
        };
        return $this->db->pdo->inTransaction()?$save():$this->db->transaction($save);
    }
    private static function providerName(string $provider): string {return match($provider){'koctas'=>'Koçtaş','pazarama'=>'Pazarama','n11'=>'n11','idefix'=>'idefix',HepsiburadaQuestionClient::PROVIDER=>'Hepsiburada',TrendyolQuestionClient::PROVIDER=>'Trendyol',default=>'Pazaryeri'};}
    public function markHepsiburadaQuestionUnavailable(int $company,string $remoteId):void
    {
        $this->markQuestionUnavailable($company,HepsiburadaQuestionClient::PROVIDER,$remoteId);
    }
    public function markQuestionUnavailable(int $company,string $provider,string $remoteId):void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Soru durumu için işlem kilidi gerekiyor.');
        $row=$this->db->one('SELECT id,payload FROM inbox_threads WHERE company_id=? AND provider=? AND remote_id=?'.$this->db->lock(),
            [$company,$provider,$remoteId]);
        if(!$row)return;
        $payload=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        $payload['questionStatus']=5;
        $payload['replyNeeded']=false;
        $this->db->run('UPDATE inbox_threads SET payload=? WHERE id=?',[json_encode($payload,JSON_THROW_ON_ERROR),$row['id']]);
    }
    public function removeRejected(int $id): void
    {
        $this->db->run('DELETE FROM inbox_reads WHERE message_id IN (SELECT id FROM inbox_messages WHERE thread_id=?)',[$id]);
        $this->db->run('DELETE FROM inbox_messages WHERE thread_id=?',[$id]);
        $row=$this->thread($id);if(!$row)return;
        $tombstone=['id'=>$row['remote_id'],'title'=>'Reddedilen soru','updated'=>$row['updated'],'questionStatus'=>3,'replyNeeded'=>false,'recipients'=>[],'entities'=>[],'partial'=>false,'fingerprint'=>hash('sha256','rejected:'.$row['remote_id'])];
        $this->db->run('UPDATE inbox_threads SET payload=? WHERE id=?',[json_encode($tombstone,JSON_THROW_ON_ERROR),$id]);
    }
    public function thread(int $id): ?array {return $this->db->one('SELECT * FROM inbox_threads WHERE id=?',[$id]);}
    public function replySnapshot(array $thread): array
    {
        $payload=json_decode($thread['payload'],true,64,JSON_THROW_ON_ERROR);
        $payload['latestCustomerAt']??=$this->db->one('SELECT MAX(created) AS created FROM inbox_messages WHERE thread_id=? AND inbound=1',[$thread['id']])['created']??null;
        return $payload;
    }
    public function detail(int $id): array
    {
        $thread=$this->thread($id);if(!$thread)Http::fail(404,'Konuşma bulunamadı.');
        $payload=$this->replySnapshot($thread);
        if(InboxStatus::value($payload)==='rejected')Http::fail(404,'Konuşma bulunamadı.');
        $identifiers=InboxQuestionFilters::identifiers($payload);
        if($identifiers)$payload['questionIdentifiers']=$identifiers;
        $products=new InboxQuestionProducts($this->db);
        $products->prime([[(int)$thread['company_id'],$thread['provider'],$payload]]);
        unset($payload['imageUrl']);
        $payload=array_replace($payload,$products->present((int)$thread['company_id'],$thread['provider'],$payload,true));
        $messages=$this->db->all('SELECT id,payload FROM inbox_messages WHERE thread_id=? ORDER BY created DESC,id DESC LIMIT 500',[$id]);
        $evidence=InboxHepsiburadaReplyVerification::sourceAnsweredSql('j');
        $jobs=$this->db->all("SELECT j.id,j.kind,j.state,j.error,j.created,j.payload,$evidence AS source_answered
            FROM inbox_jobs j JOIN inbox_threads t ON t.id=j.thread_id AND t.company_id=j.company_id
            WHERE j.thread_id=? AND j.company_id=? ORDER BY j.created DESC,j.id DESC LIMIT 20",[$id,$thread['company_id']]);
        foreach($jobs as &$job){$p=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);$job['body']=$p['body']??null;unset($job['payload']);
            if($job['kind']==='reply')$job=InboxHepsiburadaReplyVerification::presentation($job,$thread['provider'],(bool)$job['source_answered']);
            unset($job['source_answered']);
        }unset($job);
        $display=$this->db->one('SELECT '.InboxStatus::displayed().' AS question_status,reply.state AS reply_state,reply.error AS reply_error,'.InboxHepsiburadaReplyVerification::sourceAnsweredSql('reply').' AS source_answered,'.InboxStatus::pendingReply().' AS active_reply
            FROM inbox_threads t'.InboxStatus::replyJoin().' WHERE t.id=? AND t.company_id=?',[$id,$thread['company_id']]);
        return ['id'=>$id,'companyId'=>(int)$thread['company_id'],'provider'=>self::providerName($thread['provider']),'status'=>$display['question_status'],
            ...self::replyStatus($display,$thread['provider']),
            'canReply'=>self::mayReply($thread['provider'],$payload,$display),'replySupported'=>InboxReplyPolicy::supported($thread['provider']),
            'attachmentsSupported'=>$thread['provider']==='koctas',
            'data'=>$payload,'messages'=>array_reverse(array_map(fn($m)=>['localId'=>(int)$m['id'],...json_decode($m['payload'],true,64,JSON_THROW_ON_ERROR)],$messages)),'jobs'=>$jobs];
    }
    public function summary(int $user,int $page=0,bool $unreadOnly=false,?int $company=null,string $status='waiting',?string $provider=null,string $search='',int $pageSize=50,string $sort='newest',bool $table=false): array
    {
        if($table&&!$this->db->pdo->inTransaction())
            return $this->db->transaction(fn()=>$this->summary($user,$page,$unreadOnly,$company,$status,$provider,$search,$pageSize,$sort,$table));
        $scope=$company===null?'':' AND t.company_id=?';$args=$company===null?[$user]:[$user,$company];
        if(!in_array($status,InboxStatus::FILTERS,true))Http::fail(400,'Mesaj durumu geçersiz.');
        [$filters,$parameters]=InboxQuestionFilters::selection($this->db,null,$search);
        [$providerClause,$providerArgs]=InboxQuestionFilters::selection($this->db,$provider,'');
        $effective=InboxStatus::displayed();
        $replyJoin=InboxStatus::replyJoin();
        $active=InboxQuestionWindow::activeMessageSql($this->db);
        $where=$scope.' AND '.InboxStatus::visible()." AND (($effective)<>'waiting' OR ($active))"
            .($status==='all'?'':" AND ($effective)='$status'").($unreadOnly?' AND r.message_id IS NULL':'').$filters;
        if($pageSize!==10)$pageSize=50;
        $offset=$page*$pageSize;
        $limit=$pageSize+1;
        $direction=$sort==='oldest'?'ASC':'DESC';
        if($table&&$status==='rejected'){
            return $this->rejectedTable($company,$provider,$unreadOnly,$search,$pageSize,$offset,$direction);
        }
        // Pick one incoming question per conversation before pagination, including the latest unread one.
        $latest=" AND NOT EXISTS(SELECT 1 FROM inbox_messages newer WHERE newer.thread_id=m.thread_id AND newer.inbound=1 AND (newer.created>m.created OR (newer.created=m.created AND newer.id>m.id))".($unreadOnly?' AND NOT EXISTS(SELECT 1 FROM inbox_reads nr WHERE nr.message_id=newer.id AND nr.user_id='.(int)$user.')':'').')';
        $queryScope="FROM inbox_messages m JOIN inbox_threads t ON t.id=m.thread_id JOIN companies c ON c.id=t.company_id
            LEFT JOIN inbox_reads r ON r.message_id=m.id AND r.user_id=?$replyJoin WHERE m.inbound=1$where$latest";
        $currentInbound=$unreadOnly?"(t.provider<>'hepsiburada' OR NOT EXISTS (SELECT 1 FROM inbox_messages q WHERE q.thread_id=m.thread_id AND q.inbound=1 AND (q.created>m.created OR (q.created=m.created AND q.id>m.id))))":'1';
        $activeReply=$table?','.InboxStatus::pendingReply().' AS active_reply,'.InboxHepsiburadaReplyVerification::sourceAnsweredSql('reply')." AS source_answered,$currentInbound AS current_inbound":'';
        $rows=$this->db->all("SELECT m.id,m.thread_id,m.payload,m.created,t.company_id,t.provider,t.payload AS thread_payload,c.name AS company_name,r.message_id AS read_id,
            $effective AS question_status,reply.state AS reply_state,reply.error AS reply_error$activeReply
            $queryScope$providerClause ORDER BY m.created $direction,m.id $direction LIMIT $limit OFFSET $offset",[...$args,...$parameters,...$providerArgs]);
        $more=count($rows)>$pageSize;$rows=array_slice($rows,0,$pageSize);
        $threads=array_map(static fn(array $row):array=>json_decode($row['thread_payload'],true,64,JSON_THROW_ON_ERROR),$rows);
        $products=new InboxQuestionProducts($this->db);
        $products->prime(array_map(static fn(array $row,array $thread):array=>[(int)$row['company_id'],$row['provider'],$thread],$rows,$threads));
        $items=array_map(function($r,$t)use($table,$pageSize,$products){
            $m=json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR);
            $status=$r['question_status'];
            return ['id'=>(int)$r['id'],'threadId'=>(int)$r['thread_id'],'companyId'=>(int)$r['company_id'],'company'=>$r['company_name'],
                'provider'=>self::providerName($r['provider']),'preview'=>mb_substr($m['body'],0,180),
                ...$products->present((int)$r['company_id'],$r['provider'],$t,$table&&$pageSize===10),
                'created'=>$r['created'],'unread'=>$r['read_id']===null,'replyNeeded'=>$status==='waiting','status'=>$status,
                ...InboxQuestionFilters::identifiers($t),
                ...($table?['question'=>$m['body'],'dateOnly'=>!empty($m['dateOnly']),'expiresAt'=>$t['expiresAt']??null,
                    'canReply'=>(bool)$r['current_inbound']&&self::mayReply($r['provider'],['latestCustomerAt'=>$r['created'],...$t],$r),'replySupported'=>InboxReplyPolicy::supported($r['provider']),
                    ...($r['current_inbound']?self::replyStatus($r,$r['provider']):[])]:[]),
            ];
        },$rows,$threads);
        $count=(int)$this->db->one("SELECT COUNT(*) AS n FROM inbox_messages m JOIN inbox_threads t ON t.id=m.thread_id LEFT JOIN inbox_reads r ON r.message_id=m.id AND r.user_id=?$replyJoin WHERE m.inbound=1 AND r.message_id IS NULL$scope AND ($effective)='waiting' AND $active",$args)['n'];
        $waiting=$this->waitingCount($company,$table?$provider:null);
        $jobs=(new InboxQuestionActivity($this->db))->recent($company,$provider);
        if($pageSize===50)return ['available'=>true,'items'=>$items,'unreadCount'=>$count,'waitingCount'=>$waiting,'hasMore'=>$more,'jobs'=>$jobs];
        [$total,$providerCounts]=$this->questionTotals($queryScope,[...$args,...$parameters],$provider);
        return ['available'=>true,'items'=>$items,'unreadCount'=>$count,'waitingCount'=>$waiting,'hasMore'=>$more,'jobs'=>$jobs,'total'=>$total,'providerCounts'=>$providerCounts];
    }

    /** Rejected conversations retain metadata only; their discarded questions stay inaccessible. */
    private function rejectedTable(?int $company,?string $provider,bool $unreadOnly,string $search,int $pageSize,int $offset,string $direction): array
    {
        $filters=["CAST(JSON_EXTRACT(t.payload,'$.questionStatus') AS CHAR)='3'"];
        $parameters=[];
        if($company!==null){$filters[]='t.company_id=?';$parameters[]=$company;}
        if($search!==''){$filters[]="LOWER(t.remote_id) LIKE ? ESCAPE '='";$parameters[]='%'.strtr(mb_strtolower($search,'UTF-8'),['='=>'==','%'=>'=%','_'=>'=_']).'%';}
        $where=' WHERE '.implode(' AND ',$filters);
        [$providerClause,$providerArgs]=InboxQuestionFilters::selection($this->db,$provider,'');
        $jobs=(new InboxQuestionActivity($this->db))->recent($company,$provider);
        $waiting=$this->waitingCount($company,$provider);
        if($unreadOnly)return ['available'=>true,'items'=>[],'unreadCount'=>0,'waitingCount'=>$waiting,'hasMore'=>false,'jobs'=>$jobs,'total'=>0,'providerCounts'=>(object)[]];
        $rows=$this->db->all("SELECT t.id,t.company_id,t.provider,t.updated,c.name AS company
            FROM inbox_threads t JOIN companies c ON c.id=t.company_id$where$providerClause
            ORDER BY t.updated $direction,t.id $direction LIMIT $pageSize OFFSET $offset",[...$parameters,...$providerArgs]);
        $items=array_map(static fn(array $row):array=>[
            'id'=>(int)$row['id'],'threadId'=>(int)$row['id'],'companyId'=>(int)$row['company_id'],'company'=>$row['company'],
            'provider'=>self::providerName($row['provider']),'title'=>'Reddedilen soru','preview'=>'','question'=>'',
            'created'=>$row['updated'],'unread'=>false,'replyNeeded'=>false,'status'=>'rejected','canReply'=>false,'replySupported'=>false,'canInspect'=>false,
        ],$rows);
        [$total,$providerCounts]=$this->questionTotals("FROM inbox_threads t$where",$parameters,$provider);
        return ['available'=>true,'items'=>$items,'unreadCount'=>0,'waitingCount'=>$waiting,'hasMore'=>$offset+count($items)<$total,'jobs'=>$jobs,'total'=>$total,'providerCounts'=>$providerCounts];
    }

    /** Aggregated counts use the list's company, status, search and unread conditions. */
    private function questionTotals(string $queryScope,array $args,?string $provider): array
    {
        $counts=array_map('intval',array_column(
            $this->db->all("SELECT t.provider,COUNT(*) AS n $queryScope GROUP BY t.provider",$args),'n','provider'));
        return [$provider===null?array_sum($counts):($counts[$provider]??0),(object)$counts];
    }

    public function waitingCount(?int $company,?string $provider=null): int
    {
        $effective=InboxStatus::displayed();
        $reply=InboxStatus::replyJoin();
        $active=InboxQuestionWindow::activeMessageSql($this->db);
        $filters=["($effective)='waiting'","EXISTS(SELECT 1 FROM inbox_messages m WHERE m.thread_id=t.id AND m.inbound=1 AND $active)"];
        $parameters=[];
        if($company!==null){$filters[]='t.company_id=?';$parameters[]=$company;}
        if($provider!==null){$filters[]='t.provider=?';$parameters[]=$provider;}
        return (int)$this->db->one("SELECT COUNT(*) AS n FROM inbox_threads t$reply WHERE ".implode(' AND ',$filters),$parameters)['n'];
    }
    private static function mayReply(string $provider,array $thread,array $display): bool
    {
        if(!empty($display['active_reply']))return false;
        if($provider!=='koctas'&&in_array($display['reply_state'],['sent','confirmed_sent'],true))return false;
        return InboxReplyPolicy::mayReply($provider,$thread);
    }
    private static function replyStatus(array $row,string $provider): array
    {
        ['state'=>$state,'error'=>$error]=InboxHepsiburadaReplyVerification::presentation(
            ['state'=>$row['reply_state']??null,'error'=>$row['reply_error']??null],$provider,(bool)($row['source_answered']??false));
        if(!in_array($state,['pending','running','verifying','sent','accepted','confirmed_sent',InboxHepsiburadaReplyVerification::SOURCE_ANSWERED,'failed','unknown','cancelled'],true))return [];
        // Preserve only known worker copy. Legacy or manually inserted errors can contain arbitrary text.
        $knownErrors=[
            InboxHepsiburadaReplyVerification::CHANGED_BEFORE_SEND,
            'Gönderim sonucu doğrulanamadı. Tekrar gönderilmedi; pazaryerindeki konuşmayı kontrol edin.',
            'Gönderim kesildi. Pazaryerinden kontrol edilmeden tekrar gönderilmeyecek.',
            'İşlem tamamlanamadı. Bağlantı yetkisini veya konuşmayı kontrol edin.',
        ];
        return ['replyState'=>$state,...(in_array($state,['failed','unknown'],true)&&in_array($error,$knownErrors,true)?['replyError'=>$error]:[])];
    }
    public function markRead(int $user,int $message,?int $company=null): void
    {
        $scope=$company===null?'':' AND t.company_id=?';
        if(!$this->db->one("SELECT m.id FROM inbox_messages m JOIN inbox_threads t ON t.id=m.thread_id WHERE m.id=? AND m.inbound=1$scope",$company===null?[$message]:[$message,$company]))Http::fail(404,'Bildirim bulunamadı.');
        $sql=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';$this->db->run("$sql INTO inbox_reads(user_id,message_id) VALUES(?,?)",[$user,$message]);
    }
}
