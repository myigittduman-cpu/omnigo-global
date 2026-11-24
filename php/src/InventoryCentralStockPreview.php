<?php
declare(strict_types=1);
namespace Omnigo;

/** Calculates stock from a fixed opening count and order snapshots after that count. */
final class InventoryCentralStockPreview
{
    public static function calculate(array $cards, array $snapshots): array
    {
        $stock = [];
        foreach ($cards as $card) {
            $sku = self::sku($card['sku'] ?? null);
            if (isset($stock[$sku])) {
                throw new \InvalidArgumentException('Aynı SKU için birden fazla başlangıç stoğu girildi.');
            }
            $stock[$sku] = [
                'sku' => $sku,
                'openingQuantity' => self::quantity($card['openingQuantity'] ?? null),
                'reserved' => 0,
                'marketplaceQuantities' => self::marketplaces($card['marketplaceQuantities'] ?? []),
            ];
        }

        $latest = [];
        foreach ($snapshots as $snapshot) {
            $sku = self::sku($snapshot['sku'] ?? null);
            $provider = $snapshot['provider'] ?? null;
            $line = $snapshot['lineKey'] ?? null;
            $revision = $snapshot['revision'] ?? null;
            if (!isset($stock[$sku]) || !in_array($provider, InventoryCatalog::PROVIDERS, true)
                || !is_string($line) || trim($line) === '' || !is_int($revision) || $revision < 1) {
                throw new \InvalidArgumentException('Sipariş satırının stok kartı, pazaryeri, kimliği veya sürümü doğrulanamadı.');
            }
            $ordered = self::quantity($snapshot['ordered'] ?? null);
            $cancelled = self::quantity($snapshot['cancelled'] ?? 0);
            $restocked = self::quantity($snapshot['restocked'] ?? 0);
            if ($ordered < 1 || $cancelled + $restocked > $ordered) {
                throw new \InvalidArgumentException('İptal ve satılabilir stoğa dönen iade adedi sipariş adedini aşıyor.');
            }
            $entry = compact('sku', 'revision', 'ordered', 'cancelled', 'restocked');
            $previous = $latest[$provider][$line] ?? null;
            if ($previous !== null) {
                if ($previous['sku'] !== $sku || ($previous['revision'] === $revision && $previous !== $entry)) {
                    throw new \DomainException('Aynı sipariş satırına ait kayıtlar çelişiyor.');
                }
                if ($previous['revision'] >= $revision) {
                    continue;
                }
            }
            $latest[$provider][$line] = $entry;
        }

        foreach ($latest as $lines) {
            foreach ($lines as $line) {
                $stock[$line['sku']]['reserved'] += $line['ordered'] - $line['cancelled'] - $line['restocked'];
            }
        }

        foreach ($stock as &$card) {
            $balance = $card['openingQuantity'] - $card['reserved'];
            $card['availableQuantity'] = max(0, $balance);
            $card['shortage'] = max(0, -$balance);
            foreach ($card['marketplaceQuantities'] as $provider => $observed) {
                $card['marketplaceQuantities'][$provider] = [
                    'observed' => $observed,
                    'expected' => $card['availableQuantity'],
                    'difference' => $observed === null ? null : $observed - $card['availableQuantity'],
                ];
            }
        }
        unset($card);
        return array_values($stock);
    }

    private static function sku(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Stok kodu eksik.');
        }
        return InventoryLiveSkuIdentity::normalize($value);
    }

    private static function quantity(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 100000000) {
            throw new \InvalidArgumentException('Stok veya sipariş adedi geçersiz.');
        }
        return $value;
    }

    private static function marketplaces(array $quantities): array
    {
        foreach ($quantities as $provider => $value) {
            if (!in_array($provider, InventoryCatalog::PROVIDERS, true)) {
                throw new \InvalidArgumentException('Stok karşılaştırmasındaki pazaryeri doğrulanamadı.');
            }
            if ($value !== null) {
                self::quantity($value);
            }
        }
        return $quantities;
    }
}
