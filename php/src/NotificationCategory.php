<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationCategory
{
    public const TYPES=[
        'connections'=>['integration'],
        'marketplaces'=>['order','operation'],
        'stocks'=>['operation'],
        'users'=>['support','shipment','user'],
        'resolved'=>['resolved'],
        'customer-orders'=>['operation'],
        'payments'=>['operation'],
        'setup'=>['operation'],
    ];
    public const CLIENT_MARKETPLACE_CATEGORIES=['customer-orders','setup'];
    private const PAYMENT_EVENTS=['payment_updated',UpcomingPayoutNotice::EVENT];
    private const OPERATION_CATEGORIES=['stocks','payments',...self::CLIENT_MARKETPLACE_CATEGORIES];
    private const STOCK_EVENTS=['inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated'];
    public static function validate(mixed $value): string{return Validation::choice($value,['all',...array_keys(self::TYPES)]);}
    public static function forType(string $type): string
    {
        foreach(self::TYPES as $category=>$types)if(in_array($type,$types,true))return $category;
        return 'marketplaces';
    }
    private static function column(string $column):string
    {
        if(!preg_match('/^[a-z_]+(?:\.[a-z_]+)?$/D',$column))throw new \LogicException('Geçersiz bildirim alanı.');return $column;
    }
    public static function forEvent(string $type,array $payload=[]):string
    {
        if($type==='operation'){
            if(StoreSetupRun::isNotice($payload))return 'setup';
            if(array_intersect(self::STOCK_EVENTS,$payload['events']??[]))return 'stocks';
            if(CustomerOrderNotifications::event($payload)!==null)return 'customer-orders';
            if(array_intersect(self::PAYMENT_EVENTS,$payload['events']??[]))return 'payments';
        }
        return self::forType($type);
    }
    private static function operationCondition(string $type,string $payload,string $category):?string
    {
        if($category==='setup')return self::setupCondition($type,$payload);
        $condition=match($category){
            'stocks'=>self::eventCondition($payload,self::STOCK_EVENTS),
            'payments'=>self::eventCondition($payload,self::PAYMENT_EVENTS),
            'customer-orders'=>'('.implode(' OR ',array_map(
                fn(string $key)=>'('.CustomerOrderNotifications::condition($payload,$key).')',
                array_keys(CustomerOrderNotifications::options())
            )).')',
            default=>null,
        };
        return $condition===null?null:"($type='operation' AND $condition)";
    }
    private static function eventCondition(string $payload,array $events): string
    {
        return '('.implode(' OR ',array_map(static fn(string $event)=>"COALESCE($payload,'{}') LIKE '%\"".str_replace('_','!_',$event)."\"%' ESCAPE '!'",$events)).')';
    }
    private static function nonMarketplaceOperation(string $type,string $payload): string
    {
        return '('.self::operationCondition($type,$payload,'stocks').' OR '.self::operationCondition($type,$payload,'payments').')';
    }
    public static function setupCondition(string $type,string $payload):string
    {
        self::column($type);self::column($payload);
        $event=str_replace('_','!_',StoreSetupRun::EVENT);
        return "($type='operation' AND $payload LIKE '%\"$event\"%' ESCAPE '!')";
    }
    public static function groupExpression(string $type,string $payload):string
    {
        self::column($type);self::column($payload);
        $sql='CASE';
        foreach(self::OPERATION_CATEGORIES as $category)$sql.=' WHEN '.self::operationCondition($type,$payload,$category)." THEN '$category'";
        foreach(self::TYPES as $category=>$types)if(!in_array($category,self::OPERATION_CATEGORIES,true))$sql.=" WHEN $type IN ('".implode("','",$types)."') THEN '$category'";
        return $sql." ELSE 'marketplaces' END";
    }
    public static function predicate(string $column,string $category,?string $payload=null,bool $legacyClient=false): string
    {
        self::validate($category);
        self::column($column);if($payload!==null)self::column($payload);
        $base=$category==='all'?'1=1':$column." IN ('".implode("','",self::TYPES[$category])."')";
        if($payload===null)return $base;
        if($category==='marketplaces'){
            $excluded=$legacyClient?self::operationCondition($column,$payload,'stocks'):self::nonMarketplaceOperation($column,$payload);
            return "($base AND NOT $excluded)";
        }
        return self::operationCondition($column,$payload,$category)??$base;
    }
    public static function statePredicate(string $category,bool $legacyClient=false):string
    {
        $base=self::predicate('event_type',$category);
        $condition=$category==='marketplaces'&&!$legacyClient
            ?self::nonMarketplaceOperation('o.event_type','o.payload')
            :self::operationCondition('o.event_type','o.payload',$category==='marketplaces'?'stocks':$category);
        if($condition===null)return $base;
        $matched="(event_type='operation' AND event_id IN (SELECT o.id FROM order_notifications o WHERE o.company_id=notification_states.company_id AND $condition))";
        return $category==='marketplaces'?"($base AND NOT $matched)":$matched;
    }
    public static function permits(string $category,string $type): bool{return $category==='all'||in_array($type,self::TYPES[$category],true);}
}
