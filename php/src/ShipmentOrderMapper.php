<?php
declare(strict_types=1);
namespace Omnigo;

/** Builds one shipment row per marketplace order line. No billing/shipping identity fallback. */
final class ShipmentOrderMapper
{
    private static function text(mixed $value,int $length=8000): string{return DeliveryMapper::text($value,$length);}
    private static function address(array $value,array $keys): string
    {
        return implode(', ',array_unique(array_filter(array_map(fn($key)=>self::text($value[$key]??''),$keys))));
    }
    private static function name(array $value): string{return trim(self::text($value['firstname']??'').' '.self::text($value['lastname']??''));}
    private static function billing(array $base,string $name,string $address,string $type,string $number,string $office): array
    {
        return [...$base,'invoiceName'=>$name,'invoiceAddress'=>$address.($type==='Kurumsal'&&$office!==''?"\nV.D.: ".$office:''),'customerType'=>$type,'taxNumber'=>$type==='Kurumsal'?$number:''];
    }
    private static function base(array $delivery): array
    {
        return ['orderedAt'=>$delivery['createdAt'],'customerName'=>$delivery['buyerName'],'phone'=>$delivery['phone'],'deliveryAddress'=>$delivery['address'],'province'=>$delivery['city'],'district'=>$delivery['district'],'packageStatus'=>$delivery['status']];
    }
    private static function line(array $base,string $order,mixed $id,string $title,int $quantity,?int $amount): array
    {
        $id=self::text($id,180);if($id===''||$order===''||$quantity<1)throw new SyncFailure(422);
        return ['key'=>hash('sha256',json_encode([$order,$id],JSON_THROW_ON_ERROR)),'payload'=>[...$base,'productName'=>$title,'quantity'=>$quantity,'invoiceCents'=>$amount]];
    }
    public static function n11Contact(array $raw,array $delivery): array
    {
        $bill=$raw['billingAddress']??[];$shipping=$raw['shippingAddress']??[];
        $type=match((string)($bill['invoiceType']??'')){'1'=>'Bireysel','2'=>'Kurumsal',default=>''};
        return self::billing([...self::base($delivery),'recipientName'=>self::text($shipping['fullName']??''),'recipientEmail'=>self::text($raw['customerEmail']??'')],self::text($bill['fullName']??''),self::address($bill,['address','neighborhood','district','city','postalCode']),$type,self::text($bill['taxId']??$raw['taxId']??''),self::text($bill['taxHouse']??$bill['taxOffice']??$raw['taxOffice']??''));
    }
    public static function n11(array $raw,array $delivery): array
    {
        if($raw['micro']??false)return [];
        $base=self::n11Contact($raw,$delivery);
        $rows=[];foreach($raw['lines']??[] as $line){
            if(!in_array($line['orderItemLineItemStatusName']??'',['Created','Picking','Shipped','Delivered','Cancelled','UnSupplied'],true))continue;
            $rows[]=self::line([...$base,'marketplaceCancellation'=>OrderCancellation::line('n11',$line,$raw),'marketplaceShipping'=>OrderShipping::line('n11',$line,$raw),'marketplaceAmounts'=>ShipmentPaymentAmounts::n11($line)],$delivery['orderNumber'],$line['orderLineId']??'',self::text($line['productName']??''),(int)($line['quantity']??0),isset($line['sellerInvoiceAmount'])?KoctasOrderMapper::cents($line['sellerInvoiceAmount']):null);
        }return $rows;
    }
    public static function pazaramaContact(array $raw,array $delivery): array
    {
        $bill=$raw['billingAddress']??[];$shipping=$raw['shipmentAddress']??[];
        $type=match((string)($bill['invoiceType']??'')){'1'=>'Bireysel','2'=>'Kurumsal',default=>''};
        $address=self::text($bill['displayAddressText']??'')?:self::address($bill,['addressDetail','neighborhoodName','districtName','cityName','postalCode']);
        return self::billing([...self::base($delivery),'recipientName'=>self::text($shipping['nameSurname']??''),'recipientEmail'=>self::text($shipping['customerEmail']??$raw['customerEmail']??'')],self::text($type==='Kurumsal'?($bill['companyName']??$bill['nameSurname']??''):($bill['nameSurname']??'')),$address,$type,self::text($bill['taxNumber']??''),self::text($bill['taxOffice']??''));
    }
    public static function pazarama(array $raw,array $delivery): array
    {
        $base=self::pazaramaContact($raw,$delivery);
        $rows=[];foreach($raw['items']??[] as $line){
            if(!in_array($line['orderItemStatus']??null,[3,12,18,5,16,19,11,14,7,9,6,13],true))continue;
            $amounts=ShipmentPaymentAmounts::pazarama($line,PazaramaInvoiceAmount::sellerTotal($line));
            $rows[]=self::line([...$base,'marketplaceCancellation'=>OrderCancellation::line('Pazarama',$line,$raw),'marketplaceShipping'=>OrderShipping::line('Pazarama',$line,$raw),'marketplaceAmounts'=>$amounts,'invoiceBasis'=>'buyer_product'],$delivery['externalId'],$line['orderItemId']??'',self::text($line['product']['name']??''),(int)($line['quantity']??0),$amounts['netProductCents']??null);
        }return $rows;
    }
    public static function koctasContact(array $raw,array $delivery): array
    {
        $customer=$raw['customer']??[];$bill=$customer['billing_address']??[];$shipping=$customer['shipping_address']??[];$organization=$customer['organization']??[];
        $extra=[];foreach($raw['order_additional_fields']??[] as $field)if(isset($field['code']))$extra[$field['code']]=self::text($field['value']??'');
        $company=self::text($bill['company']??$organization['name']??'');$flag=strtolower($extra['is-corporate']??'');
        $type=in_array($flag,['true','1','yes','evet'],true)||$company!==''?'Kurumsal':($bill?'Bireysel':'');
        return self::billing([...self::base($delivery),'recipientName'=>self::name($shipping),'recipientEmail'=>self::text($raw['customer_notification_email']??'')],($extra['invoice-title']??'')?:($company?:self::name($bill)),self::address($bill,['street_1','street_2','additional_info','zip_code','city','state']),$type,($extra['tax-number']??'')?:self::text($organization['tax_identification_number']??''),$extra['tax-office']??'');
    }
    public static function koctas(array $raw,array $delivery): array
    {
        if(($raw['order_state']??'')==='STAGING')return [];
        $base=self::koctasContact($raw,$delivery);
        $rows=[];foreach($raw['order_lines']??[] as $line){
            $line=KoctasCancelledLine::restore($line);
            if(!in_array($line['order_line_state']??'',['WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING','SHIPPED','TO_COLLECT','RECEIVED','CLOSED','CANCELED','REFUSED'],true))continue;
            $rows[]=self::line([...$base,'marketplaceCancellation'=>OrderCancellation::line('Koçtaş',$line,$raw),'marketplaceRowStatus'=>($line['order_line_state']??'')==='CLOSED'?'completed':OrderShipping::line('Koçtaş',$line,$raw),'marketplaceShipping'=>OrderShipping::line('Koçtaş',$line,$raw),'marketplaceAmounts'=>ShipmentPaymentAmounts::koctas($line)],$delivery['orderNumber'],$line['order_line_id']??'',self::text($line['product_title']??''),(int)($line['quantity']??0),isset($line['price'])?KoctasOrderMapper::cents($line['price']):null);
        }return $rows;
    }
}
