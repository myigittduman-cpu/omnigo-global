<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only comparison from a physical stock count. No queue or marketplace gateway. */
final class InventoryCentralStockLivePreview
{
    public const STORE = 'Terzioğlu Shop';

    public function __construct(private Database $db) {}

    public function read(string $sku, int $quantity, \DateTimeImmutable $countedAt, \DateTimeImmutable $now): array
    {
        $sku = InventoryLiveSkuIdentity::normalize($sku);
        if ($sku === '' || $quantity < 0 || $quantity > 100000000 || $countedAt >= $now || $countedAt < $now->modify('-31 days')) {
            throw new \InvalidArgumentException('Stok kodunu, sayım adedini ve son 31 gün içindeki sayım zamanını kontrol edin.');
        }
        $company = $this->company();
        $card = InventoryStockCard::primaryProductSql($this->db);
        $products = $this->db->all("SELECT p.product_key FROM inventory_products p
            WHERE p.company_id=? AND UPPER(TRIM(p.sku))=? AND $card
            AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=p.company_id AND r.product_key=p.product_key)
            LIMIT 2", [$company, $sku]);
        if (count($products) !== 1) {
            throw new \DomainException('Terzioğlu Shop stok kartı tekil olarak doğrulanamadı.');
        }

        $key = $products[0]['product_key'];
        [$snapshots, $lastChange, $orderHolds] = $this->orders($company, $key, $sku, $countedAt, $now);
        [$quantities, $catalogHolds, $targetHolds, $observedAt] = $this->catalog($company, $key, $sku, $lastChange, $now);
        $ledgerKey = InventoryStockCard::productKeySql($this->db, 'l');
        $ledger = $this->db->all("SELECT l.created FROM inventory_ledger l
            WHERE l.company_id=? AND $ledgerKey=? AND l.created>=? AND l.delta<>0",
            [$company, $key, self::utc($countedAt->modify('-1 day'))]);
        if (array_filter($ledger, static fn($row) => new \DateTimeImmutable($row['created']) >= $countedAt)) {
            $orderHolds[] = 'manual_movement_after_count';
        }
        $job = $this->db->one('SELECT last_success,error FROM inventory_jobs WHERE company_id=?', [$company]);
        if (!$job || !empty($job['error']) || strtotime($job['last_success'] ?? '') < $now->getTimestamp()-1800) {
            $orderHolds[] = 'order_projection_unverified';
        }
        if ($this->db->one('SELECT 1 FROM inventory_issues WHERE company_id=? LIMIT 1', [$company])) {
            $orderHolds[] = 'unresolved_order_projection_issue';
        }

        $base = ['sku' => $sku, 'openingQuantity' => $quantity, 'marketplaceQuantities' => $quantities];
        $result = InventoryCentralStockPreview::calculate([$base], $snapshots)[0];
        $result['holds'] = array_values(array_unique([...$orderHolds, ...$catalogHolds]));
        foreach ($result['marketplaceQuantities'] as $provider => &$channel) {
            $channel['observedAt'] = $observedAt[$provider] ?? null;
            $channel['holds'] = $targetHolds[$provider] ?? [];
            $channel['decision'] = match (true) {
                $result['holds'] !== [] || $channel['holds'] !== [] => 'hold',
                $channel['difference'] === 0 => 'already_correct',
                default => 'would_update',
            };
        }
        unset($channel);
        $result['transferPreview'] = (new InventoryCentralStockTransferPreview($this->db))->plan(
            $company, $key, $result['marketplaceQuantities']
        );
        return ['store' => self::STORE, 'mode' => 'read_only', 'countedAt' => self::utc($countedAt),
            'checkedAt' => self::utc($now), 'orderLines' => count($snapshots), 'card' => $result];
    }

    private function company(): int
    {
        $shops = $this->db->all('SELECT id,name FROM companies');
        $matches = array_values(array_filter($shops, static fn($shop) =>
            InventoryLiveSkuIdentity::normalize(str_replace('İ', 'I', $shop['name'])) === 'TERZIOĞLU SHOP'));
        if (count($matches) !== 1) {
            throw new \DomainException('Terzioğlu Shop tekil olarak doğrulanamadı.');
        }
        return (int)$matches[0]['id'];
    }

    private function orders(int $company, string $key, string $sku, \DateTimeImmutable $countedAt, \DateTimeImmutable $now): array
    {
        $card = InventoryStockCard::productKeySql($this->db, 'b');
        $earliest = self::utc($countedAt->modify('-1 day'));
        $rows = $this->db->all("SELECT b.provider,b.line_key,b.sequence_no,b.ordered,b.cancelled,b.returned,b.placed_at,b.updated
            FROM inventory_balances b WHERE b.company_id=? AND $card=? AND (b.placed_at>=? OR b.updated>=?) LIMIT 5001",
            [$company, $key, $earliest, $earliest]);
        if (count($rows) > 5000) {
            throw new \DomainException('Deneme için daha kısa bir sayım aralığı gerekiyor.');
        }
        $snapshots = [];
        $lastChange = $countedAt->getTimestamp();
        $holds = [];
        foreach ($rows as $row) {
            $placed = new \DateTimeImmutable($row['placed_at']);
            $updated = new \DateTimeImmutable($row['updated']);
            if ($placed > $now || $updated > $now) {
                $holds[] = 'future_order_timestamp';
                continue;
            }
            if ($placed <= $countedAt) {
                if ($placed == $countedAt || ($updated >= $countedAt && ((int)$row['cancelled'] || (int)$row['returned']))) {
                    $holds[] = 'order_at_or_revised_after_count';
                }
                continue;
            }
            if ((int)$row['returned'] > 0) {
                $holds[] = 'returned_quantity_needs_stock_confirmation';
            }
            $lastChange = max($lastChange, $placed->getTimestamp(), $updated->getTimestamp());
            $snapshots[] = ['sku'=>$sku, 'provider'=>$row['provider'], 'lineKey'=>$row['line_key'],
                'revision'=>max(1,(int)$row['sequence_no']), 'ordered'=>(int)$row['ordered'], 'cancelled'=>(int)$row['cancelled']];
        }
        return [$snapshots, $lastChange, $holds];
    }

    private function catalog(int $company, string $key, string $sku, int $lastChange, \DateTimeImmutable $now): array
    {
        $card = InventoryStockCard::productKeySql($this->db, 'l');
        $rows = $this->db->all("SELECT l.provider,l.sku,l.observed_at,l.source_quantity,l.evidence FROM inventory_listings l
            WHERE l.company_id=? AND $card=? AND l.remote_id NOT LIKE 'order-sku:%'", [$company, $key]);
        $targets = array_count_values(array_column((new ActiveMarketplaceListings($this->db))->writableCardTargets($company, $key), 'provider'));
        $groups = [];
        $barcodes = [];
        foreach ($rows as $row) {
            $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
            if (($evidence['saleActive'] ?? null) !== 1) {
                continue;
            }
            $groups[$row['provider']][] = $row;
            $barcode = $evidence['barcode'] ?? '';
            if (is_string($barcode) && trim($barcode) !== '') {
                $barcodes[trim($barcode)] = true;
            }
        }

        $quantities = [];
        $observations = [];
        $holds = [];
        foreach ($groups as $provider => $listings) {
            $row = $listings[0];
            $observedAt = strtotime($row['observed_at']);
            $quantity = ActiveMarketplaceListings::quantity($row);
            $recent = $observedAt >= max($lastChange, $now->getTimestamp()-1800) && $observedAt <= $now->getTimestamp();
            $quantities[$provider] = count($listings) === 1 && $recent ? $quantity : null;
            if ($quantities[$provider] !== null) {
                $observations[$provider] = $row['observed_at'];
            }
            if (count($listings) !== 1 || InventoryLiveSkuIdentity::normalize($row['sku']) !== $sku) {
                $holds[$provider][] = 'listing_identity_unverified';
            }
            if (!$recent || $quantity === null) {
                $holds[$provider][] = 'catalog_quantity_unverified';
            }
            if (($targets[$provider] ?? 0) !== 1) {
                $holds[$provider][] = 'transfer_target_unverified';
            }
        }
        $cardHolds = count($barcodes) > 1 ? ['listing_barcode_conflict'] : [];
        if (!$quantities) {
            $cardHolds[] = 'no_active_listing';
        }
        return [$quantities, $cardHolds, $holds, $observations];
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
