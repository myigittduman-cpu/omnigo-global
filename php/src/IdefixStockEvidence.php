<?php
declare(strict_types=1);
namespace Omnigo;

/** A merchant export identifies a listing by its barcode and seller stock code. */
final class IdefixStockEvidence
{
    public static function read(array $listing, array $evidence): ?array
    {
        $saved = $evidence['idefixStockCheck'] ?? null;
        if ($listing['provider'] !== 'idefix' || !is_array($saved)
            || ($saved['barcode'] ?? null) !== $listing['remote_id'] || ($saved['sku'] ?? null) !== $listing['sku']) return null;
        $panel = $saved['panel'] ?? null;
        if (!is_array($panel) || !is_string($panel['label'] ?? null) || !is_int($panel['quantity'] ?? null)
            || !is_int($panel['observedAt'] ?? null) || $panel['quantity'] < 0) return null;
        $result = ['panelQuantity' => $panel['quantity'], 'panelLabel' => $panel['label'],
            'panelAt' => gmdate('c', $panel['observedAt'])];
        $stock = $saved['inventory'] ?? null;
        if (is_array($stock) && is_int($stock['quantity'] ?? null) && $stock['quantity'] >= 0 && is_int($stock['observedAt'] ?? null)) {
            $result['inventoryQuantity'] = $stock['quantity'];
            $result['inventoryAt'] = gmdate('c', $stock['observedAt']);
        }
        return $result;
    }
}
