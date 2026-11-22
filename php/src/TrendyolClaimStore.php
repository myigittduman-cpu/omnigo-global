<?php
declare(strict_types=1);
namespace Omnigo;

/** The portal only uses a complete scan from the current connection. */
final class TrendyolClaimStore
{
    public const KEY = 'trendyol_claims';
    public function __construct(private Database $db) {}

    public function connection(int $company): ?string
    {
        $row=$this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='integration_trendyol'",[$company]);
        return $row ? $row['id'].':'.$row['version'] : null;
    }

    public function read(int $company): array
    {
        if (!PazaramaCommissionStore::available($this->db)) return [];
        $connection = $this->connection($company);
        if (!$connection) return [];
        $row = (new FinanceStore($this->db))->state($company, self::KEY);
        $state = json_decode($row['payload'] ?? '{}', true, 56, JSON_THROW_ON_ERROR);
        return ($state['connection'] ?? null) === $connection ? $state : [];
    }
}
