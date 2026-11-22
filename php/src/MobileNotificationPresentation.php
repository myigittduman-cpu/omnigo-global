<?php
declare(strict_types=1);
namespace Omnigo;

final class MobileNotificationPresentation
{
    public static function create(?array $event,string $role,string $language='tr'): array
    {
        $title=match($role){'admin'=>'Omnigo Global Yönetici Portalı','client'=>'Omnigo Global İş Ortağım',default=>throw new \InvalidArgumentException('Bildirim rolü geçersiz.')};
        $t=fn(string $source,array $values=[])=>NotificationLanguage::text($source,$language,$values);
        if(!$event)return ['title'=>$title,'body'=>$t('Bildirim denemesi; mobil bildirim bağlantısı çalışıyor.')];
        $type=$event['event_type']??'order';
        $detail=json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        if($type==='order'){
            $source=match(strtolower($event['provider']??'')){
                'hepsiburada'=>'Hepsiburada’dan','n11'=>'n11’den','idefix'=>'idefix’ten',
                'pttavm'=>'PTT AVM’den','pazarama'=>'Pazarama’dan','koctas'=>'Koçtaş’tan',
                'teknosa'=>'Teknosa’dan','trendyol'=>'Trendyol’dan','amazon'=>'Amazon’dan',
                default=>'Pazaryerinden',
            };
            $amount=$event['amountCents']??null;
            $money=is_int($amount)&&$amount>=0&&$amount<=999999999999?number_format($amount/100,2,',','.').'₺ tutarında ':'';
            $body=$source.' '.$money.'yeni bir siparişiniz var.';
            if(in_array($language,['en','de'],true)){
                $provider=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pttavm'=>'PTT AVM','pazarama'=>'Pazarama','koctas'=>'Koçtaş','teknosa'=>'Teknosa','trendyol'=>'Trendyol','amazon'=>'Amazon'][strtolower($event['provider']??'')]??$t('Pazaryeri');
                $formatted=$money!==''?number_format($amount/100,2,$language==='de'?',':'.',$language==='de'?'.':',').' TRY':'';
                $body=$formatted!==''?$t("{0}'dan {1} tutarında yeni bir siparişiniz var.",[$provider,$formatted]):$t("{0}'dan yeni bir siparişiniz var.",[$provider]);
            }
        }elseif(in_array($type,['operation','shipment'],true)){
            $message=OperationalNotifications::presentation($type,$detail);
            $body=$type==='shipment'?ActivityNotification::shipment($detail):($type==='operation'&&in_array('cancelled',$detail['events']??[],true)?$t(DeliveryNotificationName::title($detail,'cancelled')).'.':trim($t($message['title']).($message['body']!==''?'. '.$t($message['body']):''))); 
        }elseif(in_array($type,['support','user'],true)){
            $message=UserChangeNotifications::presentation($detail);
            $body=$t($message['title']).($message['body']!==''?' '.$t($message['body']):'');
        }elseif($type==='resolved'){
            $body=$t(SupportResolutionNotifications::presentation($detail)['body']);
        }else{
            $body=$t('Yeni bir bildiriminiz var. Ayrıntıları uygulamada görüntüleyebilirsiniz.');
        }
        // Cancellation alerts include the explicitly requested buyer name, resolved in company scope.
        // Addresses, phone numbers and order contents remain inside the authenticated portal.
        $company=mb_substr(trim(preg_replace('/[\p{C}\p{Z}]+/u',' ',(string)($event['company']??''))??''),0,120);
        if(mb_strtolower($company,'UTF-8')==='vizyona beyaz eşya')$company='Vizyona';
        if(in_array('payment_updated',$detail['events']??[],true))$body=CustomerServiceNotifications::paymentMessage($detail);
        $namedShipmentDocument=$type==='shipment'&&($detail['operation']??'')==='document'&&!empty($detail['documentSubject']);
        return ['title'=>$title,'body'=>mb_substr(($role==='admin'&&$company!==''&&!$namedShipmentDocument?$company.'; ':'').$body,0,500)];
    }
}
