<?php
declare(strict_types=1);
namespace Omnigo;

final class PaymentPeriods
{
    private const DAYS = 15;

    public static function containing(string $anchor, string $date): array
    {
        $origin = new \DateTimeImmutable($anchor, new \DateTimeZone('Europe/Istanbul'));
        $target = new \DateTimeImmutable($date, $origin->getTimezone());
        $distance = (int)$origin->diff($target)->format('%r%a');
        $offset = (int)floor($distance / self::DAYS) * self::DAYS;
        $start = $origin->modify(sprintf('%+d days', $offset));
        return ['start'=>$start->format('Y-m-d'), 'end'=>$start->modify('+14 days')->format('Y-m-d')];
    }

    public static function schedule(array $forecastDates, array $paymentDates, string $from, string $to,
        string $today, string $selected): array
    {
        $dates = array_values(array_unique([...$forecastDates, ...$paymentDates]));
        sort($dates);
        sort($forecastDates);
        $focus = $today >= $from && $today <= $to ? $today : $from;
        if ($focus === $from && $today !== $from) {
            foreach ($dates as $date) {
                if ($date >= $from && $date <= $to) { $focus = $date; break; }
            }
        }
        $anchor = $selected ?: ($forecastDates[0] ?? $today);
        $current = self::containing($anchor, $selected ?: $focus);
        $periods = [$current['start']=>$current];
        foreach ($dates as $date) {
            $period = self::containing($anchor, $date);
            $periods[$period['start']] = $period;
        }
        ksort($periods);
        return [$current, array_values($periods)];
    }
}
