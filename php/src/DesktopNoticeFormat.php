<?php
declare(strict_types=1);
namespace Omnigo;

final class DesktopNoticeFormat
{
    private const PREVIOUS_ADMIN = [
        'cancelled','cancellation_requested','payment_updated','return_updated','dispatched','document_uploaded',
        'document_deleted','inventory_low','inventory_adjusted','purchase_price_updated',
    ];
    private const NEW_ADMIN = [
        'inventory_restocked','marketplace_import_started','marketplace_import_completed','marketplace_import_failed','upcoming_payout_amount_changed',
    ];

    public static function supports(array $item,string $role,bool $current): bool
    {
        if(($item['type']??'')!=='operation')return true;
        $events=$item['events']??null;
        if(!is_array($events))return false;
        if(CustomerOrderNotifications::event($item)!==null)return true;
        if(in_array('store_setup_completed',$events,true))return $current;
        if(array_intersect(self::NEW_ADMIN,$events))return $current&&$role==='admin';
        if($role==='client')return in_array('payment_updated',$events,true);
        return (bool)array_intersect(self::PREVIOUS_ADMIN,$events);
    }
}
