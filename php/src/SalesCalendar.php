<?php
declare(strict_types=1);
namespace Omnigo;

final class SalesCalendar
{
    public const ZERO=['grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'orders'=>0];
    /** Calendar months touched by a source interval, always in the reporting timezone. */
    public static function months(string $from,string $through): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');
        $start=(new \DateTimeImmutable($from))->setTimezone($zone)->modify('first day of this month')->setTime(0,0);
        $end=(new \DateTimeImmutable($through))->setTimezone($zone);$months=[];
        for($date=$start;$date<=$end;$date=$date->modify('first day of next month'))$months[]=$date->format('Y-m');
        return $months;
    }
    public static function day(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
    }
    public static function add(array &$totals,array $order): void
    {
        foreach (self::ZERO as $key=>$unused) $totals[$key]+=$order[$key];
    }
    public static function today(array $sales,?\DateTimeImmutable $clock=null): array
    {
        $today=($clock??new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
        $channels=[];
        foreach ($sales as $sale) {
            if ($sale['month']!==substr($today,0,7)||!isset($sale['dailySales'])) continue;
            $snapshot=$sale['dailySales'];
            $dayStart=new \DateTimeImmutable($today.' 00:00:00',new \DateTimeZone('Europe/Istanbul'));
            $covered=new \DateTimeImmutable($snapshot['through'])>=$dayStart && new \DateTimeImmutable($snapshot['from'])<=$dayStart;
            $totals=$covered?($snapshot['days'][$today]??self::ZERO):null;
            $channels[]=['channel'=>$sale['channel'],'available'=>$covered,'netCents'=>$totals===null?null:$totals['grossCents']-$totals['cancelCents']-$totals['returnCents'],'orders'=>$totals['orders']??null,'asOf'=>$snapshot['through']];
        }
        return ['date'=>$today,'channels'=>$channels];
    }
}
