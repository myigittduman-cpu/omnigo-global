<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushMessage
{
    public static function create(array $device,?array $event,string $tag,string $role='admin',string $language='tr'): array
    {
        $detail=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        $type=$event['event_type']??'';
        $url='/';
        $customerView=$type==='operation'?(CustomerOrderNotifications::presentation($detail)['view']??null):null;
        if($event)$url='/?view='.($customerView??($type==='integration'?'connections':(in_array($type,['support','user'],true)?'support':(in_array($type,['order','operation'],true)?'orders':'shipments'))));
        if($event&&(int)($event['company_id']??0)>0)$url.='&orderCompany='.(int)$event['company_id'];
        if($event&&ReconciliationPresentation::isEvent($detail))$url='/?view=connections&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        if($event&&in_array($type,['order','operation','shipment','support','user'],true)&&!ReconciliationPresentation::isEvent($detail)){
            $noticeId=match($type){'shipment'=>(int)($detail['auditId']??0),'support'=>(int)($detail['targetId']??0),default=>(int)($event['id']??0)};
            if($noticeId>0)$url.='&noticeType='.$type.'&noticeId='.$noticeId;
        }
        if($type==='operation'&&in_array('return_updated',$detail['events']??[],true))$url='/?view=returns&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        if(in_array('payment_updated',$detail['events']??[],true)){
            $url='/?view=payments&orderCompany='.(int)$event['company_id'];
            if(in_array($detail['operation']??null,[CustomerServiceNotifications::APPROVED,PaymentApprovalReminder::OPERATION],true)){
                $url.='&noticeType=operation&noticeId='.(int)$event['id'];
            }
        }
        if($type==='resolved')$url=SupportResolutionNotifications::destination($event);
        if($type==='operation'&&array_intersect(['inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated'],$detail['events']??[]))$url='/?view=inventory&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        if($type==='operation'&&IntegrationImportPresentation::isEvent($detail))$url='/?view=inventory&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        if($type==='operation'&&UpcomingPayoutNotice::isEvent($detail))$url='/?view=payments&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        if($type==='operation'&&StoreSetupRun::isNotice($detail))$url='/?view=overview&orderCompany='.(int)$event['company_id'].'&noticeType=operation&noticeId='.(int)$event['id'];
        return [...MobileNotificationPresentation::create($event,$role,$language),'url'=>$url,'tag'=>$tag,'sound'=>true];
    }
}
