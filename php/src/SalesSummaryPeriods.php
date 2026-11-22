<?php
declare(strict_types=1);
namespace Omnigo;

/** Calendar windows in Istanbul time; unknown coverage is never turned into zero. */
final class SalesSummaryPeriods
{
    public static function window(array $orders,\DateTimeImmutable $start,\DateTimeImmutable $end,\DateTimeImmutable $coverageStart,\DateTimeImmutable $coverageEnd): array
    {
        $available=$coverageEnd>=$start&&$coverageStart<=$end;
        $value=['available'=>$available,'partial'=>$coverageStart>$start||$coverageEnd->format('Y-m-d')<$end->format('Y-m-d'),'from'=>$start->format('Y-m-d'),'to'=>$end->format('Y-m-d'),...SalesCalendar::ZERO,...SourceSalesAmounts::ZERO,'quantity'=>0,'salesQuantity'=>0];
        $value['operationalCancelCents']=0;
        $value+=TurnoverAmounts::ZERO;
        $value['salesOrders']=0;
        $value['orderedQuantity']=0;
        foreach($orders as $order){
            if($order['at']<$start||$order['at']>$end||$order['at']<$coverageStart||$order['at']>$coverageEnd)continue;
            SalesCalendar::add($value,$order);
            $value['salesOrders']+=$order['salesOrders']??$order['orders'];
            $value['operationalCancelCents']+=$order['operationalCancelCents']??0;
            SourceSalesAmounts::add($value,$order);
            TurnoverAmounts::add($value,$order);
            $value['quantity']=$value['quantity']===null||$order['quantity']===null?null:$value['quantity']+$order['quantity'];
            $value['salesQuantity']=$value['salesQuantity']===null||($order['salesQuantity']??null)===null?null:$value['salesQuantity']+$order['salesQuantity'];
            $value['orderedQuantity']=SalesUnitTotals::add($value['orderedQuantity'],$order['orderedQuantity']??null);
        }
        $value['amountCents']=$value['grossCents']-$value['cancelCents']-$value['returnCents'];
        $value['salesCents']=$value['grossCents']-$value['cancelCents'];
        return $value;
    }
    public static function rolling(array $orders,int $days,\DateTimeImmutable $clock,\DateTimeImmutable $from,\DateTimeImmutable $through): array
    {
        $start=$clock->setTime(0,0)->modify('-'.($days-1).' days');
        $current=self::window($orders,$start,$clock,$from,$through);
        $priorEnd=min($clock,$through)->modify("-$days days");
        $previous=self::window($orders,$start->modify("-$days days"),$priorEnd,$from,$through);
        $current['previous']=$current['available']&&!$current['partial']&&$previous['available']&&!$previous['partial']?$previous:null;
        return $current;
    }
    /** Seller dashboard periods exclude today for the seven/thirty-day totals. */
    public static function completed(array $orders,int $days,\DateTimeImmutable $clock,\DateTimeImmutable $from,\DateTimeImmutable $through):array
    {
        $end=$clock->setTime(0,0)->modify('-1 second');$start=$clock->setTime(0,0)->modify("-$days days");
        $current=self::window($orders,$start,$end,$from,$through);
        $previous=self::window($orders,$start->modify("-$days days"),$start->modify('-1 second'),$from,$through);
        $current['previous']=$current['available']&&!$current['partial']&&$previous['available']&&!$previous['partial']?$previous:null;
        return $current;
    }
}
