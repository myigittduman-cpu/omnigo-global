<?php
declare(strict_types=1);
namespace Omnigo;

final class ReconciliationPresentation
{
    public static function isEvent(array $event): bool{return (bool)array_intersect($event['events']??[],['reconciliation_issue','reconciliation_recovered']);}
    public static function presentation(array $event): array
    {
        $recovered=in_array('reconciliation_recovered',$event['events']??[],true);
        return ['title'=>$recovered?'Sipariş mutabakatı düzeldi':'Sipariş mutabakatında fark var',
            'body'=>$recovered?'Son tamamlanan aktarımda kayıtlar eşleşiyor.':'Eksik, mükerrer kayıt veya tutar farkını yönetici panelinde inceleyin.','view'=>'connections'];
    }
    public static function summary(array $state,?int $now=null): array
    {
        $result=$state['reconciliationCheck']??[];$now??=time();
        $fresh=!empty($result['checkedAt'])&&$now-strtotime($result['checkedAt'])<=180&&!isset($state['scan'])&&!isset($state['scanEnd'])&&empty($state['failure'])&&empty($state['recentOrders']['historyBackoff']);
        if(isset($state['reconciliationSource']))$fresh=$fresh&&HepsiburadaReconciliationSource::current($state);
        return ['state'=>$fresh?($result['state']??'waiting'):'waiting','checkedAt'=>$result['checkedAt']??null,'sourceAsOf'=>$result['sourceAsOf']??null,
            'orderCount'=>$result['orderCount']??0,'total'=>$result['total']??0,'counts'=>$result['counts']??[]];
    }
    public static function mail(array $event): array
    {
        $result=$event['reconciliation'];$presentation=self::presentation($event);
        $lines=[$presentation['body'],'Firma: '.$event['company'],'Pazaryeri: '.$event['provider'],'Kontrol edilen sipariş: '.$result['orderCount']];
        foreach(['missing'=>'Eksik kayıt','duplicate'=>'Mükerrer kayıt','amount'=>'Tutar / adet farkı','unexpected'=>'Kaynakla eşleşmeyen kayıt','period'=>'Dönem farkı','status'=>'Durum farkı'] as $key=>$label)$lines[]=$label.': '.($result['counts'][$key]??0);
        $lines[]='Kaynak zamanı: '.(new \DateTimeImmutable($result['sourceAsOf']))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i:s').' (Türkiye saati)';
        $lines[]='Kayıtlar otomatik değiştirilmedi. Ayrıntılar yalnızca yönetici panelindeki bildirimde yer alır.';
        return ['subject'=>'Omnigo Global — '.$presentation['title'].' · '.$event['provider'],
            'body'=>implode("\n",$lines)."\n\nhttps://yonetim.omnigoglobal.com/?view=connections\n\nOmnigo Global"];
    }
}
