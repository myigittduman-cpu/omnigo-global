<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationFeed
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null) {}

    public function listing(int $user,int $company,int $page,string $filter='all',string $category='all',bool $present=true): array
    {
        $actor=$this->db->one('SELECT id,role,company_id FROM users WHERE id=?',[$user]);
        if(!$actor||($actor['role']!=='admin'&&(int)$actor['company_id']!==$company))Http::fail(403,'Bildirim erişiminiz yok.');
        $policy=new CustomerNotificationPolicy($this->db,$actor,$company);$client=$actor['role']==='client';
        $categoryWhere=NotificationCategory::predicate('kind',$category,'payload');
        $offset=$page*30;
        $visibleOrders=MarketplaceVisibility::sql('o.provider');
        $visibleShipments=MarketplaceVisibility::sql('se.provider');
        $supportScope=$client?'1=0':'1=1';
        $resolvedScope=$client?"(o.event_type='resolved' AND o.order_key LIKE 'support:".(int)$user.":%')":'1=0';
        $events="SELECT 'support' AS kind,s.id,s.id AS target_id,'' AS provider,'{}' AS payload,NULL AS order_key,c.name AS company,s.created,COALESCE(n.is_read,0) AS is_read,n.dismissal_id
            FROM support_tickets s JOIN companies c ON c.id=s.company_id
            JOIN users u ON u.id=s.created_by
            LEFT JOIN notification_states n ON n.user_id=? AND n.company_id=s.company_id AND n.event_type='support' AND n.event_id=s.id
            WHERE s.company_id=? AND s.status<>'deleted' AND u.role='client' AND $supportScope
            UNION ALL
            SELECT o.event_type AS kind,o.id,o.id AS target_id,o.provider,o.payload,o.order_key,c.name AS company,o.created,COALESCE(n.is_read,0) AS is_read,n.dismissal_id
            FROM order_notifications o JOIN companies c ON c.id=o.company_id
            LEFT JOIN notification_states n ON n.user_id=? AND n.company_id=o.company_id AND n.event_type=o.event_type AND n.event_id=o.id
            WHERE o.company_id=? AND (o.event_type NOT IN ('shipment','support','resolved') OR $resolvedScope) AND $visibleOrders
            UNION ALL
            SELECT 'shipment' AS kind,a.id,a.record_id AS target_id,'' AS provider,'{}' AS payload,NULL AS order_key,c.name AS company,a.created,COALESCE(n.is_read,0) AS is_read,n.dismissal_id
            FROM audit a JOIN companies c ON c.id=a.company_id
            LEFT JOIN shipment_entries se ON se.id=a.record_id AND se.company_id=a.company_id
            LEFT JOIN notification_states n ON n.user_id=? AND n.company_id=a.company_id AND n.event_type='shipment' AND n.event_id=a.id
            WHERE a.company_id=? AND a.action='shipment.client_change' AND $visibleShipments";
        $args=[$user,$company,$user,$company,$user,$company];
        $events="SELECT * FROM ($events) AS policy_events WHERE ".$policy->predicate('kind','payload');
        $categoryCounts=array_fill_keys(array_keys(NotificationCategory::TYPES),['all'=>0,'unread'=>0,'read'=>0]);
        $categoryExpression=NotificationCategory::groupExpression('kind','payload');
        foreach($this->db->all("SELECT $categoryExpression AS category_name,COUNT(*) AS total,SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) AS unread FROM ($events) AS customer_events WHERE dismissal_id IS NULL GROUP BY category_name",$args) as $count){
            $key=$count['category_name'];$categoryCounts[$key]['all']+=(int)$count['total'];$categoryCounts[$key]['unread']+=(int)$count['unread'];$categoryCounts[$key]['read']+=(int)$count['total']-(int)$count['unread'];
        }
        $totalUnread=array_sum(array_column($categoryCounts,'unread'));
        foreach(NotificationCategory::CLIENT_MARKETPLACE_CATEGORIES as $subset){
            foreach($categoryCounts[$subset] as $key=>$count)$categoryCounts['marketplaces'][$key]+=$count;
        }
        $where=match($filter){'all'=>'','read'=>' AND is_read=1','unread'=>' AND is_read=0',default=>Http::fail(400,'Bildirim filtresi geçersiz.')};
        // The limits capture the user's current list, so bulk actions leave later arrivals untouched.
        $summary=$this->db->one("SELECT
            COALESCE(MAX(CASE WHEN kind='resolved' THEN id END),0) AS resolved,
            COALESCE(MAX(CASE WHEN kind='marketplace' THEN id END),0) AS marketplace,
            COALESCE(MAX(CASE WHEN kind='support' THEN id END),0) AS support,
            COALESCE(MAX(CASE WHEN kind='order' THEN id END),0) AS orders,
            COALESCE(MAX(CASE WHEN kind='shipment' THEN id END),0) AS shipments,
            COALESCE(MAX(CASE WHEN kind='operation' THEN id END),0) AS operations,
            COALESCE(MAX(CASE WHEN kind='integration' THEN id END),0) AS integrations,
            COALESCE(MAX(CASE WHEN kind='user' THEN id END),0) AS users,
            COALESCE(SUM(CASE WHEN dismissal_id IS NULL THEN 1 ELSE 0 END),0) AS total,
            COALESCE(SUM(CASE WHEN dismissal_id IS NULL AND is_read=0 THEN 1 ELSE 0 END),0) AS unread
            FROM ($events) AS customer_events WHERE $categoryWhere",$args);
        $rows=$this->db->all("SELECT * FROM ($events) AS customer_events WHERE dismissal_id IS NULL AND $categoryWhere$where ORDER BY created DESC,kind ASC,id DESC LIMIT 31 OFFSET $offset",$args);
        $undo=$this->db->one('SELECT dismissal_id FROM notification_states WHERE user_id=? AND company_id=? AND dismissal_id IS NOT NULL AND '.NotificationCategory::statePredicate($category).' ORDER BY dismissed_at DESC LIMIT 1',[$user,$company]);
        $items=array_slice($rows,0,30);
        $available=[];
        if($actor['role']==='admin'){
            $available[]='marketplaces';
            if($policy->enabled('stock'))$available[]='stocks';
            if($policy->enabled('users')||$policy->enabled('support')||$policy->enabled('shipment'))$available[]='users';
        }
        return ['items'=>$present?$this->present($company,$items,$actor):$items,'hasMore'=>count($rows)>30,
            'counts'=>['all'=>(int)$summary['total'],'unread'=>(int)$summary['unread'],'read'=>(int)$summary['total']-(int)$summary['unread']],
            'through'=>['resolved'=>(int)$summary['resolved'],'marketplace'=>(int)$summary['marketplace'],'support'=>(int)$summary['support'],'order'=>(int)$summary['orders'],'shipment'=>(int)$summary['shipments'],'integration'=>(int)$summary['integrations'],'operation'=>(int)$summary['operations'],'user'=>(int)$summary['users']],
            'category'=>$category,'categoryCounts'=>$categoryCounts,'totalUnread'=>$totalUnread,'availableAdminCategories'=>$available,
            'undoId'=>$undo['dismissal_id']??null];
    }

    /** Present only rows already filtered by the user's permissions and selected for the page. */
    public function present(int $company,array $rows,array $actor=[]): array
    {
        $shipments=new ShipmentChangeNotifications($this->db,$this->cipher);
        $operations=new OperationalNotifications($this->db,$this->cipher);
        $users=new UserChangeNotifications($this->db);
        $orderFields=($actor['role']??'')==='admin'
            ?(new AdminOrderBellSummary($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->collect($company,$rows):[];
        $statuses=[];$ids=array_column(array_filter($rows,fn($r)=>$r['kind']==='integration'),'id');
        if($ids)foreach($this->db->all('SELECT id,payload FROM order_notifications WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$company,...$ids]) as $event)$statuses[$event['id']]=json_decode($event['payload'],true)['status']??'error';
        return array_map(fn($row)=>[
            ...($row['kind']==='order'?($orderFields[$row['id']]??[]):[]),
            ...($row['kind']==='resolved'?['message'=>SupportResolutionNotifications::presentation(json_decode($row['payload'],true))['body']]:[]),...($row['kind']==='support'?$users->supportActor($company,(int)$row['id']):[]),'type'=>$row['kind'],'category'=>NotificationCategory::forEvent($row['kind'],json_decode((string)($row['payload']??'{}'),true)??[]),'id'=>(int)$row['id'],'targetId'=>$row['kind']==='resolved'?(int)(json_decode($row['payload'],true)['targetId']??0):(int)$row['target_id'],...($row['kind']==='user'?array_intersect_key($users->detail($company,(int)$row['id']),array_flip(['targetId','actorName','operation'])):[]),
            'provider'=>$row['provider'],'company'=>$row['company'],'read'=>(bool)$row['is_read'],'createdAt'=>$row['created'],...($row['kind']==='integration'?['status'=>$statuses[$row['id']]??'error']:[]),...($row['kind']==='operation'?$operations->summary($company,(int)$row['id']):[]),...($row['kind']==='shipment'?$shipments->summary($company,(int)$row['id']):[])
        ],$rows);
    }
}
