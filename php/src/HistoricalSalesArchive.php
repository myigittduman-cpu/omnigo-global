<?php
declare(strict_types=1);
namespace Omnigo;

/** Immutable marketplace financial history, scoped to the verified store and reporting. */
final class HistoricalSalesArchive
{
    private array $archives = [];

    public function __construct(
        private Database $db,
        private string $path = __DIR__.'/../storage/historical-sales-2026.json'
    ) {}

    public function snapshot(int $company,string $provider,\DateTimeImmutable $start,\DateTimeImmutable $end): ?array
    {
        $archive = $this->archives[$company] ??= $this->load($company);

        $snapshot = $archive['providers'][$provider] ?? null;
        if ($snapshot === null) return null;

        try {
            $from = new \DateTimeImmutable($snapshot['from']);
            $until = new \DateTimeImmutable($snapshot['untilExclusive']);
        } catch (\Exception $error) {
            error_log('Doğrulanmış satış arşivinin tarih aralığı okunamadı.');
            return null;
        }
        if ($start >= $until || $end < $from) return null;

        return [...$snapshot,'from'=>$from,'untilExclusive'=>$until];
    }

    private function load(int $company): array
    {
        $scoped = dirname($this->path).'/'.pathinfo($this->path, PATHINFO_FILENAME).'.company-'.$company.'.json';
        $path = is_file($scoped) ? $scoped : $this->path;
        if (!is_file($path)) return [];
        try {
            $archive = json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if (($archive['version'] ?? null) !== 1 || ($archive['companyId'] ?? null) !== $company
                || !is_string($archive['companyName'] ?? null) || !is_array($archive['providers'] ?? null)) return [];

            $row = $this->db->one('SELECT name FROM companies WHERE id=?',[$company]);
            return ($row['name'] ?? null) === $archive['companyName'] ? $archive : [];
        } catch (\JsonException|\TypeError) {
            error_log('Doğrulanmış satış arşivi okunamadı.');
            return [];
        }
    }
}
