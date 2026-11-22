<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared text for the inbox, web push and native clients. */
final class ActivityNotification
{
    public static function clean(mixed $value): string
    {
        return mb_substr(trim(preg_replace('/[\p{C}\p{Z}]+/u',' ',(string)$value)??''),0,120);
    }
    public static function message(array $detail): ?string
    {
        $events=$detail['events']??[];$actor=self::clean($detail['actorName']??'Kullanıcı');
        if(in_array('inventory_restocked',$events,true))return self::clean($detail['productTitle']??'Ürün').' yeniden stokta · '.(int)($detail['quantity']??0).' adet.';
        if(in_array('purchase_price_updated',$events,true))return self::clean($detail['productTitle']??'Ürün').' ürününün alış fiyatı güncellendi.';
        if(in_array('inventory_adjusted',$events,true)&&isset($detail['stockDifference'])&&$detail['stockDifference']!==0)return self::clean($detail['productTitle']??'Ürün').' · Stok farkı: pazaryeri '.(int)$detail['marketplaceQuantity'].', Omnigo '.(int)$detail['quantity'].' adet.';
        if(in_array('inventory_adjusted',$events,true))return self::clean($detail['productTitle']??'Ürün').' · Ortak stok güncellendi: '.(int)($detail['quantity']??0).' adet.';
        if(in_array('inventory_low',$events,true))return 'Stok tükendi: '.self::clean($detail['productTitle']??'Ürün').' · '.(($detail['stockMode']??'')==='horoz'?'Ortak stok':'Toplam stok').': '.(int)($detail['quantity']??0).' adet.';
        if(in_array('dispatched',$events,true))return self::clean($detail['city']??'Seçili şehir')." için ".(int)($detail['orderCount']??0).' sipariş, '.(int)($detail['pieceCount']??0).' parça sevk edildi.';
        if(in_array('return_updated',$events,true))return ($detail['isNewReturn']??false)===true?'Yeni iade talebi geldi.':'İade durumu: '.self::clean($detail['statusLabel']??'Güncellendi').'.';
        if(in_array('document_uploaded',$events,true))return $actor.', bir '.(($detail['documentKind']??'')==='invoice'?'fatura':'teslimat evrağı').' yükledi.';
        if(in_array('document_deleted',$events,true))return $actor.', bir '.(($detail['documentKind']??'')==='invoice'?'faturayı':'teslimat evrağını').' kaldırdı.';
        return null;
    }
    public static function shipment(array $detail): string
    {
        $actor=self::clean($detail['actorName']??'Müşteri kullanıcısı');
        if(($detail['operation']??'')==='document'){
            $name=self::clean($detail['documentSubject']??'');
            $invoice=in_array('invoiceDocument',$detail['fields']??[],true);
            if($name==='')return $invoice?'Fatura sisteme yüklendi':'Teslimat evrağı sisteme yüklendi';
            return $name.($invoice?' faturası sisteme yüklendi':' teslimat evrağı sisteme yüklendi');
        }
        return $actor.', şehir dışı sevkiyat listesinde '.match($detail['operation']??''){'create'=>'bir ekleme yaptı','delete','undo'=>'bir kaydı kaldırdı','restore'=>'bir kaydı geri aldı','status'=>'sipariş durumunu güncelledi',default=>'bir kaydı düzenledi'}.'.';
    }
    public static function key(array $detail): string
    {
        $events=$detail['events']??[];
        if(array_intersect(['inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated'],$events))return 'stock';
        if(array_intersect(['cancelled','cancellation_requested'],$events))return 'cancellation';
        if(in_array('return_updated',$events,true))return 'returns';
        if(in_array('dispatched',$events,true))return 'shipment';
        if(array_intersect(['document_uploaded','document_deleted'],$events))return ($detail['section']??'shipments')==='orders'?'documents':'shipment';
        return 'transfer';
    }
}
