<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationState
{
    public function __construct(private Database $db) {}

    private function source(string $type,int $user,int $company,string $category='all'): string
    {
        $sql=match($type){
            'marketplace'=>"SELECT m.id FROM inbox_messages m JOIN inbox_threads t ON t.id=m.thread_id WHERE t.company_id=? AND m.inbound=1 AND "
                .InboxStatus::waiting().' AND '.InboxQuestionWindow::activeMessageSql($this->db),
            'support'=>"SELECT s.id FROM support_tickets s WHERE s.company_id=?",
            'shipment'=>"SELECT a.id FROM audit a LEFT JOIN shipment_entries se ON se.id=a.record_id AND se.company_id=a.company_id WHERE a.company_id=? AND a.action='shipment.client_change'",
            'order'=>"SELECT o.id FROM order_notifications o WHERE o.company_id=? AND o.event_type='order'",
            'operation'=>"SELECT o.id FROM order_notifications o WHERE o.company_id=? AND o.event_type='operation'",
            'integration'=>"SELECT o.id FROM order_notifications o WHERE o.company_id=? AND o.event_type='integration'",
            'resolved'=>"SELECT o.id FROM order_notifications o WHERE o.company_id=? AND o.event_type='resolved' AND o.order_key LIKE 'support:$user:%'",
            'user'=>"SELECT o.id FROM order_notifications o WHERE o.company_id=? AND o.event_type='user'",
            default=>throw new \LogicException('Bilinmeyen bildirim türü.')
        };
        $actor=$this->db->one('SELECT id,role,company_id FROM users WHERE id=?',[$user]);
        if(!$actor)Http::fail(403,'Bildirim erişiminiz yok.');
        $policy=new CustomerNotificationPolicy($this->db,$actor,$company);
        if($type==='operation')$sql.=' AND '.NotificationCategory::predicate('o.event_type',$category,'o.payload');
        if(in_array($type,['order','operation','integration','user','resolved'],true))$sql.=' AND '.$policy->predicate('o.event_type','o.payload','o.id');
        elseif(!$policy->enabled(['support'=>'support','shipment'=>'shipment','marketplace'=>'connections'][$type]))$sql.=' AND 1=0';
        return $sql.($type==='support'?'':' AND '.MarketplaceVisibility::sql(match($type){'marketplace'=>'t.provider','shipment'=>'se.provider',default=>'o.provider'}));
    }
    private function write(callable $action): mixed
    {
        if($this->db->pdo->inTransaction())return $action();
        for($attempt=0;;$attempt++){
            try{return $this->db->transaction($action);}
            catch(\PDOException $error){
                if($attempt>=2||!in_array((int)($error->errorInfo[1]??0),[5,1205,1213],true))throw $error;
                usleep(50000*($attempt+1));
            }
        }
    }
    private function record(int $user,int $company,string $type,string $condition,int $boundary,?string $dismissal=null,string $category='all'): void
    {
        $source=$this->source($type,$user,$company,$category);
        $update=$dismissal===null?'is_read=1':($this->db->mysql()?
            'dismissed_at=IF(dismissal_id IS NULL,VALUES(dismissed_at),dismissed_at),dismissal_id=COALESCE(dismissal_id,VALUES(dismissal_id))':
            'dismissed_at=CASE WHEN dismissal_id IS NULL THEN excluded.dismissed_at ELSE dismissed_at END,dismissal_id=COALESCE(dismissal_id,excluded.dismissal_id)');
        $conflict=$this->db->mysql()?' ON DUPLICATE KEY UPDATE ':' ON CONFLICT(user_id,company_id,event_type,event_id) DO UPDATE SET ';
        $stamp=$dismissal===null?null:(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $this->db->run("INSERT INTO notification_states(user_id,company_id,event_type,event_id,is_read,dismissal_id,dismissed_at)
            SELECT ?,?,?,e.id,?,?,? FROM ($source) AS e WHERE e.id $condition ?".$conflict.$update,
            [$user,$company,$type,$dismissal===null?1:0,$dismissal,$stamp,$company,$boundary]);
        if($type==='marketplace'&&$dismissal===null){
            $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $this->db->run("$insert INTO inbox_reads(user_id,message_id) SELECT ?,e.id FROM ($source) AS e WHERE e.id $condition ?",[$user,$company,$boundary]);
        }
    }
    public function read(int $user,int $company,string $type,int $id): void
    {
        $this->write(function()use($user,$company,$type,$id){
            $source=$this->source($type,$user,$company);
            if(!$this->db->one("SELECT id FROM ($source) AS e WHERE id=?",[$company,$id]))Http::fail(404,'Bildirim bulunamadı.');
            $this->record($user,$company,$type,'=',$id);
        });
    }
    public function bulk(int $user,int $company,array $through,bool $dismiss,string $category='all',?string $batchId=null): ?string
    {
        NotificationCategory::validate($category);$through['user']??=0;$through['resolved']??=0;
        $through['operation']??=0;$through['integration']??=0;$through['order']??=0;$through['shipment']??=0; // Older open browsers must leave unseen event types untouched.
        foreach(['marketplace','support','order','shipment','integration','operation','user','resolved'] as $type){
            if(!isset($through[$type])||!is_int($through[$type])||$through[$type]<0||$through[$type]>2147483647)Http::fail(400,'Bildirim listesi değişti. Listeyi yenileyin.');
        }
        $batch=$dismiss?($batchId??bin2hex(random_bytes(24))):null;
        return $this->write(function()use($user,$company,$through,$batch,$category){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            foreach(['marketplace','support','order','shipment','integration','operation','user','resolved'] as $type)if(NotificationCategory::permits($category,$type))$this->record($user,$company,$type,'<=',$through[$type],$batch,$category);
            return $batch;
        });
    }
    public function undo(int $user,int $company,string $batch,string $category='all'): void
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$batch))Http::fail(400,'Geri alma bilgisi geçersiz.');
        $scope=NotificationCategory::statePredicate($category);
        $this->write(fn()=>$this->db->run('UPDATE notification_states SET dismissal_id=NULL,dismissed_at=NULL WHERE user_id=? AND company_id=? AND dismissal_id=? AND '.$scope,[$user,$company,$batch]));
    }
}
