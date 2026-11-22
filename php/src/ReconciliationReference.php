<?php
declare(strict_types=1);
namespace Omnigo;

/** A compact reference of completed API reads, captured before the local snapshot is published. */
final class ReconciliationReference
{
    public const PROVIDERS=['n11'=>'n11','pazarama'=>'Pazarama','idefix'=>'idefix','koctas'=>'Koçtaş','hepsiburada'=>'Hepsiburada','pttavm'=>'PTT AVM','trendyol'=>'Trendyol'];
    public static function key(string $value): string{return hash('sha256',$value);}
    public static function money(array $order): array{return array_map(static fn($field)=>(int)$order[$field],array_keys(SalesCalendar::ZERO));}
    public static function page(array &$state,array $deliveries,bool $reset): void
    {
        if($reset||!isset($state['reconciliationDraft']))$state['reconciliationDraft']=['deliveries'=>[],'shipments'=>[]];
        $draft=&$state['reconciliationDraft'];
        foreach($deliveries as $delivery){
            $id=(string)$delivery['externalId'];
            $draft['deliveries'][self::key($id)]=['reference'=>$id,'active'=>(bool)$delivery['active']];
            foreach($delivery['shipmentItems']??[] as $item){
                $value=$item['payload'];$cancel=$value['marketplaceCancellation']??[];
                $key=self::key($delivery['provider'].':'.$item['key']);
                $draft['shipments'][$key]=['reference'=>(string)$delivery['orderNumber'],'city'=>$value['province'],
                    'orderedAt'=>$value['orderedAt'],'invoiceCents'=>(int)$value['invoiceCents'],'quantity'=>(int)$value['quantity'],
                    'cancelled'=>!empty($cancel['cancelledQuantity'])&&empty($cancel['partial'])];
                if($delivery['provider']==='Pazarama'){
                    $draft['shipments'][$key]['invoiceBasis']=$value['invoiceBasis']??null;
                    $draft['shipments'][$key]['invoiceCents']=$value['invoiceCents']??null;
                }
            }
        }
    }
    public static function complete(array &$state,array $orders,string $from,string $asOf): void
    {
        $expected=[];
        foreach($orders as $order){$id=(string)$order['externalId'];$expected[self::key($id)]=['reference'=>$id,'month'=>$order['month'],'money'=>self::money($order)];}
        $state['reconciliationReference']=['version'=>1,'from'=>$from,'asOf'=>$asOf,'capturedAt'=>gmdate('c'),'orders'=>$expected,
            ...($state['reconciliationDraft']??['deliveries'=>[],'shipments'=>[]])];
        unset($state['reconciliationDraft']);
    }
    /** Mirakl updates are incremental; the first reference is always a complete API backfill. */
    public static function incremental(array &$state,array $orders,array $deliveries,bool $full,bool $first,bool $complete,string $from,string $asOf): void
    {
        if($first){$state['reconciliationDraft']=$full?['orders'=>[],'deliveries'=>[],'shipments'=>[]]:array_intersect_key($state['reconciliationReference']??[],array_flip(['orders','deliveries','shipments']));}
        self::page($state,$deliveries,false);
        foreach($orders as $order){$id=(string)$order['externalId'];$state['reconciliationDraft']['orders'][self::key($id)]=['reference'=>$id,'month'=>$order['month'],'money'=>self::money($order)];}
        if($complete){$state['reconciliationReference']=['version'=>1,'from'=>$from,'asOf'=>$asOf,'capturedAt'=>gmdate('c'),...$state['reconciliationDraft']];unset($state['reconciliationDraft']);}
    }
}
