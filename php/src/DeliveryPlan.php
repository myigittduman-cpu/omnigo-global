<?php
declare(strict_types=1);
namespace Omnigo;

/** Marketplace state determines which lines can enter the city delivery list. */
final class DeliveryPlan
{
    public const ADDITIONS = 'list-additions';

    public static function marketplaceState(array $order, array $product, string $linkedState = ''): string
    {
        $line = $product['shippingState'] ?? OrderShipping::state($order['provider'], $product['status'] ?? '');
        $package = $order['shippingState'] ?? OrderShipping::state($order['provider'], $order['details']['status'] ?? $order['status'] ?? '');
        $terminal = ['cancelled', 'undelivered', 'returned', 'delivered', 'completed'];
        if (in_array($line, $terminal, true)) return $line;
        if (in_array($package, $terminal, true)) return $package;
        if (in_array($line, ['pending', 'shipped', 'invoiced'], true)) return $line === 'invoiced' ? 'pending' : $line;
        if (in_array($linkedState, [...$terminal, 'pending', 'shipped', 'invoiced'], true)) {
            return $linkedState === 'invoiced' ? 'pending' : $linkedState;
        }
        if (in_array($package, ['pending', 'shipped'], true)) return $package;
        if (!empty($product['status'])) return 'unknown';
        return match ($order['deliveryStatus'] ?? $order['status'] ?? '') {
            'Sevkiyat bekliyor', 'Sevk bekliyor', 'Hazırlanıyor' => 'pending',
            'Yolda', 'Kargoda', 'Kargolandı', 'Sevk edildi' => 'shipped',
            'Teslim edildi', 'Sipariş Tamamlandı' => 'delivered',
            default => 'unknown',
        };
    }

    public static function products(array $order): array
    {
        $groups = $order['planningProducts'];
        return [...$groups['pending'], ...$groups['shipped']];
    }

    public static function status(array $order): string
    {
        $groups = $order['planningProducts'];
        return $groups['pending'] ? ($groups['shipped'] ? 'mixed' : 'pending') : 'shipped';
    }

    public static function label(string $status): string
    {
        return $status === 'mixed' ? 'Kısmi sevkiyat' : ShipmentFulfillment::label($status);
    }

    public static function documentOrder(array $order): array
    {
        $products = self::products($order);
        if (!$products) Http::fail(409, 'Sipariş artık teslimat listesinde yer almıyor. Listeyi yenileyin.');
        $order['products'] = $products;
        $order['deliveryStatus'] = self::label(self::status($order));
        unset($order['dispatchDecision'], $order['planningProducts'], $order['deliveryListAdded']);
        return $order;
    }

    public static function sourceRevision(array $order): string
    {
        $lines = [];
        foreach ($order['details']['products'] ?? $order['products'] ?? [] as $line) {
            $lines[] = json_encode(array_intersect_key($line, array_flip([
                'lineId', 'sku', 'title', 'status', 'shippingState', 'quantity', 'cancelledQuantity', 'refundedQuantity',
            ])), JSON_THROW_ON_ERROR);
        }
        sort($lines, SORT_STRING);
        return hash('sha256', json_encode([
            $order['active'] ?? false, $order['details']['status'] ?? null, $order['status'] ?? null,
            $order['deliveryStatus'] ?? null, $order['shippingState'] ?? null, $lines,
        ], JSON_THROW_ON_ERROR));
    }
}
