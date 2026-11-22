<?php
declare(strict_types=1);
namespace Omnigo;

/** Validates a complete editor form without accepting status or provenance fields. */
final class ShipmentDraft
{
    public static function parse(mixed $input,string $month): array
    {
        if(is_object($input))$input=get_object_vars($input);
        if(!is_array($input))Http::fail(400,'Kayıt bilgileri geçersiz.');
        $limits=['orderedAt'=>19,'customerName'=>240,'recipientName'=>240,'phone'=>60,'deliveryAddress'=>8000,'province'=>40,'district'=>150,'productName'=>1000,'invoiceAmount'=>20,'quantity'=>6,'invoiceName'=>500,'invoiceAddress'=>8000,'recipientEmail'=>254,'customerType'=>20,'taxNumber'=>20];
        if(array_diff(array_keys($input),array_keys($limits)))Http::fail(400,'Desteklenmeyen kayıt alanı.');
        $data=[];
        foreach($limits as $key=>$limit){
            $value=$input[$key]??'';
            if(!is_string($value)||mb_strlen($value)>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))Http::fail(400,'Alan uzunluğunu veya içeriğini kontrol edin.');
            $data[$key]=trim($value);
        }
        foreach(['orderedAt','customerName','deliveryAddress','province','productName','quantity'] as $key)if($data[$key]==='')Http::fail(400,'Zorunlu alanları doldurun.');
        $time=$data['orderedAt'];if(strlen($time)===16)$time.=':00';
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$time,new \DateTimeZone('Europe/Istanbul'));
        if(!$date||$date->format('Y-m-d\TH:i:s')!==$time||$date->format('Y-m')!==$month)Http::fail(400,'Sipariş tarihi seçili ayın içinde olmalı.');
        $data['orderedAt']=$date->format('c');
        if(!in_array($data['province'],ShipmentRepository::CITIES,true))Http::fail(400,'Teslimat ilini seçin.');
        if(!preg_match('/^[1-9]\d{0,4}$/D',$data['quantity']))Http::fail(400,'Adet 1–99999 arasında olmalı.');
        $data['quantity']=(int)$data['quantity'];
        $amount=$data['invoiceAmount'];unset($data['invoiceAmount']);
        if($amount==='')$data['invoiceCents']=null;
        else{
            if(!preg_match('/^(0|[1-9]\d{0,8})(?:[.,](\d{1,2}))?$/D',$amount,$match))Http::fail(400,'Tutarı binlik ayıracı olmadan yazın. Örnek: 25890,50');
            $data['invoiceCents']=(int)$match[1]*100+(int)str_pad($match[2]??'',2,'0');
        }
        if($data['recipientEmail']!==''&&!filter_var($data['recipientEmail'],FILTER_VALIDATE_EMAIL))Http::fail(400,'E-posta adresini kontrol edin.');
        if(!in_array($data['customerType'],['','Bireysel','Kurumsal'],true))Http::fail(400,'Müşteri tipini kontrol edin.');
        if($data['taxNumber']!==''&&!preg_match('/^\d{10,11}$/D',$data['taxNumber']))Http::fail(400,'Vergi numarası 10 veya 11 rakam olmalı.');
        return $data;
    }
}
