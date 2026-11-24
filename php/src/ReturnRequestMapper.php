<?php
declare(strict_types=1);
namespace Omnigo;

/** Display projection only. These values never enter sales/refund calculations. */
final class ReturnRequestMapper
{
    public static function text(mixed $value,int $limit=180): string
    {
        return is_scalar($value)?mb_substr(trim((string)$value),0,$limit):'';
    }
    public static function skus(array $products): array
    {
        $codes=array_map(static fn(array $product)=>self::text($product['sku']??null),$products);
        return array_values(array_unique(array_filter($codes,static fn(string $code)=>$code!=='')));
    }
    public static function date(mixed $value): ?string
    {
        if(!is_string($value)||$value==='')return null;
        try{
            $zone=new \DateTimeZone('Europe/Istanbul');
            if(preg_match('/^\d{2}\/\d{2}\/\d{4}(?: \d{2}:\d{2}:\d{2})?$/D',$value))$date=\DateTimeImmutable::createFromFormat(strlen($value)===10?'!d/m/Y':'!d/m/Y H:i:s',$value,$zone);
            elseif(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',$value))$date=new \DateTimeImmutable($value,$zone);
            else return null;
            return $date&&\DateTimeImmutable::getLastErrors()===false?$date->format(DATE_ATOM):null;
        }catch(\Throwable){return null;}
    }
    public static function n11(array $claim): array
    {
        return ['id'=>(string)$claim['claimReturnId'],'orderNumber'=>$claim['orderNumber'],'lineId'=>$claim['productId'],
            'status'=>$claim['status'],'createdAt'=>self::date($claim['requestDate']??null),'dateOnly'=>strlen($claim['requestDate']??'')===10,'quantity'=>$claim['quantity'],
            'amountCents'=>$claim['amountCents'],'product'=>'','reason'=>''];
    }
    public static function hepsiburada(array $claim): array
    {
        return ['id'=>$claim['id'],'orderNumber'=>$claim['orderNumber'],'lineId'=>$claim['lineId'],'status'=>$claim['status'],
            'createdAt'=>null,'orderedAt'=>$claim['orderedAt'],'quantity'=>$claim['quantity'],'claimType'=>$claim['claimType']??null,
            'amountCents'=>$claim['status']==='Refunded'?$claim['amountCents']:null,'product'=>'','reason'=>''];
    }
    public static function idefix(array $claim,array $line): array
    {
        // Customer identity, address and free-text notes stay in the encrypted order store.
        $amount=null;try{if(isset($line['discountedTotalPrice']))$amount=KoctasOrderMapper::cents($line['discountedTotalPrice']);}catch(\Throwable){}
        return ['id'=>self::text($line['id']??''),'claimId'=>self::text($claim['id']??''),'orderNumber'=>self::text($claim['orderNumber']??''),
            'lineId'=>self::text($line['orderLineId']??''),'status'=>self::text($line['state']??''),'statusLabel'=>self::text($line['stateName']??''),
            'createdAt'=>self::date($claim['createdAt']??null),'updatedAt'=>self::date($line['stateUpdatedAt']??null),'quantity'=>null,
            'amountCents'=>$amount,'product'=>self::text($line['productName']??'',500),'reason'=>self::text($line['customerReason']??$line['vendorReason']??'',300)];
    }
    public static function status(string $provider,string $status): array
    {
        $maps=[
            'trendyol'=>[
                'Returned'=>['approved','İade edildi (sipariş durumu)'],
                'Created'=>['pending','Yeni talep'],'WaitingInAction'=>['pending','İşlem bekliyor'],
                'WaitingFraudCheck'=>['pending','Güvenlik kontrolü bekliyor'],'Unresolved'=>['pending','İtiraz sürecinde'],
                'InAnalysis'=>['pending','İnceleniyor'],'Accepted'=>['approved','Kabul edildi'],
                'Cancelled'=>['cancelled','Talep iptal edildi'],'Rejected'=>['rejected','Reddedildi'],
            ],
            'pttavm'=>['iade'=>['approved','İade edildi (sipariş durumu)'],'gondericisine_teslim_edildi'=>['approved','Göndericiye teslim edildi']],
            'hepsiburada'=>['NewRequest'=>['pending','Yeni talep'],'AwaitingAction'=>['pending','İşlem bekliyor'],'InDispute'=>['pending','İtiraz sürecinde'],'Accepted'=>['approved','Kabul edildi'],'Rejected'=>['rejected','Reddedildi'],'Refunded'=>['refunded','Geri ödeme tamamlandı'],'Cancelled'=>['cancelled','Talep iptal edildi'],'AwaitingPreApproval'=>['pending','Ön onay bekliyor']],
            // n11 uses CANCELLED for both withdrawn claims and approved rejection requests.
            'n11'=>['REQUESTED'=>['pending','Yeni talep'],'APPROVAL_WAITING'=>['pending','Onay bekliyor'],'CANCELLED'=>['cancelled','İade talebi kapandı (iptal / ret)'],'DENIED'=>['rejected','Reddedildi'],'PENDING'=>['pending','Beklemede'],'PENDED'=>['pending','Ertelendi'],'APPROVED'=>['approved','Onaylandı'],'MANUAL_REFUND'=>['refunded','Manuel geri ödeme']],
            'idefix'=>['ready'=>['pending','Yeni talep'],'in_cargo'=>['pending','İade kargoda'],'waiting_vendor_approve'=>['pending','Satıcı onayı bekliyor'],'approved'=>['approved','Onaylandı'],'decline'=>['rejected','Reddedildi'],'cancel'=>['cancelled','Talep iptal edildi'],'vendor_decline_request'=>['pending','Ret talebi inceleniyor']],
            'pazarama'=>['7'=>['pending','İade süreci başlatıldı'],'8'=>['approved','İade onaylandı'],'9'=>['rejected','İade reddedildi'],'10'=>['refunded','İade edildi']],
            'koctas'=>['WAITING_ACCEPTANCE'=>['pending','Kabul bekliyor'],'IN_PROGRESS'=>['pending','İade sürecinde'],'RECEIVED'=>['pending','İade teslim alındı'],'REFUSED'=>['rejected','İade reddedildi'],'CANCELED'=>['cancelled','Talep iptal edildi'],'CLOSED'=>['unknown','İade süreci kapatıldı (ödeme teyidi değildir)']],
        ];
        return $maps[$provider][$status]??['unknown','Kaynak durumu: '.self::text($status)];
    }
}
