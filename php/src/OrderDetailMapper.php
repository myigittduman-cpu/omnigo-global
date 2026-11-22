<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit fields only. Stored inside the encrypted delivery envelope, never financial records. */
final class OrderDetailMapper
{
    private static function text(mixed $value): string{return DeliveryMapper::text($value);}
    public static function n11(array $raw,array $delivery): array
    {
        return [...ShipmentOrderMapper::n11Contact($raw,$delivery),'province'=>self::text($raw['shippingAddress']['city']??''),'packageId'=>self::text($raw['id']??''),'status'=>self::text($raw['shipmentPackageStatus']??''),'superseded'=>($raw['shipmentPackageStatus']??'')==='Unpacked',
            'cargoCompany'=>self::text($raw['cargoProviderName']??''),'trackingNumber'=>self::text($raw['cargoTrackingNumber']??''),
            'neighborhood'=>self::text($raw['shippingAddress']['neighborhood']??''),'postalCode'=>self::text($raw['shippingAddress']['postalCode']??''),
            'products'=>array_map(fn($line)=>[...OrderCancellation::line('n11',$line,$raw),'lineId'=>self::text($line['orderLineId']??''),'title'=>self::text($line['productName']??''),'sku'=>self::text($line['stockCode']??''),'quantity'=>$line['quantity']??null,'amountCents'=>isset($line['sellerInvoiceAmount'])?KoctasOrderMapper::cents($line['sellerInvoiceAmount']):null,'status'=>self::text($line['orderItemLineItemStatusName']??''),...ShipmentSourceProduct::read('n11',$line)],$raw['lines']??[])];
    }
    public static function pazarama(array $raw,array $delivery): array
    {
        return [...ShipmentOrderMapper::pazaramaContact($raw,$delivery),'province'=>self::text($raw['shipmentAddress']['cityName']??''),'packageId'=>self::text($raw['orderId']??''),'status'=>self::text($raw['orderStatus']??''),
            'neighborhood'=>self::text($raw['shipmentAddress']['neighborhoodName']??''),'postalCode'=>self::text($raw['shipmentAddress']['postalCode']??''),
            'products'=>array_map(static function(array $line) use ($raw): array {
                $invoice=PazaramaInvoiceAmount::fromLine($line);
                return [...OrderCancellation::line('Pazarama',$line,$raw),'shippingBarcode'=>DeliveryShippingLabels::code($line['shipmentCode']??null),'lineId'=>self::text($line['orderItemId']??''),'title'=>self::text($line['product']['name']??''),'sku'=>self::text($line['product']['stockCode']??''),'quantity'=>$line['quantity']??null,'amountCents'=>$invoice,'invoiceCents'=>$invoice,'status'=>self::text($line['orderItemStatus']??''),...ShipmentSourceProduct::read('Pazarama',$line)];
            },$raw['items']??[])];
    }
    public static function koctas(array $raw,array $delivery): array
    {
        $raw['order_lines']=array_map([KoctasCancelledLine::class,'restore'],$raw['order_lines']??[]);
        return [...ShipmentOrderMapper::koctasContact($raw,$delivery),'province'=>($delivery['city']??'Diğer / Kontrol')==='Diğer / Kontrol'?self::text($raw['customer']['shipping_address']['state']??''):$delivery['city'],'packageId'=>self::text($raw['order_id']??''),'status'=>self::text($raw['order_state']??''),
            'cargoCompany'=>self::text($raw['shipping_company']??''),'trackingNumber'=>self::text($raw['shipping_tracking']??''),
            'postalCode'=>self::text($raw['customer']['shipping_address']['zip_code']??''),
            'products'=>array_map(fn($line)=>[...OrderCancellation::line('Koçtaş',$line,$raw),'lineId'=>self::text($line['order_line_id']??''),'title'=>self::text($line['product_title']??''),'sku'=>self::text($line['offer_sku']??''),'quantity'=>$line['quantity']??null,'refundedQuantity'=>array_sum(array_map(fn($refund)=>($refund['refund_state']??'')==='REFUNDED'?max(0,(int)($refund['quantity']??0)):0,$line['refunds']??[])),'amountCents'=>isset($line['price'])?KoctasOrderMapper::cents($line['price']):null,'status'=>self::text($line['order_line_state']??''),...ShipmentSourceProduct::read('Koçtaş',$line)],$raw['order_lines']??[])];
    }
}
