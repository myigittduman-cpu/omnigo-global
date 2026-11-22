<?php
declare(strict_types=1);
namespace Omnigo;

/** Read dated allowance movements without inferring a transfer date from the order date. */
final class PazaramaUpcomingWindow
{
    public static function read(array $data, string $from, string $to): array
    {
        $rows = $data['transactionList'];
        if (count($rows) >= 10000) throw new SyncFailure(422);
        $entries = [];
        foreach ($rows as $raw) {
            if (!is_array($raw)) throw new SyncFailure(422);
            $entry = FinanceMapper::pazarama($raw);
            $date = $entry['expectedDate'];
            if ($date === '' || $date < $from || $date > $to) throw new SyncFailure(422);
            $calendar = date_parse($raw['transferredDate']);
            if ($calendar['warning_count'] || $calendar['error_count']) throw new SyncFailure(422);
            $status = mb_strtolower(trim((string)$raw['status']),'UTF-8');
            if (str_contains($status,'ödendi') || str_contains($status,'aktarıldı')) $entry['state'] = 'sent';
            if (isset($entries[$entry['key']])) throw new SyncFailure(409);
            $entries[$entry['key']] = $entry;
        }
        return ['entries'=>array_values($entries),'count'=>count($rows),'cursor'=>null];
    }
}
