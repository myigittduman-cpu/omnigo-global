<?php
declare(strict_types=1);
namespace Omnigo;

/** Package-level shipping references only. Product EANs and order identifiers are never label values. */
final class DeliveryShippingLabels
{
    public static function code(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) return null;
        $value = (string)$raw;
        if (!preg_match('/^(?=[A-Za-z0-9._-]*[0-9])[A-Za-z0-9][A-Za-z0-9._-]{0,47}$/D', $value)) return null;
        return preg_match('/^0+$/D', $value) ? null : $value;
    }

    public static function forProduct(array $order, array $product): array
    {
        $details = $order['details'] ?? [];
        $unit = $order['trendyolSourceItems'][$product['lineId'] ?? ''] ?? [];
        [$package, $code] = match ($order['provider']) {
            'Hepsiburada' => [$details['labelPackageId'] ?? '', $details['shippingBarcode'] ?? null],
            'Trendyol' => [$unit['package'] ?? '', $unit['trackingNumber'] ?? null],
            'n11' => [$details['packageId'] ?? '', $details['trackingNumber'] ?? null],
            'PTT AVM' => ['', $details['trackingNumber'] ?? null],
            'Pazarama' => ['', $product['shippingBarcode'] ?? null],
            default => ['', null],
        };
        return ['packageId'=>(string)$package, 'value'=>self::code($code), 'format'=>'CODE128'];
    }

    /** Merge only inside the same company's order, marketplace and delivery address. */
    public static function merge(array $labels): array
    {
        $packages = [];
        $owners = [];
        foreach ($labels as $label) {
            $id = $label['packageId'];
            $code = $label['value'];
            $key = $id === '' ? 'code:'.$code : 'id:'.$id;
            if (!isset($packages[$key])) {
                $packages[$key] = $label;
            } elseif ($packages[$key]['value'] !== $code) {
                $packages[$key]['value'] = null;
            }
            if ($id !== '' && $code !== null) $owners['code:'.$code][$key] = true;
        }
        foreach ($owners as $ids) {
            if (count($ids) < 2) continue;
            foreach (array_keys($ids) as $key) $packages[$key]['value'] = null;
        }
        ksort($packages, SORT_STRING);
        return array_values($packages);
    }
}
