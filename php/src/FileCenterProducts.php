<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads confirmed catalogue and order snapshots without scheduling marketplace work. */
final class FileCenterProducts
{
    private const MAX_AGE = 1800;

    public function __construct(private Database $db) {}

    public function listing(int $company, int $page, string $query = '', string $sort = 'stable', string $brand = ''): array
    {
        // Use the stock page's catalog identity, saved visibility and sorting rules.
        $stock = (new InventoryRead($this->db))->productPage($company, $query, $page, filter: 'all',
            sort: $sort, fileCenter: true, brand: $brand);
        $rows = $stock['rows'];
        $coverage = $this->coverage($company, gmdate('c', time() - self::MAX_AGE));
        $keys = array_column($rows, 'product_key');
        $snapshots = (new InventorySourceStock($this->db))->forKeys($company, $keys, includeOfferPrice: true);
        $verified = [];
        foreach ($coverage as $provider) {
            if (!$provider['ready']) continue;
            foreach ($snapshots as $key => $sources) {
                foreach ($sources as $source) {
                    if ($source['provider'] === $provider['name'] && $source['observedAt'] >= $provider['since']) {
                        $verified[$key][] = $source;
                    }
                }
            }
        }
        $statuses = (new InventoryMarketplaceAvailability($this->db))->forProducts($company, $rows, $verified, []);
        $prices = $this->lastPrices($company, $keys);
        $items = [];
        foreach ($rows as $row) {
            $key = $row['product_key'];
            $status = array_column($statuses[$key] ?? [], 'status', 'provider');
            $sources = [];
            foreach ($verified[$key] ?? [] as $source) $sources[$source['provider']][] = $source;
            $markets = [];
            foreach ($coverage as $provider) {
                $name = $provider['name'];
                $current = $sources[$name] ?? [];
                $offer = self::offerPrice($current);
                $currentStatus = $status[$name] ?? 'unknown';
                if ($currentStatus === 'not_found' && !$provider['complete']) $currentStatus = 'awaiting';
                $markets[$name] = [
                    'status'=>self::marketStatus($provider['ready'], $currentStatus, $current),
                    ...($offer !== null ? ['priceCents'=>$offer, 'priceSource'=>'catalogue']
                        : ($prices[$key][$name] ?? ['priceCents'=>null, 'priceSource'=>null])),
                ];
            }
            $items[] = ['key'=>$key, 'name'=>$row['title'], 'sku'=>$row['sku'], 'barcode'=>$row['barcode'],
                'imageUrl'=>InventoryImages::url($company, $key, $row['image_url']), 'markets'=>$markets];
        }
        return ['items'=>$items, 'providers'=>$coverage, 'brands'=>$stock['brands'],
            'total'=>$stock['total'], 'page'=>$stock['page'], 'pageSize'=>$stock['pageSize']];
    }

    private function coverage(int $company, string $cutoff): array
    {
        $jobs = $this->db->all("SELECT j.provider,j.last_success,j.error,p.state,p.started_at FROM inventory_catalog_jobs j
            LEFT JOIN inventory_catalog_progress p ON p.company_id=j.company_id AND p.provider=j.provider
            WHERE j.company_id=?", [$company]);
        $jobs = array_column($jobs, null, 'provider');
        $coverage = [];
        foreach ((new CustomerMarketplaces($this->db))->listing($company) as $store) {
            if (!$store['selected'] || !$store['configured'] || !in_array($store['provider'], InventoryCatalogPage::SUPPORTED, true)) continue;
            $job = $jobs[$store['provider']] ?? [];
            $checked = $job['last_success'] ?? null;
            $ready = $checked >= $cutoff && empty($job['error']);
            $started = (int)($job['started_at'] ?? 0);
            // A completed run can establish absence since its start.
            $since = $job && $job['state'] === 'complete' && $started ? max($cutoff, gmdate('c', $started)) : $cutoff;
            $coverage[] = ['name'=>$store['name'], 'ready'=>(bool)$ready, 'checkedAt'=>$checked, 'since'=>$since, 'complete'=>$job && $job['state'] === 'complete'];
        }
        return $coverage;
    }

    private function lastPrices(int $company, array $keys): array
    {
        if (!$keys) return [];
        $placeholders = self::placeholders($keys);
        $card = InventoryStockCard::productKeySql($this->db,'b');
        $rows = $this->db->all("SELECT target,provider,invoice_cents,ordered,placed_at FROM (
            SELECT $card target,b.provider,b.invoice_cents,b.ordered,b.placed_at,
              ROW_NUMBER() OVER (PARTITION BY $card,b.provider
                ORDER BY b.placed_at DESC,b.sequence_no DESC,b.line_key DESC) price_rank
            FROM inventory_balances b
            WHERE b.company_id=? AND $card IN ($placeholders)
              AND b.invoice_cents>0 AND b.ordered-b.cancelled-b.returned>0 AND b.placed_at<>''
            ) sales WHERE sales.price_rank=1", [$company, ...$keys]);
        $prices = [];
        foreach ($rows as $row) {
            $prices[$row['target']][$row['provider']] = [
                'priceCents'=>(int)round((int)$row['invoice_cents'] / (int)$row['ordered']),
                'priceSource'=>'sale',
            ];
        }
        return $prices;
    }

    private static function marketStatus(bool $ready, string $status, array $sources): string
    {
        if (!$ready) return 'awaiting';
        if ($status !== 'active') return $status;
        foreach ($sources as $source) {
            if ($source['saleActive'] === true && $source['quantity'] > 0) return 'active';
        }
        foreach ($sources as $source) {
            if ($source['saleActive'] === true && $source['quantity'] === null) return 'unknown_stock';
        }
        return 'out_of_stock';
    }

    private static function offerPrice(array $sources): ?int
    {
        $offers = array_filter($sources, static fn(array $source): bool =>
            $source['saleActive'] !== false && $source['offerPriceCents'] !== null);
        $amounts = array_unique(array_column($offers, 'offerPriceCents'));
        return count($amounts) === 1 ? reset($amounts) : null;
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
