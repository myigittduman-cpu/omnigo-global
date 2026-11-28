<?php
declare(strict_types=1);
namespace Omnigo;

/** A bounded view of confirmed and estimated upcoming payments. */
final class UpcomingPayments
{
    private const PER_MARKETPLACE_LIMIT = 4;

    public static function report(array $payments, array $channels, string $today, array $official = [], array $panelCoverage = []): array
    {
        return self::projection(self::snapshot($payments, $channels, $today, $official, $panelCoverage),$channels,$today);
    }

    public static function projection(array $snapshot,array $channels,string $today): array
    {
        $items = [];
        $counts = [];
        $confirmedCents = 0;
        $estimatedCents = 0;
        foreach ($snapshot['items'] as $item) {
            if(PaymentApproval::isApproved($item))continue;
            $channel = $item['channel'];
            if ($item['date'] < $today || $item['amountCents'] <= 0 || !in_array($channel,$channels,true)) continue;
            $visible = $counts[$channel] ?? 0;
            if ($visible >= self::PER_MARKETPLACE_LIMIT) continue;
            $items[] = $item;
            $counts[$channel] = $visible + 1;
            if ($item['verification'] === 'confirmed') $confirmedCents += $item['amountCents'];
            else $estimatedCents += $item['amountCents'];
        }
        return [
            'items' => $items,
            'remainingCents' => $confirmedCents+$estimatedCents,
            'confirmedCents' => $confirmedCents,
            'estimatedCents' => $estimatedCents,
            'paidThisMonthCents' => $snapshot['paidThisMonthCents'],
            'today' => $today,
            'channelsWithoutData' => array_values(array_diff($channels, array_keys($counts))),
        ];
    }

    public static function snapshot(array $payments, array $channels, string $today, array $official = [], array $panelCoverage = []): array
    {
        $monthStart = substr($today, 0, 7).'-01';
        $paidThisMonthCents = 0;
        $manual = [];
        $settled = [];
        foreach ($payments as $payment) {
            if (!in_array($payment['channel'] ?? null, $channels, true)
                || !empty($payment['integration']) || !empty($payment['archivedAt'])
                || !empty($payment['paymentStateUnknown']) || !empty($payment['excludedFromTotals'])) {
                continue;
            }
            $status = $payment['status'] ?? '';
            if ($status === 'paid' && PaymentApproval::isApproved($payment)) {
                $date = $payment['paidDate'] ?? '';
                $amount = $payment['actualCents'] ?? null;
                if (self::validDate($date) && $date >= $monthStart && $date <= $today
                    && is_int($amount) && $amount >= 0) {
                    $paidThisMonthCents += $amount;
                }
            }
            $date = $payment['expectedDate'] ?? '';
            $amount = $payment['expectedCents'] ?? null;
            if (in_array($status, ['paid','sent'], true) && self::validDate($date)) {
                $settled[$payment['channel']][$date] = true;
            }
            if ($status !== 'pending' || !self::validDate($date) || $date < $today || !is_int($amount) || $amount <= 0) {
                continue;
            }
            $verification = ($payment['verification'] ?? '') === 'confirmed'
                && trim((string)($payment['verificationSource'] ?? '')) !== '' ? 'confirmed' : 'estimated';
            $entered = ($payment['source'] ?? '') === UpcomingManualPayout::SOURCE && $verification === 'estimated';
            $through = $panelCoverage[$payment['channel']] ?? null;
            if (!$entered && $verification === 'estimated' && self::validDate($through) && $date <= $through) continue;
            $manual[$payment['channel']][$date][$entered?'entered':$verification][] = [
                'id' => (int)$payment['id'],
                'channel' => $payment['channel'],
                'date' => $date,
                'amountCents' => $amount,
                'verification' => $verification,
                'source' => 'manual',
                ...($entered ? ['manualEntry'=>true] : []),
            ];
        }
        $reported = [];
        foreach ($official as $payment) {
            $channel = $payment['channel'] ?? null;
            $date = $payment['date'] ?? null;
            $amount = $payment['amountCents'] ?? null;
            $verification = $payment['verification'] ?? null;
            $source = $payment['source'] ?? null;
            if (!in_array($channel,$channels,true) || !self::validDate($date) || $date < $today
                || !is_int($amount) || $amount < 0 || isset($settled[$channel][$date])
                || !in_array($verification,['confirmed','estimated'],true)
                || !in_array($source,['marketplace','marketplace_panel'],true)
                || ($amount === 0 && ($source !== 'marketplace' || !is_int($payment['observedAt'] ?? null)))) continue;
            $reported[$channel][$date][$verification][$source] = [
                'id'=>(string)$payment['id'], 'channel'=>$channel, 'date'=>$date, 'amountCents'=>$amount,
                'verification'=>$verification, 'source'=>$source,
            ];
            if ($source === 'marketplace_panel' && self::validDate($payment['observedDate'] ?? null)) {
                $reported[$channel][$date][$verification][$source]['observedDate'] = $payment['observedDate'];
            }
            if (is_int($payment['observedAt'] ?? null)) {
                $reported[$channel][$date][$verification][$source]['observedAt'] = $payment['observedAt'];
            }
            if (is_string($payment['sourceConnection'] ?? null)) {
                $reported[$channel][$date][$verification][$source]['sourceConnection'] = $payment['sourceConnection'];
            }
        }
        $items = self::combine($manual,$reported);
        usort($items, static fn(array $a, array $b): int =>
            [$a['date'], $a['channel'], $a['id']] <=> [$b['date'], $b['channel'], $b['id']]);
        $settledDays = [];
        foreach ($settled as $channel => $dates) {
            foreach (array_keys($dates) as $date) $settledDays[] = compact('channel','date');
        }
        return [
            'items' => $items,
            'paidThisMonthCents' => $paidThisMonthCents,
            'today' => $today,
            'settled' => $settledDays,
        ];
    }

    private static function combine(array $manual, array $reported): array
    {
        $candidates = [];
        foreach (array_unique([...array_keys($manual),...array_keys($reported)]) as $channel) {
            $localDays = $manual[$channel] ?? [];
            $reportedDays = $reported[$channel] ?? [];
            foreach (array_unique([...array_keys($localDays),...array_keys($reportedDays)]) as $day) {
                $local = $localDays[$day] ?? [];
                $source = $reportedDays[$day] ?? [];
                if ($local['confirmed'] ?? []) {
                    array_push($candidates,...$local['confirmed']);
                    continue;
                }
                $official = $source['confirmed']['marketplace'] ?? $source['estimated']['marketplace']
                    ?? $source['confirmed']['marketplace_panel']
                    ?? $local['entered'][0] ?? $source['estimated']['marketplace_panel'] ?? null;
                if ($official) $candidates[] = $official;
                else array_push($candidates,...($local['estimated'] ?? []));
            }
        }
        return $candidates;
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
