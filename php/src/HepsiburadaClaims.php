<?php
declare(strict_types=1);
namespace Omnigo;

/** Claim creation/acceptance is not a payment. Only the documented Refunded amount is deducted. */
final class HepsiburadaClaims
{
    public const STATUSES=['NewRequest','AwaitingAction','InDispute','Accepted','Rejected','Refunded','Cancelled','AwaitingPreApproval'];
    /** Sending a missing invoice does not accept a physical product return. */
    public static function isInvoice(array $claim): bool
    {
        return strcasecmp(trim((string)($claim['claimType']??'')),'MissingInvoice')===0;
    }
    public static function reportsReturn(array $claim): bool
    {
        if(self::isInvoice($claim))return false;
        $replacement=strcasecmp(trim((string)($claim['claimType']??'')),'RenewProduct')===0;
        return !$replacement||($claim['status']??'')==='Refunded';
    }
    public static function date(mixed $value):\DateTimeImmutable
    {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D',$value))throw new SyncFailure(422);
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}catch(\Throwable){throw new SyncFailure(422);}
        if(\DateTimeImmutable::getLastErrors()!==false)throw new SyncFailure(422);
        return $date;
    }
    public static function map(array $raw,string $expectedStatus,string $merchant):array
    {
        if(($raw['status']??null)!==$expectedStatus||!in_array($expectedStatus,self::STATUSES,true))throw new SyncFailure(422);
        $owner=$raw['merchantId']??$raw['line']['merchantId']??$merchant;
        if(strtolower((string)$owner)!==$merchant)throw new SyncFailure(422);
        $number=(string)($raw['number']??'');$order=(string)($raw['orderNumber']??'');$line=(string)($raw['lineItemId']??'');
        if(!preg_match('/^\d{1,30}$/D',$number)||!preg_match('/^\d{1,30}$/D',$order)||!preg_match('/^[a-zA-Z0-9-]{1,80}$/D',$line))throw new SyncFailure(422);
        $quantity=$raw['quantity']??null;if(!is_int($quantity)||$quantity<1||$quantity>100000)throw new SyncFailure(422);
        $date=self::date($raw['orderDate']??null)->format(DATE_ATOM);$amount=0;$refundDate=null;
        if($expectedStatus==='Refunded'){
            if(($raw['refundCurrency']??null)!=='TRY'||($raw['finalizedWith']??'')==='Change')throw new SyncFailure(422);
            $amount=HepsiburadaMoney::cents($raw['refundAmount']??null);
            $refundDate=self::date($raw['refundDate']??null)->format(DATE_ATOM);
            if(strtotime($refundDate)<strtotime($date))throw new SyncFailure(422);
        }
        return ['id'=>$number,'orderNumber'=>$order,'lineId'=>$line,'orderedAt'=>$date,'quantity'=>$quantity,'status'=>$expectedStatus,'amountCents'=>$amount,'refundedAt'=>$refundDate,
            'claimType'=>is_string($raw['claimType']??null)?mb_substr($raw['claimType'],0,80):''];
    }
    public static function apply(array $order,array $claims):array
    {
        $byLine=[];$seen=[];
        foreach($claims as $claim){
            if($claim['status']!=='Refunded')continue;
            if(isset($seen[$claim['id']])){if($seen[$claim['id']]!==$claim)throw new SyncFailure(409);continue;}
            if($claim['orderNumber']!==$order['externalId']||SalesCalendar::day($claim['orderedAt'])!==SalesCalendar::day($order['createdAt']))throw new SyncFailure(422);
            $seen[$claim['id']]=$claim;$byLine[$claim['lineId']][]=$claim;
        }
        $order['returnCents']=0;$order['refundsVerified']=true;
        foreach($order['products'] as &$product){
            $refunds=$byLine[$product['lineId']]??[];unset($byLine[$product['lineId']]);
            $amount=array_sum(array_column($refunds,'amountCents'));$quantity=array_sum(array_column($refunds,'quantity'));
            if($amount>$product['grossCents']||$quantity>$product['quantity'])throw new SyncFailure(422);
            $fullyCancelled=$product['cancelCents']===$product['grossCents'];
            if(!$fullyCancelled&&($amount+$product['cancelCents']>$product['grossCents']||$quantity+($product['cancelledQuantity']??0)>$product['quantity']))throw new SyncFailure(422);
            // A cancelled line may also have a refund payment. Never deduct that money twice.
            $product['returnCents']=min($amount,$product['grossCents']-$product['cancelCents']);
            $product['netCents']=$product['grossCents']-$product['cancelCents']-$product['returnCents'];
            $product['netQuantity']=$fullyCancelled?0:max(0,$product['quantity']-$quantity-($product['cancelledQuantity']??0));
            $order['returnCents']+=$product['returnCents'];
        }unset($product);
        if($byLine)throw new SyncFailure(422);
        return $order;
    }
}
