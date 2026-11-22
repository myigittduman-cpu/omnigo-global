<?php
declare(strict_types=1);
namespace Omnigo;

/** Stock evidence only. Order dates, sales counts and product update dates are not stock history. */
final class InventoryCatalogEligibility
{
    public static function windowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $now->setTimezone(new \DateTimeZone('Europe/Istanbul'));
        // Subtract calendar months without PHP's end-of-month overflow (e.g. June 30 -> February 28).
        $month = $local->modify('first day of this month')->modify('-4 months');
        return $local->setDate((int)$month->format('Y'), (int)$month->format('m'),
            min((int)$local->format('j'), (int)$month->format('t')));
    }

    /**
     * All inputs belong to one verified, tenant-scoped canonical product.
     * $sources: one entry per marketplace listing with a unique nonempty sourceKey.
     * observations: [{at: ISO8601, quantity: int}].
     * periods: [{from: ISO8601, to: ISO8601, maximumQuantity: int, complete: bool}].
     * A complete period must come from a source guaranteeing continuous history;
     * two zero snapshots must NEVER be converted into a complete zero period.
     * $catalogComplete means all connected marketplace catalogs completed successfully.
     */
    public static function evaluate(array $sources, bool $catalogComplete, \DateTimeImmutable $now): array
    {
        $start = self::windowStart($now);
        $from = $start->getTimestamp();
        $to = $now->getTimestamp();
        $zeroCoverage = [];
        $sourceKeys = [];
        $uncertain = !$sources || !$catalogComplete;

        foreach ($sources as $source) {
            if (!is_array($source)) { $uncertain = true; continue; }
            $key = $source['sourceKey'] ?? null;
            if (!is_string($key) || trim($key) === '' || isset($sourceKeys[$key])) $uncertain = true;
            else $sourceKeys[$key] = true;

            $observations = $source['observations'] ?? [];
            if (!is_array($observations)) { $uncertain = true; $observations = []; }
            foreach ($observations as $observation) {
                if (!is_array($observation)) { $uncertain = true; continue; }
                $at = self::timestamp($observation['at'] ?? null);
                $quantity = $observation['quantity'] ?? null;
                if ($at === null || !is_int($quantity) || $quantity < 0) { $uncertain = true; continue; }
                if ($at >= $from && $at <= $to && $quantity > 0) {
                    return self::result('include', 'Son dört ay içinde stok bulundu.', $start, $now);
                }
            }

            $periods = $source['periods'] ?? [];
            if (!is_array($periods)) { $uncertain = true; $periods = []; }
            $intervals = [];
            foreach ($periods as $period) {
                if (!is_array($period)) { $uncertain = true; continue; }
                $begin = self::timestamp($period['from'] ?? null);
                $end = self::timestamp($period['to'] ?? null);
                $maximum = $period['maximumQuantity'] ?? null;
                if ($begin === null || $end === null || $begin >= $end || !is_int($maximum) || $maximum < 0) {
                    $uncertain = true;
                    continue;
                }
                // A positive maximum is located in our window only if the whole period is inside it.
                if ($maximum > 0 && $begin >= $from && $end <= $to) {
                    return self::result('include', 'Son dört ay içinde stok bulundu.', $start, $now);
                }
                if ($maximum === 0 && ($period['complete'] ?? false) === true && $end > $from && $begin < $to) {
                    $intervals[] = [max($from, $begin), min($to, $end)];
                }
                elseif ($maximum > 0 && $end > $from && $begin < $to) {
                    // An overlapping positive history period cannot safely prove absence of stock.
                    $uncertain = true;
                }
            }
            $zeroCoverage[] = self::coversWindow($intervals, $from, $to);
        }

        if (!$uncertain && $zeroCoverage && !in_array(false, $zeroCoverage, true)) {
            return self::result('exclude', 'Son dört ayın tamamında stok bulunmadığı doğrulandı.', $start, $now);
        }
        return self::result('review', 'Dört aylık stok geçmişi doğrulanamadı; ürün otomatik dışlanmadı.', $start, $now);
    }

    private static function coversWindow(array $intervals, int $from, int $to): bool
    {
        usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $coveredUntil = $from;
        foreach ($intervals as [$begin, $end]) {
            if ($begin > $coveredUntil) return false;
            $coveredUntil = max($coveredUntil, $end);
            if ($coveredUntil >= $to) return true;
        }
        return false;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return null;
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? null : $date->getTimestamp();
        } catch (\Exception) { return null; }
    }

    private static function result(string $status, string $reason, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return ['status' => $status, 'visible' => $status !== 'exclude', 'reason' => $reason,
            'from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)];
    }
}
