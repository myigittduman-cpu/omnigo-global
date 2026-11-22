<?php
declare(strict_types=1);
namespace Omnigo;

/** Presentation policy only. Stored records and integration workers retain the full catalog. */
final class MarketplaceVisibility
{
    public const HIDDEN = ['teknosa'];

    public static function visible(string $provider): bool
    {
        $key = str_replace([' ', '_'], '', mb_strtolower(trim($provider)));
        return !in_array($key, self::HIDDEN, true);
    }

    public static function rows(array $rows, string $field): array
    {
        return array_values(array_filter($rows, fn($row) => self::visible((string)($row[$field] ?? ''))));
    }

    public static function requireVisible(string $provider): void
    {
        if (!self::visible($provider)) Http::fail(404, 'Bu pazaryeri geçici olarak gizlenmiştir.');
    }

    /** Apply before counts and pagination. Only internal SQL identifiers are accepted. */
    public static function sql(string $column = 'provider'): string
    {
        if (!preg_match('/^[a-z_]+(?:\.[a-z_]+)?$/D', $column)) throw new \InvalidArgumentException('Geçersiz sütun.');
        if (!self::HIDDEN) return '1=1';
        $values = implode(',', array_map(fn($key) => "'".str_replace("'", "''", $key)."'", self::HIDDEN));
        return "LOWER(REPLACE(REPLACE(COALESCE($column,''),' ',''),'_','')) NOT IN ($values)";
    }
}
