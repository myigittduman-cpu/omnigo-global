<?php
declare(strict_types=1);
namespace Omnigo;

/** Envelope snapshots the operator name and action; private messages remain in support. */
final class UserChangeNotifications
{
    private const ACTIONS=['Destek talebi açıldı'=>'created','Destek mesajı eklendi'=>'reply','Destek talebi kapatıldı'=>'closed','Destek talebi yeniden açıldı'=>'reopened'];
    public function __construct(private Database $db){}
    public function record(array $user,int $company,int $ticket,int $audit,string $action): void
    {
        $role=$user['role']??'';
        $operation=self::ACTIONS[$action]??null;
        if(!$operation||!in_array($role,['admin','client'],true))return;
        if($role==='admin'&&!in_array($operation,['created','closed'],true))return;
        if($role==='client'&&(int)($user['company_id']??0)!==$company)Http::fail(403,'Bildirim firmasına erişemezsiniz.');
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Bildirim değişiklikle aynı işlemde kaydedilmeli.');
        $type=$operation==='created'&&$role==='client'?'support':'user';
        $prefix=$role==='client'?'client-support:':'admin-support:';
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $result=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",[$company,'portal',hash('sha256',$prefix.$audit),$type,json_encode(['audience'=>'admin','actorId'=>(int)$user['id'],'actorName'=>ClientSessionOperator::actorName($user),'targetId'=>$ticket,'auditId'=>$audit,'operation'=>$operation]),gmdate('c')]);
        if($result->rowCount()===1)PushRoutes::devices($this->db)->enqueue($this->db->insertId());
    }
    public function supportActor(int $company,int $ticket): array
    {
        $audit=$this->db->one("SELECT id FROM audit WHERE company_id=? AND record_id=? AND action='Destek talebi açıldı' ORDER BY id LIMIT 1",[$company,$ticket]);
        $row=$audit?$this->db->one("SELECT payload FROM order_notifications WHERE company_id=? AND order_key=? AND event_type='support'",[$company,hash('sha256','client-support:'.$audit['id'])]):null;
        $value=$row?json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR):[];
        return isset($value['actorName'])?['actorName'=>$value['actorName'],'operation'=>'created']:[];
    }
    public static function presentation(array $detail): array
    {
        $action=match($detail['operation']??''){'created'=>'Destek talebi oluşturdu','reply'=>'Destek mesajı gönderdi','closed'=>'Destek talebini kapattı','reopened'=>'Destek talebini yeniden açtı',default=>'Destek talebini güncelledi'};
        return ['title'=>ActivityNotification::clean($detail['actorName']??'Müşteri kullanıcısı').', '.mb_strtolower(mb_substr($action,0,1)).mb_substr($action,1).'.','body'=>'','view'=>'support'];
    }
    public function detail(int $company,int $id): array
    {
        $row=$this->db->one("SELECT payload,created FROM order_notifications WHERE company_id=? AND id=? AND event_type='user'",[$company,$id]);
        if(!$row)Http::fail(404,'Kullanıcı bildirimi bulunamadı.');
        $detail=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
        if(!$this->db->one('SELECT id FROM support_tickets WHERE company_id=? AND id=?',[$company,$detail['targetId']]))Http::fail(404,'Destek talebi bulunamadı.');
        $actor=$this->db->one('SELECT name FROM users WHERE id=?',[$detail['actorId']]);
        return ['targetId'=>(int)$detail['targetId'],'actorName'=>$detail['actorName']??$actor['name']??'Müşteri kullanıcısı','operation'=>$detail['operation'],'createdAt'=>$row['created'],...self::presentation($detail)];
    }
}
