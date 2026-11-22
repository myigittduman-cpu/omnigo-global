<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable mail projection of new client activity; old inbox history is never replayed. */
final class UserNotificationMail
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function enqueue(MailQueue $mail): int
    {
        $count=0;
        $admin=$this->db->one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");if(!$admin)return 0;
        foreach($this->db->all('SELECT id,name FROM companies ORDER BY id') as $company){
            $id=(int)$company['id'];
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$id]);
            $key='user-mail-cursor-'.$id;
            $record=$this->db->one("SELECT id,next_run FROM mail_outbox WHERE id=? AND state='checkpoint'",[$key]);
            if(!$record){
                $cursor=(int)($this->db->one('SELECT MAX(id) AS last_id FROM order_notifications WHERE company_id=?',[$id])['last_id']??0);
                $this->db->run("INSERT INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,NULL,'checkpoint',?,?)",[$key,$admin['id'],$cursor,gmdate('c')]);continue;
            }
            $cursor=(int)$record['next_run'];
            $rows=$this->db->all("SELECT * FROM order_notifications WHERE company_id=? AND id>? AND event_type IN ('shipment','support','user') ORDER BY id LIMIT 100",[$id,$cursor]);
            foreach($rows as $row){
                $data=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);$cursor=(int)$row['id'];
                if(($data['audience']??'admin')!=='admin')continue;
                if($row['event_type']==='shipment'){
                    // Document uploads already have one durable operation-mail event.
                    if(($data['operation']??'')==='document')continue;
                    $detail=(new ShipmentChangeNotifications($this->db,$this->cipher))->detail($id,(int)$data['auditId']);
                    $action='Şehir Dışı Sevkiyat kaydını '.match($detail['operation']??''){'create'=>'oluşturdu','delete'=>'kaldırdı','restore'=>'geri aldı','status'=>'durumunu değiştirdi',default=>'güncelledi'};
                }else{$detail=$data;$action=UserChangeNotifications::presentation($data)['title'];}
                if($mail->enqueueUserActivity('user-notification-'.$cursor,['events'=>['user_change'],'company'=>$company['name'],'companyId'=>$id,'provider'=>$row['provider'],'detectedAt'=>$row['created'],'actorName'=>$detail['actorName']??'Müşteri kullanıcısı','action'=>$action,'rowId'=>$detail['rowId']??null,'targetId'=>$detail['targetId']??null]))$count++;
            }
            if($rows)$this->db->run("UPDATE mail_outbox SET next_run=? WHERE id=? AND state='checkpoint'",[$cursor,$record['id']]);
        }
        return $count;
    }
}
