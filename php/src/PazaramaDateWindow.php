<?php
declare(strict_types=1);
namespace Omnigo;

/** Pazarama includes both calendar dates, even when endDate contains 00:00. */
final class PazaramaDateWindow
{
    public static function scanEnd(\DateTimeImmutable $now): string
    {
        $end=$now->modify('+1 minute');
        // A midnight end can equal the next calendar cursor. Keep a valid terminal window.
        if($end->format('H:i')==='00:00')$end=$end->modify('+1 minute');
        return $end->format('Y-m-d\TH:i');
    }
    public static function exhausted(array $scan): bool
    {
        return isset($scan['cursor'],$scan['end'])&&$scan['cursor']>=$scan['end'];
    }
    public static function end(string $cursor,string $through): string
    {
        $day=(new \DateTimeImmutable($cursor,new \DateTimeZone('Europe/Istanbul')))->setTime(0,0);
        return min($through,$day->modify('+13 days')->setTime(23,59)->format('Y-m-d\TH:i'));
    }
    public static function next(string $end): string
    {
        return (new \DateTimeImmutable($end,new \DateTimeZone('Europe/Istanbul')))->modify('+1 day')->setTime(0,0)->format('Y-m-d\TH:i');
    }
}
