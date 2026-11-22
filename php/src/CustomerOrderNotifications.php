<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer-facing changes in the two operational order lists. */
final class CustomerOrderNotifications
{
    public const DELIVERY_ADDED='client_delivery_added';
    public const DELIVERY_CANCELLED='client_delivery_cancelled';
    public const SHIPMENT_ADDED='client_shipment_added';
    public const SHIPMENT_CANCELLED='client_shipment_cancelled';

    private const EVENTS=[
        self::DELIVERY_ADDED=>['deliveryAdded','deliveries','Şehir Dışı Teslimatlar listesine yeni sipariş eklendi'],
        self::DELIVERY_CANCELLED=>['deliveryCancelled','deliveries','İptal edilen sipariş Şehir Dışı Teslimatlar listesinden çıkarıldı'],
        self::SHIPMENT_ADDED=>['shipmentAdded','shipments','Şehir Dışı Sevkiyat tablosuna yeni sipariş eklendi'],
        self::SHIPMENT_CANCELLED=>['shipmentCancelled','shipments','İptal edilen sipariş satırı aktif sevkiyattan çıkarıldı'],
    ];
    public const DEFAULTS=['deliveryAdded'=>false,'deliveryCancelled'=>false,'shipmentAdded'=>false,'shipmentCancelled'=>false];
    private const HIDDEN=[self::DELIVERY_CANCELLED,self::SHIPMENT_CANCELLED];

    public static function options(): array
    {
        return array_column(array_diff_key(self::EVENTS,array_flip(self::HIDDEN)),1,0);
    }

    public static function event(array $detail): ?string
    {
        $events=$detail['events']??null;
        if(($detail['audience']??'')!=='client'||!is_array($events)||count($events)!==1)return null;
        $event=$events[0]??null;
        return is_string($event)&&isset(self::EVENTS[$event])?$event:null;
    }

    public static function setting(string $event): string{return self::EVENTS[$event][0];}

    public static function condition(string $payload,string $setting): string
    {
        // Only keys from this fixed catalog are interpolated; escape LIKE wildcards in the event code.
        $event=array_search($setting,array_column(self::EVENTS,0),true);
        if($event===false)throw new \LogicException('Sipariş bildirim seçeneği geçersiz.');
        $code=str_replace('_','!_',array_keys(self::EVENTS)[$event]);
        return "$payload LIKE '%\"audience\":\"client\"%' AND $payload LIKE '%\"events\":[\"$code\"]%' ESCAPE '!'";
    }

    public static function presentation(array $detail): ?array
    {
        $event=self::event($detail);
        if($event===null)return null;
        [,$view,$title]=self::EVENTS[$event];
        return ['title'=>$title,'body'=>'','view'=>$view];
    }

    private ?array $profile=null;
    public function __construct(private Database $db,private int $company){}

    public function enabled(string $event): bool
    {
        if(in_array($event,self::HIDDEN,true))return false;
        if($this->profile===null){
            $row=$this->db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$this->company]);
            $this->profile=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        }
        [$setting,$page]=self::EVENTS[$event];
        $policy=$this->profile['workspacePolicy']??[];
        return ($policy['notifications'][$setting]??false)===true&&($this->profile['modules'][$page]??true)===true
            &&CustomerSectionAudience::hasStoreRole($page,$policy['pages'][$page]??'shown');
    }

    /** The parent import owns the transaction and retries. Only new records enter the existing push queue. */
    public function publish(string $provider,string $identity,array $events): void
    {
        if(!$events||!MarketplaceVisibility::visible($provider))return;
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Sipariş bildirimi işlemle birlikte kaydedilmeli.');
        $provider=MarketplaceSalesSummary::PROVIDERS[$provider]??strtolower(str_replace(' ','',$provider));
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        foreach(array_unique($events) as $event){
            if(!$this->enabled($event))continue;
            // Keep buyer details and marketplace references out of notification payloads.
            $payload=json_encode(['audience'=>'client','events'=>[$event]],JSON_THROW_ON_ERROR);
            $key=hash('sha256',json_encode([$event,$provider,$identity],JSON_THROW_ON_ERROR));
            $result=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
                [$this->company,$provider,$key,'operation',$payload,gmdate('c')]);
            if($result->rowCount()===1)PushRoutes::devices($this->db)->enqueue($this->db->insertId(),$this->company);
        }
    }
}
