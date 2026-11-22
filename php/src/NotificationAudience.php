<?php
declare(strict_types=1);
namespace Omnigo;

final class NotificationAudience
{
    public static function permits(array $user,?array $event): bool
    {
        if(!$event)return true; // Explicit device test only.
        if(($event['event_type']??'')==='integration')return false;
        $detail=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        if(StoreSetupRun::isNotice($detail))return ($event['event_type']??'')==='operation'&&($detail['audience']??'')===($user['role']??'')
            &&($user['role']==='admin'||($user['role']==='client'&&(int)$user['company_id']===(int)$event['company_id']));
        if(IntegrationImportPresentation::isEvent($detail))return $user['role']==='admin'&&($detail['audience']??'')==='admin';
        if(($event['event_type']??'')==='resolved')return $user['role']==='client'&&(int)$user['company_id']===(int)$event['company_id']&&(int)$user['id']===(int)($detail['recipientId']??0)&&($detail['audience']??'')==='client';
        if($user['role']==='client')return (int)$user['company_id']===(int)$event['company_id']&&($event['event_type']??'')==='operation'&&($detail['audience']??'')==='client'
            &&(in_array('payment_updated',$detail['events']??[],true)||CustomerOrderNotifications::event($detail)!==null);
        // Actual section and operation grants are checked before delivery.
        return $user['role']==='admin'&&($detail['audience']??'')!=='client';
    }
}
