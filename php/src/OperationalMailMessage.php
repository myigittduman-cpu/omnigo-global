<?php
declare(strict_types=1);
namespace Omnigo;

final class OperationalMailMessage
{
    public static function format(array $event): array
    {
        if(ReconciliationPresentation::isEvent($event))return ReconciliationPresentation::mail($event);
        $labels=['new_order'=>'Yeni sipariş geldi','delivery_added'=>'Şehir Dışı Teslimatlar listesine eklendi','shipment_added'=>'Şehir Dışı Sevkiyat tablosuna eklendi','cancelled'=>'Sipariş iptal edildi','cancellation_requested'=>'Sipariş için iptal talebi geldi','user_change'=>'Müşteri işlemi','document_uploaded'=>'Müşteri belge yükledi','notification_test'=>'Bildirim e-postası testi'];
        $labels['return_updated']=($event['isNewReturn']??false)===true?'Yeni iade talebi geldi':'İade talebinin durumu güncellendi';
        $events=array_values(array_intersect($event['events']??[],array_keys($labels)));
        $labels['delivery_added']=DeliveryNotificationName::title($event);
        $labels['cancelled']=DeliveryNotificationName::title($event,'cancelled');
        $labels['shipment_added']=DeliveryNotificationName::title($event,'shipment_added');
        if(!$events)throw new \RuntimeException('Bildirim türü geçersiz.');
        $title=in_array('cancelled',$events,true)?$labels['cancelled']:$labels[$events[0]];
        $date=(new \DateTimeImmutable($event['detectedAt']))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i:s');
        $lines=['Firma: '.$event['company'],'Pazaryeri: '.$event['provider'],'İşlem zamanı: '.$date.' (Türkiye saati)'];
        if(!empty($event['orderNumber']))$lines[]='Sipariş numarası: '.$event['orderNumber'];
        if(!empty($event['city']))$lines[]='Şehir: '.$event['city'];
        if(!empty($event['action']))$lines[]='Yapılan işlem: '.$event['action'];
        if(!empty($event['targetId']))$lines[]='Destek talebi: #'.$event['targetId'];
        if(in_array('cancellation_requested',$events,true))$lines[]='İptal henüz kesinleşmedi. Pazaryerindeki talebi inceleyin.';
        if(in_array('return_updated',$events,true)){
            if(!empty($event['claimId']))$lines[]='İade talebi: '.$event['claimId'];
            if(!empty($event['statusLabel']))$lines[]='İade durumu: '.$event['statusLabel'];
            if(isset($event['quantity']))$lines[]='Talep edilen adet: '.(int)$event['quantity'];
            $lines[]='Talep bildirimi, iadenin onaylandığı veya para iadesinin yapıldığı anlamına gelmez.';
        }
        if(!empty($event['actorName']))$lines[]='İşlemi yapan: '.$event['actorName'];
        if(!empty($event['rowId']))$lines[]='Sevkiyat satırı: #'.$event['rowId'];
        if(!empty($event['documentKind']))$lines[]='Yüklenen belge: '.($event['documentKind']==='invoice'?'Fatura':'Teslimat evrağı');
        if(!empty($event['cancelledQuantity']))$lines[]='İptal edilen adet: '.$event['cancelledQuantity'];
        if(!empty($event['shipmentCount']))$lines[]='Eklenen sevkiyat satırı: '.$event['shipmentCount'];
        if(in_array('notification_test',$events,true))$lines[]='Bu bir kurulum kontrolüdür. Gerçek bir sipariş, iptal veya belge yüklemesi değildir. Yeni sipariş, iptal, otomatik aktarım ve müşteri belge yüklemelerinde bu adrese bildirim gönderilir.';
        $view=in_array('return_updated',$events,true)?'returns':(!empty($event['targetId'])?'support':(array_intersect($events,['document_uploaded','user_change'])?'shipments':'orders'));
        return ['subject'=>'Omnigo Global — '.$title.' · '.$event['provider'],'body'=>implode("\n",$lines)."\n\n".implode("\n",array_map(fn($key)=>'• '.$labels[$key],$events))."\n\nAyrıntıları yönetici hesabınızla görüntüleyin:\nhttps://yonetim.omnigoglobal.com/?view=".$view."\n\nOmnigo Global"];
    }
}
