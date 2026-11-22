<?php
declare(strict_types=1);
namespace Omnigo;

/** Reported line earnings are not evidence of a payout or an unpaid balance. */
final class IdefixFinanceMapper
{
    public static function amount(array $line): ?int
    {
        if(!isset($line['earningAmount']))return null;
        try{return KoctasOrderMapper::cents($line['earningAmount']);}catch(\Throwable){return null;}
    }
    public static function entries(array $orders): array
    {
        $entries=[];$seen=[];
        foreach($orders as $order){
            if(empty($order['refundsVerified']))throw new SyncFailure(409,60);
            foreach($order['products']??[] as $line){
                $key=hash('sha256',$order['externalId'].':'.$line['lineId']);
                if(isset($seen[$key]))throw new SyncFailure(422);$seen[$key]=true;
                if(($line['cancelCents']??0)>0||($line['returnCents']??0)>0||in_array($line['status']??'',['cancel_payment_refunded','return_payment_refund'],true))continue;
                if(!is_int($line['earningCents']??null)||$line['earningCents']<0)throw new SyncFailure(409,60);
                $entries[]=['key'=>$key,'transactionDate'=>FinanceMapper::date($order['createdAt']),'expectedDate'=>'','netCents'=>$line['earningCents'],'state'=>'earnings'];
            }
        }
        return $entries;
    }
}
