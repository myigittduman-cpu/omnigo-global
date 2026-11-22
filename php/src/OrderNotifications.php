<?php
declare(strict_types=1);
namespace Omnigo;

/** Called inside the existing marketplace transaction and company lock. */
final class OrderNotifications
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null) {}

    public function observe(int $company,array $order): bool
    {
        $provider=array_search($order['provider']??'',OrderList::PROVIDERS,true);
        if($provider===false||!MarketplaceVisibility::visible($provider))return false;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->db->run("$insert INTO order_notification_sources(company_id,provider,started_at) VALUES(?,?,?)",[$company,$provider,time()]);
        $source=$this->db->one('SELECT started_at FROM order_notification_sources WHERE company_id=? AND provider=?',[$company,$provider]);
        try{$created=new \DateTimeImmutable($order['createdAt']?:'invalid',new \DateTimeZone('Europe/Istanbul'));}catch(\Exception){return false;}
        // Backfills and reconnects never generate a storm of historical alerts.
        if($created->getTimestamp()<(int)$source['started_at']||$created->getTimestamp()>time()+300)return false;
        $number=trim($order['orderNumber']??'');if($number==='')return false;
        // One notification per order even when n11 sends several shipment packages.
        $added=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,payload,created) VALUES(?,?,?,?,?)",[$company,$provider,hash('sha256',$number),json_encode(OrderNotificationOrder::reference($order),JSON_THROW_ON_ERROR),gmdate('c')]);
        if($added->rowCount()===1)PushRoutes::devices($this->db)->enqueue($this->db->insertId());
        return $added->rowCount()===1;
    }

    public function arrivals(?int $after,bool $includeIntegration=false,bool $includeRecovery=false,bool $includeOperations=false,?array $user=null): array
    {
        $latest=(int)($this->db->one('SELECT MAX(id) AS id FROM order_notifications')['id']??0);
        if($after===null)return ['items'=>[],'cursor'=>$latest,'hasMore'=>false];
        $rows=$this->db->all("SELECT n.id,n.company_id,n.provider,n.order_key,n.event_type,n.payload,c.name AS company FROM order_notifications n JOIN companies c ON c.id=n.company_id WHERE n.id>? AND n.event_type<>'checkpoint' ORDER BY n.id LIMIT 101",[$after]);
        $items=array_map(fn($r)=>['id'=>(int)$r['id'],'companyId'=>(int)$r['company_id'],'company'=>$r['company'],'provider'=>$r['provider'],'type'=>$r['event_type'],...($r['event_type']==='integration'?['status'=>json_decode($r['payload'],true)['status']??'error']:[])],array_slice($rows,0,100));
        $cursor=$items?end($items)['id']:max($after,$latest);
        $items=array_values(array_filter($items,fn($item)=>!in_array($item['type'],['resolved','integration'],true)));
        $sourceRows=array_column($rows,null,'id');$items=array_values(array_filter($items,fn($item)=>(json_decode($sourceRows[$item['id']]['payload']??'{}',true)['audience']??'')!=='client'));
        if($includeOperations)foreach($items as &$item){if(in_array($item['type'],['operation','shipment','support','user'],true)){
            $row=array_values(array_filter($rows,fn($r)=>(int)$r['id']===$item['id']))[0];$detail=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            $userEvent=in_array($item['type'],['support','user'],true);
            $item=[...$item,...$detail,...($userEvent?UserChangeNotifications::presentation($detail):OperationalNotifications::presentation($item['type'],$detail)),'noticeId'=>$item['type']==='shipment'?(int)$detail['auditId']:($item['type']==='support'?(int)$detail['targetId']:$item['id'])];
        }}unset($item);
        if(!$includeOperations)$items=array_values(array_filter($items,fn($item)=>in_array($item['type'],['order','integration'],true)));
        if(!$includeIntegration)$items=array_values(array_filter($items,fn($item)=>$item['type']==='order'));
        if(!$includeRecovery)$items=array_values(array_filter($items,fn($item)=>($item['status']??'')!=='recovered'));
        if($user){$source=array_column($rows,null,'id');$items=array_values(array_filter($items,fn($item)=>CustomerNotificationPolicy::recipient($this->db,$user,$source[$item['id']])));}
        $byId=array_column($rows,null,'id');$orders=new OrderNotificationOrder($this->db,$this->cipher);
        foreach($items as &$item){
            $event=$orders->enrich($byId[$item['id']]);
            $item['presentation']=MobileNotificationPresentation::create($event,'admin');
            $item['url']=MobilePushMessage::create([],$event,'','admin')['url'];
            if($item['type']==='order'){$item['amountCents']=$event['amountCents']??null;$item['noticeId']=$item['id'];}
        }unset($item);
        return ['items'=>array_map(fn($item)=>[...$item,'category'=>NotificationCategory::forEvent($item['type'],$item)],MarketplaceVisibility::rows($items,'provider')),'cursor'=>$cursor,'hasMore'=>count($rows)>100];
    }
}
