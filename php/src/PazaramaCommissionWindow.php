<?php
declare(strict_types=1);
namespace Omnigo;

/** Reconcile signed commission movements against the official response total. */
final class PazaramaCommissionWindow
{
    public static function read(array $agreement, string $from, string $to): array
    {
        $total = self::cents($agreement['totalCommission'] ?? null);
        $days = $seen = [];
        $sum = 0;
        $rows = $agreement['transactionList'];
        // A full limit can represent a truncated response; preserve the last checked snapshot.
        if (count($rows) >= 10000) throw new SyncFailure(422);
        foreach ($rows as $raw) {
            if (!is_array($raw)) throw new SyncFailure(422);
            $stamp = $raw['transactionDate'] ?? null;
            if (!is_string($stamp)) throw new SyncFailure(422);
            $calendar = date_parse($stamp);
            if ($calendar['error_count'] || $calendar['warning_count']) throw new SyncFailure(422);
            $movement = FinanceMapper::pazarama($raw);
            $key = $movement['key'];
            $date = $movement['transactionDate'];
            if ($date < $from || $date > $to || isset($seen[$key])) throw new SyncFailure(422);
            $seen[$key] = true;
            $signed = self::cents($raw['commissionAmount'] ?? null);
            $sum += $signed;
            $days[$date] = ($days[$date] ?? 0) - $signed;
        }
        return ['days'=>$days, 'reconciled'=>$sum === $total];
    }

    private static function cents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', (string)$value, $parts)) {
            throw new SyncFailure(422);
        }
        $cents = (int)$parts[2] * 100 + (int)str_pad($parts[3] ?? '', 2, '0');
        return $parts[1] === '-' ? -$cents : $cents;
    }
}
