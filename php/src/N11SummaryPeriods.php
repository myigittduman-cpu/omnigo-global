<?php
declare(strict_types=1);
namespace Omnigo;

/** n11's seller summaries include both the starting date and today. */
final class N11SummaryPeriods
{
    public static function window(array $orders,string $period,\DateTimeImmutable $clock,\DateTimeImmutable $from,\DateTimeImmutable $through):array
    {
        $clock=$clock->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        $today=$clock->setTime(0,0);
        if($period==='seven')$start=$today->modify('-7 days');
        elseif($period==='thirty'){
            $previous=$today->modify('first day of previous month');
            $start=$previous->setDate((int)$previous->format('Y'),(int)$previous->format('m'),min((int)$today->format('d'),(int)$previous->format('t')));
        }else throw new \InvalidArgumentException('Geçersiz n11 satış dönemi.');
        $days=(int)$start->diff($today)->format('%a')+1;
        return SalesSummaryPeriods::rolling($orders,$days,$clock,$from,$through);
    }
}
