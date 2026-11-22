<?php
declare(strict_types=1);
namespace Omnigo;

/** Per-device push jobs are committed together with the message. */
final class ChatNotifications
{
    public function __construct(private Database $db){}

    public function enqueue(array $thread,int $sender,int $message,int $now):void
    {
        $devices=$this->db->all("SELECT d.id,d.generation FROM notification_devices d
            JOIN chat_members m ON m.user_id=d.user_id JOIN users u ON u.id=d.user_id ".ChatAccess::ACCOUNT_STATE."
            WHERE m.thread_id=? AND m.user_id<>? AND d.kind IN ('ios','android','web') AND d.enabled=1 AND d.expires>?
              AND ".ChatAccess::ACTIVE." AND (u.role='admin' OR (u.role='client' AND u.company_id=?))",
            [$thread['id'],$sender,$now,$thread['company_id']]);
        foreach($devices as $device){
            $job='chat-'.substr(hash('sha256',$device['id'].':'.$message),0,59);
            $this->db->run('INSERT INTO push_deliveries(id,device_id,event_id,generation,next_run,created) VALUES(?,?,NULL,?,?,?)',
                [$job,$device['id'],$device['generation'],$now,$now]);
            $this->db->run('INSERT INTO chat_push_refs(job_id,message_id) VALUES(?,?)',[$job,$message]);
        }
    }

    public static function message(int $thread,string $tag,string $role):array
    {
        return ['title'=>$role==='admin'?'OG Yönetici Portalı':'OG İş Ortağım',
            'body'=>'Yeni sohbet mesajınız var.','category'=>'chat','tag'=>hash('sha256',$tag),'sound'=>true,'url'=>'/?chatThread='.$thread];
    }

    public function delivery(array $device,string $job):?array
    {
        $row=$this->db->one("SELECT t.id,t.company_id,u.id recipient,u.role,u.company_id recipient_company FROM chat_push_refs r
            JOIN chat_messages b ON b.id=r.message_id JOIN chat_threads t ON t.id=b.thread_id
            JOIN chat_members m ON m.thread_id=t.id JOIN users u ON u.id=m.user_id ".ChatAccess::ACCOUNT_STATE."
            WHERE r.job_id=? AND m.user_id=? AND b.sender_id<>m.user_id AND b.deleted_at IS NULL AND ".ChatAccess::ACTIVE,
            [$job,$device['user_id']]);
        if(!$row||!ChatAccess::permits(['role'=>$row['role'],'company_id'=>$row['recipient_company']],$row))return null;
        return self::message((int)$row['id'],$job,$row['role']);
    }

    public function desktop(array $user,?int $after):array
    {
        $latest=(int)($this->db->one('SELECT MAX(id) id FROM chat_messages')['id']??0);
        if($after===null)$after=(int)($this->db->one('SELECT MAX(id) id FROM chat_messages WHERE created<?',[time()-120])['id']??0);
        $args=[$user['id'],$after,$latest];
        $scope='';
        if($user['role']==='client'){$scope=' AND t.company_id=?';$args[]=$user['company_id'];}
        $rows=$this->db->all("SELECT b.id,b.thread_id FROM chat_messages b JOIN chat_threads t ON t.id=b.thread_id
            JOIN chat_members m ON m.thread_id=t.id WHERE m.user_id=? AND b.sender_id<>m.user_id
              AND b.id>? AND b.id<=? AND b.id>m.read_id AND b.deleted_at IS NULL$scope ORDER BY b.id LIMIT 101",$args);
        $more=count($rows)>100;
        $rows=array_slice($rows,0,100);
        return ['items'=>array_map(static fn(array $row)=>['id'=>(int)$row['id'],...self::message((int)$row['thread_id'],'chat-'.$row['id'],$user['role'])],$rows),
            'cursor'=>$more?(int)end($rows)['id']:max($latest,$after)];
    }
}
