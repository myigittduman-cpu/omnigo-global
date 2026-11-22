<?php
declare(strict_types=1);
namespace Omnigo;

/** Customer names stay in encrypted order/audit storage and authenticated responses. */
final class DeliveryNotificationName
{
    public static function enrich(Database $db,DeliveryCipher $cipher,int $company,int $record,array $detail): array
    {
        if(!array_intersect(['delivery_added','shipment_added','cancelled'],$detail['events']??[]))return $detail;
        if(trim($detail['buyerName']??'')!=='')return $detail;
        $row=$db->one('SELECT provider,external_id,payload FROM delivery_orders WHERE company_id=? AND id=?',[$company,$record]);
        if($row){$order=$cipher->open($company,'order:'.$row['provider'].':'.$row['external_id'],$row['payload']);$detail['buyerName']=trim($order['buyerName']??'');}
        return $detail;
    }
    public static function title(array $detail,string $event='delivery_added'): string
    {
        $name=mb_substr(trim(preg_replace('/[\p{C}\p{Z}]+/u',' ',(string)($detail['buyerName']??''))??''),0,120);
        if($event==='cancelled')return $name!==''?$name.' siparişi iptal edildi':'Sipariş iptal edildi';
        if($event==='shipment_added')return ($name!==''?$name.' siparişi':'Sipariş').' Şehir Dışı Sevkiyat listesine eklendi';
        return $name!==''?$name.' siparişi Şehir Dışı Teslimatlar listesine eklendi':'Şehir Dışı Teslimatlar listesine eklendi';
    }
}
