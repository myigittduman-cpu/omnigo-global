<?php
declare(strict_types=1);
namespace Omnigo;

/** Published snapshots read from authenticated seller panels. */
final class UpcomingPanelPayoutStore
{
    public function __construct(
        private Database $db,
        private string $path = __DIR__.'/../storage/upcoming-panel-payouts.json',
    ) {}

    public function snapshot(int $company, array $channels, string $today, bool $includeAll = false): array
    {
        $empty = ['rows'=>[], 'coverage'=>[]];
        if (!is_file($this->path)) return $empty;
        try {
            $capture = json_decode(file_get_contents($this->path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException|\TypeError) {
            error_log('Pazaryeri ödeme takvimi kaydı okunamadı.');
            return $empty;
        }
        if (($capture['version'] ?? null) !== 1 || ($capture['companyId'] ?? null) !== $company
            || !is_string($capture['companyName'] ?? null) || !is_array($capture['channels'] ?? null)) return $empty;
        $store = $this->db->one('SELECT name FROM companies WHERE id=?', [$company]);
        if (($store['name'] ?? null) !== $capture['companyName']) return $empty;

        $rows = [];
        $coverage = [];
        foreach ($capture['channels'] as $channel => $source) {
            $observed = $source['observedDate'] ?? null;
            if (!in_array($channel, $channels, true) || !self::validDate($observed) || $observed > $today
                || !is_array($source['payments'] ?? null)) continue;
            $payments = [];
            foreach ($source['payments'] as $day => $amount) {
                if (!self::validDate($day) || $day < $today || $day < $observed || !is_int($amount) || $amount <= 0) continue;
                $payments[$day] = $amount;
            }
            ksort($payments);
            foreach ($includeAll ? $payments : array_slice($payments, 0, 4, true) as $day => $amount) {
                $rows[] = ['id'=>'panel:'.$channel.':'.$day, 'channel'=>$channel, 'date'=>$day,
                    'amountCents'=>$amount, 'verification'=>'estimated', 'source'=>'marketplace_panel', 'observedDate'=>$observed];
                $coverage[$channel] = $day;
            }
        }
        return compact('rows','coverage');
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
