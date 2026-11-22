<?php
declare(strict_types=1);
namespace Omnigo;

/** Dedicated commission snapshot in the existing finance store. */
final class PazaramaCommissionStore
{
    public const PROVIDER = 'pazarama_commission';

    public function __construct(private Database $db) {}

    public static function available(Database $db): bool
    {
        return (bool)($db->mysql()
            ? $db->one("SHOW TABLES LIKE 'finance_sync'")
            : $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='finance_sync'"));
    }

    public function sourceVersion(int $company): ?string
    {
        $row = $this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='integration_pazarama'", [$company]);
        return $row ? $row['id'].':'.$row['version'] : null;
    }

    public function report(int $company, string $from, string $to): array
    {
        $empty = ['coverage'=>'unavailable','items'=>[],'coverageFrom'=>null,'coverageTo'=>null,'updatedAt'=>null];
        if (!self::available($this->db)) return $empty;
        $row = (new FinanceStore($this->db))->state($company, self::PROVIDER);
        $snapshot = json_decode($row['payload'] ?? '{}', true)['published'] ?? null;
        if (!$snapshot || $snapshot['sourceVersion'] !== $this->sourceVersion($company)) return $empty;
        $start = $snapshot['from'];
        $end = $snapshot['to'];
        if ($to < $start || $from > $end) return $empty;
        $reconciled = true;
        foreach ($snapshot['unreconciled'] ?? [] as $window) {
            if ($window['from'] <= $to && $window['to'] >= $from) $reconciled = false;
        }
        $items = [];
        foreach ($snapshot['days'] as $day => $amount) {
            if ($day < $from || $day > $to) continue;
            $dayReconciled = true;
            foreach ($snapshot['unreconciled'] ?? [] as $window) {
                if ($window['from'] <= $day && $window['to'] >= $day) $dayReconciled = false;
            }
            $items[] = ['id'=>'pazarama-commission-'.$day,'date'=>$day,'amountCents'=>$amount,
                'taxBasis'=>'unspecified','reconciled'=>$dayReconciled];
        }
        return [
            'coverage'=>$reconciled && $from >= $start && $to <= $end ? 'complete' : 'partial',
            'reconciled'=>$reconciled,
            'items'=>$items,'coverageFrom'=>$start,'coverageTo'=>$end,'updatedAt'=>$snapshot['updatedAt'],
        ];
    }
}
