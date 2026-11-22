<?php
declare(strict_types=1);
namespace Omnigo;

/** PttAVM order/line identities, never customer names, determine uniqueness. */
final class PttavmOrderMapper
{
    public static function shipping(string $status): string
    {
        return match($status){
            'kargo_yapilmasi_bekleniyor'=>'pending','gonderilmis','gonderilmiş'=>'shipped',
            'tamamlandi'=>'delivered','iptal','odeme_gecersiz'=>'cancelled',
            'iade','gondericisine_teslim_edildi','havale_onayi_bekleniyor','onay_surecinde',''=>'unknown',
            default=>throw new SyncFailure(422),
        };
    }
    public static function batch(array $rows,?string $storeName=null,?callable $catalogCode=null): array
    {
        $orders=[];$codes=[];
        foreach($rows as $raw){
            if($storeName!==null&&mb_strtolower(trim((string)($raw['tedarikciFirmaAdi']??'')))!==mb_strtolower(trim($storeName)))throw new SyncFailure(403);
            $number=DeliveryMapper::text($raw['siparisNo']??'',180);
            if($number===''||!is_array($raw['siparisUrunler']??null)||!$raw['siparisUrunler'])throw new SyncFailure(422);
            if(!isset($orders[$number]))$orders[$number]=[...$raw,'siparisUrunler'=>[]];
            foreach(['islemTarihi','siparisAdresi','siparisIli','siparisIlce'] as $field)if(($orders[$number][$field]??null)!==($raw[$field]??null))throw new SyncFailure(422);
            foreach($raw['siparisUrunler'] as $line){
                $id=(string)($line['lineItemId']??'');if(!ctype_digit($id)||$id==='0')throw new SyncFailure(422);
                if(isset($orders[$number]['siparisUrunler'][$id])&&$orders[$number]['siparisUrunler'][$id]!==$line)throw new SyncFailure(422);
                $orders[$number]['siparisUrunler'][$id]=$line;
            }
            foreach(self::sellerStockCodes($raw) as $id=>$code)$codes[$number][$id][$code]=true;
        }
        $mapped=[];
        foreach($orders as $number=>$raw){
            $verified=[];
            foreach($raw['siparisUrunler'] as $id=>$line){
                $candidates=$codes[$number][$id]??[];
                if(count($candidates)===1)$verified[$id]=(string)array_key_first($candidates);
                elseif(!$candidates&&$catalogCode!==null)$verified[$id]=ShipmentProductCode::normalize($catalogCode((string)($line['urunId']??'')));
            }
            $mapped[]=self::mapWithCodes($raw,$verified);
        }
        return $mapped;
    }
    public static function sellerStockCodes(array $raw): array
    {
        // PTT supplies the seller code at response level. A grouped response cannot attribute it to a line.
        $lines=$raw['siparisUrunler']??null;
        if(!is_array($lines)||count($lines)!==1)return [];
        $line=reset($lines);
        $id=(string)($line['lineItemId']??'');
        $code=ShipmentProductCode::normalize($raw['urunKodu']??null);
        return ctype_digit($id)&&$id!=='0'&&$code!==''?[$id=>$code]:[];
    }
    public static function map(array $raw): array
    {
        return self::mapWithCodes($raw,self::sellerStockCodes($raw));
    }
    private static function mapWithCodes(array $raw,array $sellerCodes): array
    {
        $text=static fn($value,$length=8000)=>DeliveryMapper::text($value,$length);
        $number=$text($raw['siparisNo']??'',180);$date=$raw['islemTarihi']??'';
        if($number===''||!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D',$date))throw new SyncFailure(422);
        try{$created=new \DateTimeImmutable($date,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        if(\DateTimeImmutable::getLastErrors()!==false)throw new SyncFailure(422);
        $name=trim($text($raw['musteriAdi']??'').' '.$text($raw['musteriSoyadi']??''));
        $invoice=trim($text($raw['faturaMusteriAdi']??'').' '.$text($raw['faturaMusteriSoyadi']??''));
        $type=$text($raw['faturaTip']??'');$office=$text($raw['vergiDaire']??'');
        $base=['orderedAt'=>$created->format(DATE_ATOM),'customerName'=>$name,'recipientName'=>$name,
            'phone'=>$text($raw['telefonNo']??'',60),'recipientEmail'=>$text($raw['eposta']??'',250),
            'deliveryAddress'=>$text($raw['siparisAdresi']??''),'province'=>DeliveryMapper::city($raw['siparisIli']??''),'district'=>$text($raw['siparisIlce']??'',100),
            'invoiceName'=>$type==='Kurumsal'?($text($raw['firmaUnvani']??'')?:$invoice):$invoice,
            'invoiceAddress'=>$text($raw['faturaAdresi']??'').($type==='Kurumsal'&&$office!==''?"\nV.D.: ".$office:''),
            'customerType'=>in_array($type,['Bireysel','Kurumsal'],true)?$type:'','taxNumber'=>$type==='Kurumsal'?$text($raw['vergiNo']??''):''];
        $products=[];$sourceProducts=[];$shipments=[];$cancelLines=[];$cancelProducts=[];$seen=[];$totals=SalesCalendar::ZERO;$states=[];
        foreach($raw['siparisUrunler']??[] as $line){
            $id=(string)($line['lineItemId']??'');$quantity=$line['toplamIslemAdedi']??null;
            if(!ctype_digit($id)||$id==='0'||isset($seen[$id])||!is_int($quantity)||$quantity<1||$quantity>10000)throw new SyncFailure(422);
            $seen[$id]=true;$rawStatus=(string)($line['siparisDurumu']??'');$state=self::shipping($rawStatus);$states[]=$state;
            $returned=in_array($rawStatus,['iade','gondericisine_teslim_edildi'],true);$cancelled=$state==='cancelled';
            $original=KoctasOrderMapper::cents($line['kdvDahilToplamTutar']??null);
            $discount=KoctasOrderMapper::cents($line['indirimToplam']??0);
            if($discount>$original)throw new SyncFailure(422);
            $gross=$original-$discount;
            $product=['lineId'=>$id,'title'=>$text($line['urun']??'',1000),'sku'=>$text($line['urunBarkod']??'',180),'quantity'=>$quantity,
                'grossCents'=>$gross,'cancelCents'=>$cancelled?$gross:0,'returnCents'=>$returned?$gross:0,
                'netCents'=>$cancelled||$returned?0:$gross,'netQuantity'=>$cancelled||$returned?0:$quantity,
                'cancelledQuantity'=>$cancelled?$quantity:0,'refundedQuantity'=>$returned?$quantity:0,'shippingState'=>$state,'status'=>$rawStatus,
                'pricing'=>PttavmPricing::line($line,$original,$discount)];
            $sourceId=(string)($line['urunId']??'');
            if(preg_match('/^[1-9][0-9]{0,19}$/D',$sourceId))$product['sourceProductId']=$sourceId;
            $sourceProducts[$id]=ShipmentSourceProduct::read('PTT AVM',$line);
            $products[]=$product;
            foreach(['grossCents','cancelCents','returnCents'] as $key)$totals[$key]+=$product[$key];
            $cancelLines[$id]=['quantity'=>$quantity,'cancelledQuantity'=>$product['cancelledQuantity'],'partial'=>false];
            if($cancelled)$cancelProducts[]=['lineId'=>$id,'title'=>$product['title'],'quantity'=>$quantity];
            // Awaiting payment is not a dispatch instruction. Returns remain excluded from dispatch.
            if(in_array($state,['pending','shipped','delivered','cancelled'],true)||$returned)$shipments[]=[
                'key'=>hash('sha256',json_encode([$number,$id],JSON_THROW_ON_ERROR)),
                'payload'=>[...$base,'orderNumber'=>$number,'productName'=>$product['title'],'sellerStockCode'=>$sellerCodes[$id]??'','quantity'=>$quantity,'invoiceCents'=>$gross,
                    'packageStatus'=>self::label($rawStatus),'marketplaceShipping'=>$state,'marketplaceRowStatus'=>$returned?'undelivered':$state,
                    'marketplaceCancellation'=>$cancelLines[$id],'marketplaceAmounts'=>ShipmentPaymentAmounts::pricing($product['pricing'])],
            ];
        }
        if(!$products)throw new SyncFailure(422);
        $activeProducts=array_values(array_filter($products,fn($p)=>in_array($p['shippingState'],['pending','shipped'],true)));
        $cancellation=OrderCancellation::combine($cancelLines);$state=count(array_unique($states))===1?$states[0]:'mixed';
        $status=count($products)===1?self::label($products[0]['status']):($state==='mixed'?'Kısmi işlem':ShipmentFulfillment::label($state));
        $delivery=DeliveryMapper::finish(['provider'=>'PTT AVM','externalId'=>$number,'orderNumber'=>$number,'buyerName'=>$name,
            'phone'=>$base['phone'],'address'=>$base['deliveryAddress'],'city'=>$base['province'],'district'=>$base['district'],
            'createdAt'=>$base['orderedAt'],'status'=>$status,'deliveryStatus'=>$status,'shippingState'=>$state,'active'=>(bool)$activeProducts,
            'products'=>array_map(fn($p)=>array_intersect_key($p,array_flip(['title','sku','quantity'])),$activeProducts),
            'cancellation'=>[...$cancellation,'lines'=>$cancelLines],'cancelledProducts'=>$cancelProducts]);
        $delivery['shipmentItems']=$shipments;
        $delivery['details']=[...$base,'status'=>$status,'trackingNumber'=>$text($raw['kargoBarkod']??'',180),
            'products'=>array_map(fn($p)=>['amountCents'=>$p['grossCents'],'sellerStockCode'=>$sellerCodes[$p['lineId']]??'',
                ...array_intersect_key($p,array_flip(['lineId','title','sku','quantity','cancelledQuantity','refundedQuantity','shippingState','status'])),
                ...$sourceProducts[$p['lineId']]],$products)];
        return ['order'=>['externalId'=>$number,'createdAt'=>$base['orderedAt'],'month'=>$created->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m'),
            ...$totals,'orders'=>1,'state'=>$status,'products'=>$products,'refundsVerified'=>false],'delivery'=>$delivery];
    }
    private static function label(string $status): string
    {
        return match($status){'iade','gondericisine_teslim_edildi'=>'İade edildi','havale_onayi_bekleniyor'=>'Ödeme bekleniyor','onay_surecinde'=>'Onay bekleniyor',default=>ShipmentFulfillment::label(self::shipping($status))};
    }
}
