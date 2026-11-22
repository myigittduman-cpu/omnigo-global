<?php
declare(strict_types=1);
namespace Omnigo;

/** A resolution belongs to the requesting customer, never the whole marketplace feed. */
final class SupportResolutionNotifications
{
    public function __construct(private Database $db){}
    public function record(array $actor,int $company,array $ticket,int $audit,string $operation='resolved'): void
    {
        if(($actor['role']??'')!=='admin')return;
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Destek bildirimi aynı işlemde kaydedilmeli.');
        $recipient=$this->db->one("SELECT id FROM users WHERE id=? AND company_id=? AND role='client'",[$ticket['created_by'],$company]);
        if(!$recipient)return;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $payload=['audience'=>'client','recipientId'=>(int)$recipient['id'],'targetId'=>(int)$ticket['id'],'operation'=>$operation];
        $result=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",[$company,'portal','support:'.$recipient['id'].':'.$audit,'resolved',json_encode($payload),gmdate('c')]);
        if($result->rowCount()===1)PushRoutes::devices($this->db)->enqueue($this->db->insertId());
    }
    private function source(int $user,int $company): array
    {
        return ["n.company_id=? AND n.event_type='resolved' AND n.order_key LIKE ?",[$company,'support:'.$user.':%']];
    }
    public static function presentation(array $detail=[]): array
    {
        return ['title'=>'Omnigo','body'=>($detail['operation']??'resolved')==='reply'?'Omnigo, destek talebine cevap verdi.':'Omnigo, destek talebinizi çözüldü olarak kapattı.'];
    }
    public static function destination(array $event): string
    {
        // The existing 1.0.5 native allowlist supports this detail path; no new APK is required.
        return '/?view=support&orderCompany='.(int)$event['company_id'].'&noticeType=user&noticeId='.(int)$event['id'];
    }
    public function detail(int $user,int $company,int $id): array
    {
        [$where,$args]=$this->source($user,$company);
        $row=$this->db->one("SELECT n.payload,n.created FROM order_notifications n WHERE $where AND n.id=?",[...$args,$id]);
        if(!$row)Http::fail(404,'Destek bildirimi bulunamadı.');
        $data=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        if((int)$data['recipientId']!==$user||!$this->db->one('SELECT id FROM support_tickets WHERE id=? AND company_id=? AND created_by=?',[$data['targetId'],$company,$user]))Http::fail(404,'Destek bildirimi bulunamadı.');
        return ['targetId'=>(int)$data['targetId'],'createdAt'=>$row['created']];
    }
    public function listing(int $user,int $company,int $page,string $filter): array
    {
        [$where,$args]=$this->source($user,$company);
        $events="SELECT n.id,n.payload,n.created,COALESCE(s.is_read,0) is_read,s.dismissal_id FROM order_notifications n LEFT JOIN notification_states s ON s.user_id=? AND s.company_id=n.company_id AND s.event_type='resolved' AND s.event_id=n.id WHERE $where";
        $args=[$user,...$args];
        $filterSql=match($filter){'all'=>'','read'=>' AND is_read=1','unread'=>' AND is_read=0',default=>Http::fail(400,'Bildirim filtresi geçersiz.')};
        $summary=$this->db->one("SELECT COALESCE(MAX(id),0) boundary,COALESCE(SUM(CASE WHEN dismissal_id IS NULL THEN 1 ELSE 0 END),0) total,COALESCE(SUM(CASE WHEN dismissal_id IS NULL AND is_read=0 THEN 1 ELSE 0 END),0) unread FROM ($events) e",$args);
        $rows=$this->db->all("SELECT * FROM ($events) e WHERE dismissal_id IS NULL$filterSql ORDER BY id DESC LIMIT 31 OFFSET ".($page*30),$args);
        $undo=$this->db->one("SELECT dismissal_id FROM notification_states WHERE user_id=? AND company_id=? AND event_type='resolved' AND dismissal_id IS NOT NULL ORDER BY dismissed_at DESC LIMIT 1",[$user,$company]);
        return ['items'=>array_map(fn($row)=>['type'=>'resolved','id'=>(int)$row['id'],'targetId'=>(int)json_decode($row['payload'],true)['targetId'],'createdAt'=>$row['created'],'read'=>(bool)$row['is_read']],array_slice($rows,0,30)),
            'hasMore'=>count($rows)>30,'counts'=>['all'=>(int)$summary['total'],'unread'=>(int)$summary['unread'],'read'=>(int)$summary['total']-(int)$summary['unread']],
            'totalUnread'=>(int)$summary['unread'],'through'=>['resolved'=>(int)$summary['boundary']],'undoId'=>$undo['dismissal_id']??null];
    }
    private function write(callable $action): mixed
    {
        for($attempt=0;;$attempt++)try{return $this->db->transaction($action);}catch(\PDOException $e){if($attempt>=2||!in_array((int)($e->errorInfo[1]??0),[5,1205,1213],true))throw $e;usleep(50000*($attempt+1));}
    }
    public function mark(int $user,int $company,int $boundary,bool $dismiss=false,bool $single=false): ?string
    {
        if($boundary<0||$boundary>2147483647)Http::fail(400,'Bildirim listesini yenileyin.');
        return $this->write(function()use($user,$company,$boundary,$dismiss,$single){
            if($single)$this->detail($user,$company,$boundary);
            [$where,$args]=$this->source($user,$company);$batch=$dismiss?bin2hex(random_bytes(24)):null;
            $stamp=$dismiss?(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'):null;
            $update=!$dismiss?'is_read=1':($this->db->mysql()?'dismissed_at=IF(dismissal_id IS NULL,VALUES(dismissed_at),dismissed_at),dismissal_id=COALESCE(dismissal_id,VALUES(dismissal_id))':'dismissed_at=CASE WHEN dismissal_id IS NULL THEN excluded.dismissed_at ELSE dismissed_at END,dismissal_id=COALESCE(dismissal_id,excluded.dismissal_id)');
            $conflict=$this->db->mysql()?' ON DUPLICATE KEY UPDATE ':' ON CONFLICT(user_id,company_id,event_type,event_id) DO UPDATE SET ';
            $operator=$single?'=':'<=';
            $this->db->run("INSERT INTO notification_states(user_id,company_id,event_type,event_id,is_read,dismissal_id,dismissed_at) SELECT ?,?,'resolved',n.id,?,?,? FROM order_notifications n WHERE $where AND n.id $operator ?".$conflict.$update,[$user,$company,$dismiss?0:1,$batch,$stamp,...$args,$boundary]);
            return $batch;
        });
    }
    public function undo(int $user,int $company,string $batch): void
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$batch))Http::fail(400,'Geri alma bilgisi geçersiz.');
        $this->write(fn()=>$this->db->run("UPDATE notification_states SET dismissal_id=NULL,dismissed_at=NULL WHERE user_id=? AND company_id=? AND event_type='resolved' AND dismissal_id=?",[$user,$company,$batch]));
    }
}
