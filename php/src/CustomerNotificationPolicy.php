<?php
declare(strict_types=1);
namespace Omnigo;

/** Presentation/delivery policy only: never suppresses creation of source events or sync jobs. */
final class CustomerNotificationPolicy
{
    public const DETAIL_DEFAULTS=[
        'stockDepleted'=>true,'stockRestocked'=>true,'stockAdjusted'=>true,'stockPurchasePrice'=>true,
        'orderCancellationRequests'=>true,'orderCancelled'=>true,
    ];
    private const EVENTS=[
        'stock'=>['stockDepleted'=>'inventory_low','stockRestocked'=>'inventory_restocked','stockAdjusted'=>'inventory_adjusted','stockPurchasePrice'=>'purchase_price_updated'],
        'cancellation'=>['orderCancellationRequests'=>'cancellation_requested','orderCancelled'=>'cancelled'],
    ];
    public const DEFAULTS=['stock'=>true,'newOrder'=>true,'cancellation'=>true,'returns'=>true,'documents'=>true,'payments'=>true,'transfer'=>true,'shipment'=>true,'support'=>true,'connections'=>false,'users'=>true,...CustomerOrderNotifications::DEFAULTS,...self::DETAIL_DEFAULTS];
    private array $access;
    private array $settings;
    public function __construct(private Database $db,private array $user,private int $company)
    {
        $permissions=new CustomerPermissions($db,$company);
        $this->access=$permissions->access($user);
        $this->settings=$this->access['notifications'];
    }
    public static function validate(mixed $value): array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        if(!is_array($value)||array_diff(array_keys($value),array_keys(self::DEFAULTS)))Http::fail(400,'Bildirim ayarlarını kontrol edin.');
        foreach(['stock','returns','documents','payments',...array_keys(CustomerOrderNotifications::DEFAULTS),...array_keys(self::DETAIL_DEFAULTS)] as $key)if(!array_key_exists($key,$value))$value[$key]=self::DEFAULTS[$key];
        foreach(self::DEFAULTS as $key=>$default)if(!is_bool($value[$key]??null))Http::fail(400,'Her bildirim için seçim yapın.');
        return $value;
    }
    private function page(string $page): bool{return ($this->access['pages'][$page]??'hidden')==='shown'&&!empty($this->access['grants'][$page]['view']);}
    public function options(): array
    {
        $admin=($this->user['role']??'')==='admin';
        $options=['stock'=>$admin&&$this->page('inventory'),'newOrder'=>$admin,'cancellation'=>$admin&&$this->page('orders'),'returns'=>$admin&&$this->page('returns'),'documents'=>$admin&&$this->page('orders'),'payments'=>$this->page('payments'),'transfer'=>$admin&&($this->page('shipments')||$this->page('deliveries')),'shipment'=>$admin&&$this->page('shipments'),'support'=>$this->page('support'),'connections'=>false,'users'=>$admin&&$this->page('support')];
        foreach(CustomerOrderNotifications::options() as $key=>$page)$options[$key]=!$admin&&$this->page($page);
        foreach(self::EVENTS as $group=>$events)foreach($events as $key=>$event)$options[$key]=$options[$group];
        return $options;
    }
    public function enabled(string $key): bool{return $key!=='connections'&&!empty($this->settings[$key])&&!empty($this->options()[$key]);}

    private function enabledEvents(string $group):array
    {
        return array_intersect_key(self::EVENTS[$group],
            array_filter($this->settings,static fn(mixed $enabled):bool=>$enabled===true));
    }
    public static function eventGroup(string $key):string
    {
        foreach(self::EVENTS as $group=>$events)if(isset($events[$key]))return $group;
        return $key;
    }
    private function detailCondition(string $group,string $payload,string $id,array $floors):string
    {
        $conditions=[];
        foreach($this->enabledEvents($group) as $key=>$event){
            $name=str_replace('_','!_',$event);
            $floor=max((int)($floors[$key]['order']??-1),(int)($floors[$group]['order']??-1));
            $conditions[]="($id>$floor AND $payload LIKE '%\"$name\"%' ESCAPE '!')";
        }
        return $conditions?'('.implode(' OR ',$conditions).')':'1=0';
    }
    public function allowsActivity(string $group,array $events,?int $id=null,string $source='order'):bool
    {
        if(!$this->enabled($group))return false;
        $floors=$id===null?[]:NotificationResumePolicy::forUser($this->db,$this->company,(int)$this->user['id']);
        if($id!==null&&$id<=(int)($floors[$group][$source]??-1))return false;
        if(!isset(self::EVENTS[$group]))return true;
        foreach($this->enabledEvents($group) as $key=>$event){
            if(in_array($event,$events,true)&&($id===null||$id>(int)($floors[$key][$source]??-1)))return true;
        }
        return false;
    }
    public function predicate(string $type,string $payload,string $id='id'): string
    {
        foreach([$type,$payload,$id] as $column)if(!preg_match('/^[a-z_]+(?:\.[a-z_]+)?$/D',$column))throw new \LogicException('Geçersiz bildirim alanı.');
        $admin=($this->user['role']??'')==='admin';
        $clientAudience="COALESCE($payload,'{}') LIKE '%\"audience\":\"client\"%'";
        $imports="($type='operation' AND ($payload LIKE '%\"marketplace_import_started\"%' OR $payload LIKE '%\"marketplace_import_completed\"%' OR $payload LIKE '%\"marketplace_import_failed\"%'))";
        $eventName=str_replace('_','!_',UpcomingPayoutNotice::EVENT);
        $payoutReview="($type='operation' AND $payload LIKE '%\"$eventName\"%' ESCAPE '!')";
        $internal="($imports OR $payoutReview)";
        $floors=NotificationResumePolicy::forUser($this->db,$this->company,(int)$this->user['id']);
        $parts=[];if($admin)$parts[]="($internal AND $payload LIKE '%\"audience\":\"admin\"%')";
        $setup=NotificationCategory::setupCondition($type,$payload);
        $setupAudience=$admin?'admin':'client';
        $parts[]="($setup AND $payload LIKE '%\"audience\":\"$setupAudience\"%')";
        $cancel="($payload LIKE '%\"cancelled\"%' OR $payload LIKE '%\"cancellation_requested\"%')";
        $special=['stock'=>"($payload LIKE '%\"inventory_low\"%' OR $payload LIKE '%\"inventory_restocked\"%' OR $payload LIKE '%\"inventory_adjusted\"%' OR $payload LIKE '%\"purchase_price_updated\"%')",'returns'=>"$payload LIKE '%\"return_updated\"%'",'documents'=>"(($payload LIKE '%\"document_uploaded\"%' OR $payload LIKE '%\"document_deleted\"%') AND $payload LIKE '%\"orders\"%')",'shipment'=>"($payload LIKE '%\"dispatched\"%' OR (($payload LIKE '%\"document_uploaded\"%' OR $payload LIKE '%\"document_deleted\"%') AND $payload NOT LIKE '%\"orders\"%'))",'payments'=>"$payload LIKE '%\"payment_updated\"%'"];
        foreach(['newOrder'=>'order','shipment'=>'shipment','connections'=>'integration'] as $key=>$event)if($this->enabled($key))$parts[]="$type='$event'";
        $supportChange="($payload LIKE '%\"operation\":\"created\"%' OR $payload LIKE '%\"operation\":\"closed\"%')";
        if($admin&&$this->enabled('support'))$parts[]="($type='user' AND $supportChange)";
        if($this->enabled('users'))$parts[]="($type='user' AND NOT $supportChange)";
        if($this->enabled('support'))$parts[]="$type='".(($this->user['role']??'')==='client'?'resolved':'support')."'";
        if($this->enabled('cancellation'))$parts[]="($type='operation' AND ".$this->detailCondition('cancellation',$payload,$id,$floors).')';
        foreach($special as $key=>$condition)if($this->enabled($key)){
            if(isset(self::EVENTS[$key]))$condition=$this->detailCondition($key,$payload,$id,$floors);
            $parts[]="($type='operation' AND $condition)";
        }
        if($this->enabled('transfer'))$parts[]="($type='operation' AND NOT $internal AND NOT $setup AND NOT $cancel AND NOT (".implode(' OR ',$special)."))";
        if(!$admin){
            foreach(CustomerOrderNotifications::options() as $key=>$page)if($this->enabled($key)){
                $floor=(int)($floors[$key]['order']??0);
                $parts[]="($type='operation' AND $id>$floor AND ".CustomerOrderNotifications::condition($payload,$key).')';
            }
        }
        $audience=$admin?"($type<>'operation' OR NOT ($clientAudience)) AND (NOT $internal OR $payload LIKE '%\"audience\":\"admin\"%')":"NOT $internal AND ($type<>'operation' OR COALESCE($payload,'{}') NOT LIKE '%\"audience\":\"admin\"%')";
        return $parts?'(('.implode(' OR ',$parts).") AND ($audience) AND COALESCE($payload,'{}') NOT LIKE '%\"duplicateOf\"%')":'1=0';
    }
    public function permits(array $event): bool
    {
        if((int)($event['company_id']??0)!==$this->company)return false;
        $detail=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        if(isset($detail['duplicateOf']))return false;
        if(StoreSetupRun::isNotice($detail))return ($event['event_type']??'')==='operation'&&($detail['audience']??'')===($this->user['role']??'');
        if(IntegrationImportPresentation::isEvent($detail)||UpcomingPayoutNotice::isEvent($detail))return ($this->user['role']??'')==='admin'&&($event['event_type']??'')==='operation'&&($detail['audience']??'')==='admin';
        $clientEvent=CustomerOrderNotifications::event($detail);
        if(($event['event_type']??'')==='operation'&&($detail['audience']??'')==='client'&&($this->user['role']??'')!=='client')return false;
        if(($event['event_type']??'')==='operation'&&($detail['audience']??'')==='admin'&&($this->user['role']??'')!=='admin')return false;
        if($clientEvent!==null&&!MarketplaceVisibility::visible((string)($event['provider']??'')))return false;
        $key=match($event['event_type']??''){
            'order'=>'newOrder','operation'=>$clientEvent!==null?CustomerOrderNotifications::setting($clientEvent):(in_array('payment_updated',$detail['events']??[],true)?'payments':ActivityNotification::key($detail)),
            'shipment'=>'shipment','support','resolved'=>'support','integration'=>'connections','user'=>in_array($detail['operation']??'',['created','closed'],true)?'support':'users',default=>''};
        if(!$this->allowsActivity($key,$detail['events']??[],isset($event['id'])?(int)$event['id']:null))return false;
        if(($event['event_type']??'')==='resolved')return (int)($detail['recipientId']??0)===(int)$this->user['id'];
        return true;
    }
    public static function recipient(Database $db,array $user,?array $event): bool
    {
        if(!$event)return true;
        if(($user['role']??'')!=='admin'&&(int)($user['company_id']??0)!==(int)$event['company_id'])return false;
        return (new self($db,$user,(int)$event['company_id']))->permits($event);
    }
}
