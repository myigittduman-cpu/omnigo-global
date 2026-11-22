<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer desktop tokens only read permitted service updates and account-specific support. */
final class DesktopClientNotifications
{
    public function __construct(private Database $db){}
    public function arrivals(array $user,?int $after):array
    {
        if(($user['role']??'')!=='client'||empty($user['company_id']))Http::fail(403,'Bildirim erişiminiz yok.');
        $latest=(int)($this->db->one('SELECT MAX(id) AS id FROM order_notifications')['id']??0);
        if($after===null)return ['items'=>[],'cursor'=>$latest,'hasMore'=>false];
        if($after<0)Http::fail(400,'Bildirim konumu geçersiz.');
        $rows=$this->db->all("SELECT n.*,c.name AS company FROM order_notifications n JOIN companies c ON c.id=n.company_id WHERE n.company_id=? AND ((n.event_type='operation' AND n.payload LIKE ?) OR (n.event_type='resolved' AND n.order_key LIKE ?)) AND n.id>? AND n.id<=? ORDER BY n.id LIMIT 101",[(int)$user['company_id'],'%"audience":"client"%','support:'.(int)$user['id'].':%',$after,$latest]);
        $more=count($rows)>100;$rows=array_slice($rows,0,100);$items=[];
        $policy=new CustomerNotificationPolicy($this->db,$user,(int)$user['company_id']);
        foreach($rows as $row){
            try{if(!NotificationAudience::permits($user,$row)||!$policy->permits($row))continue;}catch(\JsonException){continue;}
            $items[]=['id'=>(int)$row['id'],'companyId'=>(int)$row['company_id'],'company'=>$row['company'],'provider'=>$row['provider'],'type'=>$row['event_type'],'noticeId'=>(int)$row['id'],...json_decode($row['payload'],true), 'presentation'=>MobileNotificationPresentation::create($row,'client'),'url'=>MobilePushMessage::create([],$row,'','client')['url'],...($row['event_type']==='operation'?OperationalNotifications::presentation('operation',json_decode($row['payload'],true)):[]),'createdAt'=>$row['created']];
        }
        return ['items'=>$items,'cursor'=>$more?(int)end($rows)['id']:max($after,$latest),'hasMore'=>$more];
    }
}
