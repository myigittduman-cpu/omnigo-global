<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads catalogue prices and saved net costs. Never publishes a price. */
final class InventoryCentralPricePreview
{
    public function __construct(private Database $db) {}

    public function read(string $sku, array $rules, ?int $draftCostNetCents = null): array
    {
        $sku = InventoryLiveSkuIdentity::normalize($sku);
        if ($sku === '' || array_diff(array_keys($rules), InventoryCatalog::PROVIDERS)) {
            throw new \InvalidArgumentException('Stok kodunu ve pazaryeri fiyat kurallarını kontrol edin.');
        }
        $shops = array_values(array_filter($this->db->all('SELECT id,name FROM companies'), static fn($row) =>
            InventoryLiveSkuIdentity::normalize(str_replace('İ','I',$row['name'])) === 'TERZIOĞLU SHOP'));
        if (count($shops) !== 1) {
            throw new \DomainException('Terzioğlu Shop tekil olarak doğrulanamadı.');
        }
        $company = (int)$shops[0]['id'];
        $main = InventoryStockCard::primaryProductSql($this->db);
        $cards = $this->db->all("SELECT p.product_key FROM inventory_products p WHERE p.company_id=?
            AND UPPER(TRIM(p.sku))=? AND $main
            AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=p.company_id AND r.product_key=p.product_key)
            LIMIT 2", [$company,$sku]);
        if (count($cards) !== 1) {
            throw new \DomainException('Fiyat önizlemesi için tek bir stok kartı gerekli.');
        }

        $key = $cards[0]['product_key'];
        $stored = (new InventoryPurchasePrices($this->db))->forKeys($company,[$key])[$key] ?? [];
        $netCost = $draftCostNetCents ?? $stored['purchaseNetCents'] ?? null;
        $listings = (new InventorySourceStock($this->db))->forKeys($company,[$key],true)[$key] ?? [];
        $channels = [];
        foreach ($listings as $listing) {
            if ($listing['saleActive'] === true) {
                $channels[$listing['provider']][] = $listing;
            }
        }

        $output = [];
        foreach ($channels as $provider=>$offers) {
            $offer = $offers[0];
            $verified = count($offers) === 1 && $offer['health'] === 'current'
                && InventoryLiveSkuIdentity::normalize($offer['sku']) === $sku;
            $price = $verified ? $offer['offerPriceCents'] : null;
            $holds = [];
            if ($price === null) {
                $holds[] = 'catalog_price_unverified';
            }
            if ($netCost === null) {
                $holds[] = 'net_purchase_cost_missing';
            }
            if (!isset($rules[$provider])) {
                $holds[] = 'pricing_rule_missing';
            }
            $quote = null;
            if (!$holds) {
                if (!is_array($rules[$provider])) {
                    throw new \InvalidArgumentException('Kanal fiyat kuralı geçersiz.');
                }
                $quote = InventoryChannelPricePreview::quote($netCost, $rules[$provider]);
                $quote['differenceCents'] = $quote['priceGrossCents'] - $price;
            }
            $output[$provider] = ['currentPriceCents'=>$price,'quote'=>$quote,'holds'=>$holds];
        }
        return ['store'=>InventoryCentralStockLivePreview::STORE,'sku'=>$sku,'mode'=>'read_only',
            'netPurchaseCostCents'=>$netCost,'marketplaces'=>$output];
    }
}
