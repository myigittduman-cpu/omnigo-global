<?php
declare(strict_types=1);
namespace Omnigo;

final class DeliveryCorrectionFields
{
    public const CONTACT=['buyerName'=>['Siparişi veren kişi',240],'recipientName'=>['Teslim alacak kişi',240],'phone'=>['Teslimat telefonu',60],'address'=>['Teslimat adresi',8000],'city'=>['Teslimat ili',100],'district'=>['İlçe',150]];
    public static function describe(array $order): array
    {
        $fields=[];
        foreach(self::CONTACT as $key=>[$label,$limit]){
            $value=$key==='recipientName'?($order['details']['recipientName']??''):($order[$key]??'');
            if($key==='city'&&!in_array($value,DeliveryMapper::CITIES,true))$value=$order['manualCorrections']['city']??$order['details']['province']??'';
            if($value==='Diğer / Kontrol')$value='';
            $missing=in_array($key,['buyerName','recipientName','phone','address','city'],true)&&trim($value)==='';
            $outside=$key==='city'&&$value!==''&&DeliveryMapper::city($value)==='Diğer / Kontrol';
            $fields[]=['key'=>$key,'label'=>$label,'value'=>$value,'limit'=>$limit,'error'=>$missing||$outside,'message'=>$outside?'Kapsam dışı — bu ile teslimat yapılmıyor.':($missing?$label.' eksik.':''),'required'=>in_array($key,['buyerName','recipientName','phone','address','city'],true)];
        }
        if(empty($order['orderNumber']))$fields[]=['key'=>'orderNumber','label'=>'Sipariş numarası','value'=>'','limit'=>180,'error'=>true,'message'=>'Sipariş numarası eksik.','required'=>true];
        foreach($order['details']['products']??[] as $index=>$product){
            $id=(string)($product['lineId']??'');if($id==='')continue;
            if(($product['title']??'')===''||isset($order['manualCorrections']['productTitle:'.$id]))$fields[]=['key'=>'productTitle:'.$id,'label'=>($index+1).'. ürün adı','value'=>$product['title']??'','limit'=>1000,'error'=>empty($product['title']),'message'=>empty($product['title'])?'Ürün adı eksik.':'','required'=>true];
            if(($product['quantity']??0)<1)$fields[]=['key'=>'productQuantity:'.$id,'label'=>($index+1).'. ürün adedi','value'=>'','limit'=>5,'error'=>true,'message'=>'Ürün adedi eksik.','required'=>true];
        }
        return $fields;
    }
    public static function parse(array $order,mixed $input): array
    {
        if($input instanceof \stdClass)$input=(array)$input;
        if(!is_array($input)||count($input)>100)Http::fail(400,'Düzeltme alanlarını kontrol edin.');
        $allowed=array_column(self::describe($order),null,'key');$result=[];
        foreach($input as $key=>$value){
            if(!isset($allowed[$key])||!is_string($value)||mb_strlen($value)>$allowed[$key]['limit']||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value))Http::fail(400,'Desteklenmeyen veya geçersiz düzeltme alanı.');
            $value=trim($value);if($value===(string)$allowed[$key]['value'])continue;
            if($value===''&&$allowed[$key]['required'])Http::fail(422,$allowed[$key]['label'].' boş bırakılamaz.');
            if($key==='phone'&&!preg_match('/^[+\d\s().-]{7,60}$/u',$value))Http::fail(422,'Teslimat telefonunu kontrol edin.');
            if(str_starts_with($key,'productQuantity:')&&!preg_match('/^[1-9]\d{0,4}$/D',$value))Http::fail(422,'Ürün adedi 1–99999 arasında olmalı.');
            $result[$key]=$value;
        }
        return $result;
    }
}
