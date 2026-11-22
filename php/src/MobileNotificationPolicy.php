<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared delivery policy; never changes the permanent notification feed. */
final class MobileNotificationPolicy
{
    private const INTERRUPTING_TYPES=['order','shipment','support','user','resolved'];

    public static function allows(array $device,?array $event): bool
    {
        // A null event is an explicit, user-requested device diagnostic.
        if($event===null)return true;
        $type=$event['event_type']??'';
        if(in_array($type,self::INTERRUPTING_TYPES,true))return true;
        if($type!=='operation')return false;
        $detail=json_decode($event['payload']??'{}',true);
        if(is_array($detail)&&StoreSetupRun::isNotice($detail))return true;
        return is_array($detail)&&(CustomerOrderNotifications::event($detail)!==null||is_array($detail['events']??null)&&(bool)array_intersect(['cancelled','cancellation_requested','return_updated','dispatched','document_uploaded','document_deleted','payment_updated','inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated',UpcomingPayoutNotice::EVENT,...IntegrationImportPresentation::EVENTS],$detail['events']));
    }

    public static function allowsArrival(array $event): bool
    {
        return self::allows(['kind'=>'desktop'],['event_type'=>$event['type']??'', 'payload'=>json_encode(['events'=>$event['events']??[]])]);
    }
}
