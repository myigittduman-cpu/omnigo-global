<?php
declare(strict_types=1);
namespace Omnigo;

final class ReportPeriod
{
    public const HISTORY_START='2026-06-01';
    public static function range(string $from,string $to,bool $historyPreset=false): array
    {
        Validation::date($from);Validation::date($to);
        $zone=new \DateTimeZone('Europe/Istanbul');
        $start=new \DateTimeImmutable($from.' 00:00:00',$zone);$end=new \DateTimeImmutable($to.' 23:59:59',$zone);
        if($from>$to||($start->diff($end)->days>365&&(!$historyPreset||$from!==self::HISTORY_START))) Http::fail(400,'En fazla bir yıllık geçerli bir tarih aralığı seçin.');
        return [$start,$end];
    }
    public static function previous(string $from,string $to,bool $historyPreset=false): array
    {
        [$start,$end]=self::range($from,$to,$historyPreset);
        if($start->format('d')==='01'&&$start->format('Y-m')===$end->format('Y-m')) {
            $first=$start->modify('first day of previous month');
            $day=$end->format('d')===$end->format('t')?(int)$first->format('t'):min((int)$end->format('d'),(int)$first->format('t'));
            return [$first->format('Y-m-d'),$first->setDate((int)$first->format('Y'),(int)$first->format('m'),$day)->format('Y-m-d')];
        }
        $days=(int)$start->diff($end)->days+1;
        return [$start->modify('-'.$days.' days')->format('Y-m-d'),$start->modify('-1 day')->format('Y-m-d')];
    }
}
