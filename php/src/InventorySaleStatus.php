<?php
declare(strict_types=1);
namespace Omnigo;

/** Sale eligibility is independent of stock. Unknown source states are never assumed live. */
final class InventorySaleStatus
{
    public static function fromSource(string $provider, array $row, ?int $partition = null): ?bool
    {
        return match ($provider) {
            'hepsiburada' => self::hepsiburada($row),
            'n11' => self::n11($row),
            'koctas' => self::flag($row['active'] ?? null),
            // PTT's four source queries explicitly partition active/inactive listings.
            'pttavm' => $partition !== null && $partition >= 0 && $partition < 4 ? $partition < 2 : null,
            // Approval and catalog matching do not prove that an offer is on sale.
            'pazarama', 'idefix' => null,
            default => null,
        };
    }

    private static function flag(mixed $value): ?bool
    {
        return match ($value) { true, 1, '1', 'true' => true, false, 0, '0', 'false' => false, default => null };
    }

    private static function hepsiburada(array $row): ?bool
    {
        foreach (['isSuspended', 'isLocked', 'isFrozen'] as $field) {
            if (self::flag($row[$field] ?? null) === true) return false;
        }
        if (!empty($row['deactivationReasons'])) return false;
        return self::flag($row['isSalable'] ?? null);
    }

    private static function n11(array $row): ?bool
    {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $sale = strtolower(trim((string)($row['saleStatus'] ?? '')));
        if ($status !== '' && $status !== 'active') return false;
        if ($sale !== '') return $status === 'active' && $sale === 'on_sale';
        return null;
    }
}
