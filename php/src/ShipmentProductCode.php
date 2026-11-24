<?php
declare(strict_types=1);
namespace Omnigo;

/** A shipment may only use the seller code from its exact source line. */
final class ShipmentProductCode
{
    public static function normalize(mixed $value): string
    {
        if(!is_string($value)&&!is_int($value))return '';
        $code=(string)$value;
        return strlen($code)<=800&&preg_match('/^\S(?:[^\p{C}]*\S)?$/uD',$code)&&!preg_match('/\p{C}/u',$code)?$code:'';
    }

    public static function legacyIdentity(array $shipment): string
    {
        $fields=['orderedAt','province','district','productName','quantity','customerName','recipientName','phone',
            'recipientEmail','invoiceName','invoiceAddress','deliveryAddress','invoiceCents'];
        return hash('sha256',json_encode(array_map(fn($key)=>$shipment[$key]??null,$fields),JSON_THROW_ON_ERROR));
    }

    public static function legacyCode(array $shipment,string $provider): string
    {
        $proof=$shipment['verifiedLegacyProduct']??null;
        if(!is_array($proof)||($proof['provider']??null)!==$provider
            ||!is_string($proof['sourceKey']??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof['sourceKey'])
            ||!is_string($proof['identity']??null)||!hash_equals(self::legacyIdentity($shipment),$proof['identity']))return '';
        return self::normalize($proof['code']??null);
    }

    public static function index(array $order): array
    {
        $codes=[];
        $provider=$order['provider']??'';
        $identity=$provider==='Pazarama'?($order['externalId']??''):($order['orderNumber']??'');
        if($identity==='')return $codes;
        foreach($order['details']['products']??[] as $product){
            $code=self::sourceCode($provider,$product);
            if($code==='')continue;
            $key=DeliveryFulfillment::key($order,$product);
            if($key!==null)$codes[$key][$code]=true;
        }
        return $codes;
    }

    public static function sourceCode(string $provider,array $product): string
    {
        $value=match($provider){
            'n11','Pazarama','Koçtaş','idefix'=>$product['sku']??null,
            'Hepsiburada','Trendyol','PTT AVM'=>$product['sellerStockCode']??null,
            default=>null,
        };
        return self::normalize(is_string($value)?trim($value):$value);
    }
}
