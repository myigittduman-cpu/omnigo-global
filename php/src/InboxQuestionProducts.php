<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-time product labels for old and new questions, using already imported local data. */
final class InboxQuestionProducts
{
    private const RECORDS = ['koctas'=>'order_koctas', 'idefix'=>'order_idefix', 'n11'=>'order_n11', 'pazarama'=>'order_pazarama'];
    private const RECENT = ['hepsiburada', 'trendyol'];
    private const DELIVERY_FALLBACK = ['koctas'=>'Koçtaş','idefix'=>'idefix'];
    private const UNKNOWN = 'Ürün adı bekleniyor';
    private array $orders = [];
    private ?bool $inventoryAvailable = null;
    private ?bool $recentAvailable = null;
    private bool $deliveryAvailable = true;
    private array $listingMatches = [];
    private array $pazaramaImages = [];

    public function __construct(
        private Database $db,
        private ?InboxQuestionPhotoCatalog $photoCatalog = null,
        private ?DeliveryCipher $cipher = null,
        private ?InboxQuestionOrderModels $verifiedOrders = null,
        private ?InboxVerifiedQuestionPhotos $verifiedQuestions = null,
    ) {}

    /** Fetch each company/provider batch once; only the exact order references are used. */
    public function prime(array $rows): void
    {
        $groups = [];
        foreach ($rows as [$company, $provider, $thread]) {
            $number = InboxQuestionFilters::identifiers($thread)['orderNumber'] ?? '';
            if ($number !== '' && self::sourceTitle($provider, $thread) === '') $groups[$company][$provider][$number] = true;
        }
        foreach ($groups as $company=>$marketplaces) {
            foreach ($marketplaces as $provider=>$numbers) {
                $numbers = array_map('strval', array_keys($numbers));
                $orders = $this->readOrders((int)$company, $provider, $numbers);
                if (isset(self::DELIVERY_FALLBACK[$provider])) {
                    $missing = array_values(array_filter($numbers, static function(string $number) use ($orders): bool {
                        foreach ($orders[$number] ?? [] as $product) {
                            if (self::productName($product['title'] ?? '', [$product['sku'] ?? '']) !== '') return false;
                        }
                        return true;
                    }));
                    $orders = array_replace($orders, $this->deliveryProducts((int)$company, $provider, $missing));
                }
                $this->orders[$company][$provider] = $orders;
            }
        }
    }

    public function present(int $company, string $provider, array $thread, bool $photo): array
    {
        $product = $this->presentCatalog($company, $provider, $thread, $photo);
        if (!$photo || !is_string($thread['id'] ?? null)) return $product;
        $directOrder = $provider === 'koctas' || ($provider === 'idefix' && str_starts_with($thread['id'], 'order:'));
        if (!$directOrder && isset($product['imageUrl'])) return $product;
        $this->verifiedQuestions ??= new InboxVerifiedQuestionPhotos();
        $verified = $this->verifiedQuestions->find($company, $provider, $thread['id']);
        if ($verified === null) return $product;
        $title = $directOrder || $product['title'] === self::UNKNOWN ? $verified['title'] : $product['title'];
        return ['title'=>$title, 'imageUrl'=>$verified['imageUrl']];
    }

    private function presentCatalog(int $company, string $provider, array $thread, bool $photo): array
    {
        $fields = InboxQuestionFilters::identifiers($thread);
        $orderLines = $this->orders[$company][$provider][$fields['orderNumber'] ?? ''] ?? [];
        $title = self::sourceTitle($provider, $thread);
        $offerIds = [];
        $offerNames = [];
        foreach ($thread['entities'] ?? [] as $entity) {
            if (!in_array($entity['type'] ?? '', ['MMP_OFFER', 'MMP_PRODUCT'], true)) continue;
            $id = ShipmentProductCode::normalize($entity['id'] ?? null);
            if ($id !== '') $offerIds[$id] = true;
            $name = self::productName($entity['label'] ?? '', [$id]);
            if ($name !== '') $offerNames[$name] = true;
        }
        $skus = array_filter([$fields['sku'] ?? '']);
        $productCode = $fields['productCode'] ?? '';
        $codes = array_map('strval', array_keys($offerIds));
        if ($productCode !== '') $codes[] = $productCode;

        if ($title === '' && count($offerIds) <= 1 && count($offerNames) === 1) {
            $title = (string)array_key_first($offerNames);
        }
        if ($title === '' && $orderLines) {
            [$candidates, $incomplete] = $this->orderProducts($orderLines, $skus, $codes);
            if (count($candidates) > 1) {
                // This is an order conversation with several products, not a single product match.
                return ['title'=>implode(' · ', array_keys($candidates))];
            }
            if ($candidates) {
                $title = (string)array_key_first($candidates);
                $verifiedSkus = reset($candidates);
                if (($incomplete && count($orderLines)>1) || count($verifiedSkus) > 1) return ['title'=>$title];
                $skus = array_unique([...$skus, ...$verifiedSkus]);
            }
        }

        if ($title === '' && !$orderLines && !$offerIds && !$offerNames && !$codes && !$skus && !empty($fields['orderNumber'])) {
            $this->verifiedOrders ??= new InboxQuestionOrderModels();
            $verified = $this->verifiedOrders->find($company, $provider, $fields['orderNumber']);
            if ($verified !== null) {
                $title = $verified['title'];
                $this->photoCatalog ??= new InboxQuestionPhotoCatalog();
                $url = $photo ? $this->photoCatalog->url($title) : '';
                return ['title'=>$title, ...($url !== '' ? ['imageUrl'=>$url] : [])];
            }
        }

        if (count($offerIds) > 1 || count($offerNames) > 1 || (!$photo && $title !== '')) return ['title'=>$title ?: self::UNKNOWN];
        $signature = serialize([$company,$provider,$skus,$codes,$title]);
        if (!array_key_exists($signature, $this->listingMatches)) {
            $this->listingMatches[$signature] = $this->listing($company, $provider, $skus, $codes, $title);
        }
        $listing = $this->listingMatches[$signature];
        $listingTitle = self::productName($listing['sourceTitle'] ?? $listing['title'] ?? '', [...$skus, ...$codes]);
        $title = $title ?: ($listingTitle ?: self::UNKNOWN);
        if (!$photo || $title === self::UNKNOWN) return ['title'=>$title];
        $url = '';
        if ($listing && preg_match('/^[a-f0-9]{64}$/D', $listing['product_key'])) {
            $local = InventoryImages::url($company, $listing['product_key'], $listing['image_url']);
            $url = str_replace('/api/inventory/image?', '/api/sales/product-image?', $local);
        }
        if ($url === '') {
            $this->photoCatalog ??= new InboxQuestionPhotoCatalog();
            $models = $skus;
            if ($listing) {
                $evidence = json_decode($listing['evidence'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if (!empty($evidence['identityVerified'])) {
                    array_push($models, $evidence['invoiceModel'] ?? '', $evidence['model'] ?? '', $listing['sku'] ?? '');
                }
            }
            $url = $this->photoCatalog->url($title, $models);
        }
        if ($url === '' && $provider === 'pazarama') {
            return ['title'=>$title, ...$this->pazaramaPhoto($company, $title, $skus)];
        }
        return ['title'=>$title, ...($url ? ['imageUrl'=>$url] : [])];
    }

    private function pazaramaPhoto(int $company, string $title, array $skus): array
    {
        $key = serialize([$company,$title,$skus]);
        $url = $this->pazaramaImages[$key] ??= (new InboxPazaramaOrderPhotos($this->db))->find($company, $title, $skus);
        return $url === '' ? [] : ['imageUrl'=>$url];
    }

    private static function sourceTitle(string $provider, array $thread): string
    {
        // Mirakl's topic and idefix order topics are subjects, not product names.
        if ($provider === 'koctas' || ($provider === 'idefix' && str_starts_with((string)($thread['id'] ?? ''), 'order:'))) return '';
        return self::productName($thread['title'] ?? '', InboxQuestionFilters::identifiers($thread));
    }

    private static function productName(mixed $value, array $identifiers = []): string
    {
        $name = DeliveryMapper::text($value, 500);
        if ($name === '' || in_array($name, $identifiers, true) || !preg_match('/\p{L}/u', $name)) return '';
        if (preg_match('/^(?:(?:ORDER|PRODUCT|OFFER)[ _-]*(?:MESSAG(?:E|ING)|QUESTION|SUBJECT|REQUEST)(?:[ _:#-].*)?|(?:Hepsiburada|Trendyol|Pazarama|n11|idefix|Koçtaş) sorusu|Müşteri mesajı|Ürün adı bekleniyor|Sipariş sorusu|Stok kodu:.*)$/iuD', $name)) return '';
        return $name;
    }

    private function readOrders(int $company, string $provider, array $numbers): array
    {
        $in = implode(',', array_fill(0, count($numbers), '?'));
        if (in_array($provider, self::RECENT, true)) {
            $this->recentAvailable ??= RecentOrderSchema::available($this->db);
            if (!$this->recentAvailable) return [];
            $rows = $this->db->all("SELECT payload FROM recent_orders WHERE company_id=? AND provider=? AND external_id IN ($in)",
                [$company, $provider, ...$numbers]);
        } elseif (isset(self::RECORDS[$provider])) {
            $id = $this->db->mysql() ? "JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))" : "JSON_EXTRACT(payload,'$.externalId')";
            $rows = $this->db->all("SELECT payload FROM records WHERE company_id=? AND kind=? AND $id IN ($in)",
                [$company, self::RECORDS[$provider], ...$numbers]);
        } else {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $order = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
            $number = (string)($order['externalId'] ?? '');
            if (in_array($number, $numbers, true)) $result[$number] = [...($result[$number] ?? []), ...($order['products'] ?? [])];
        }
        return $result;
    }

    /** Only exact delivery order references can supplement missing finance records. */
    private function deliveryProducts(int $company, string $provider, array $numbers): array
    {
        if (!$this->deliveryAvailable || !$numbers) return [];
        $keyFile = dirname(__DIR__).'/storage/backup.key';
        if ($this->cipher === null && !is_readable($keyFile)) return [];
        $labels = self::DELIVERY_FALLBACK;
        $params = [$company,$labels[$provider]];
        $criteria = [];
        foreach ($numbers as $number) {
            $criteria[] = 'external_id=?';
            $params[] = $number;
            if ($provider === 'idefix') {
                $criteria[] = "external_id LIKE ? ESCAPE '!'";
                $params[] = strtr($number, ['!'=>'!!','%'=>'!%','_'=>'!_']).':%';
            }
        }
        try {
            $rows = $this->db->all('SELECT external_id,payload FROM delivery_orders WHERE company_id=? AND provider=? AND ('
                .implode(' OR ', $criteria).')', $params);
        } catch (\PDOException $error) {
            if ($error->getCode() !== '42S02' && !str_contains($error->getMessage(), 'no such table: delivery_orders')) throw $error;
            $this->deliveryAvailable = false;
            return [];
        }
        $cipher = $this->cipher ??= new DeliveryCipher($keyFile);
        $products = [];
        $unverified = [];
        foreach ($rows as $row) {
            $external = $row['external_id'];
            $number = $provider === 'idefix' ? (preg_replace('/:[0-9]+$/D','',$external) ?? $external) : $external;
            if (!in_array($number, $numbers, true)) continue;
            try {
                $order = $cipher->open($company, 'order:'.$labels[$provider].':'.$external, $row['payload']);
            } catch (\RuntimeException | \JsonException) {
                $unverified[$number] = true;
                continue;
            }
            if (($order['orderNumber'] ?? null) !== $number || ($order['provider'] ?? null) !== $labels[$provider]) {
                $unverified[$number] = true;
                continue;
            }
            if (!empty($order['details']['superseded'])) continue;
            $lines = $order['details']['products'] ?? $order['products'] ?? [];
            foreach ($lines as $line) {
                $products[$number][] = array_intersect_key($line,array_flip(['title','sku','sourceProduct','productId']));
            }
        }
        return array_diff_key($products, $unverified);
    }

    private function orderProducts(array $lines, array $skus, array $codes): array
    {
        $products = [];
        $incomplete = false;
        foreach ($lines as $line) {
            $sku = ShipmentProductCode::normalize($line['sku'] ?? null);
            $source = is_array($line['sourceProduct'] ?? null) ? $line['sourceProduct'] : [];
            $ids = [$sku, ...array_values(array_intersect_key($source+$line, array_flip(['listingId', 'catalogId', 'variantId', 'barcode', 'productId'])))];
            if (($skus || $codes) && !array_intersect([...$skus, ...$codes], array_filter($ids))) continue;
            $name = self::productName($line['title'] ?? '', $ids);
            if ($name === '' || $sku === '') $incomplete = true;
            if ($name !== '') $products[$name] = array_unique([...($products[$name] ?? []), ...($sku !== '' ? [$sku] : [])]);
        }
        return [$products, $incomplete];
    }

    private function listing(int $company, string $provider, array $skus, array $codes, string $title): ?array
    {
        $keys = array_values(array_unique([...$skus, ...$codes]));
        if ((!$keys && $title==='') || $this->inventoryAvailable === false) return null;
        $in = implode(',', array_fill(0, count($keys), '?'));
        $marketplace = InboxQuestionFilters::PROVIDERS[$provider] ?? null;
        if (!$marketplace) return null;
        $sourceId = $this->db->mysql() ? "JSON_UNQUOTE(JSON_EXTRACT(l.evidence,'$.sourceCatalogId'))" : "JSON_EXTRACT(l.evidence,'$.sourceCatalogId')";
        $catalogId = str_replace('sourceCatalogId', 'invoiceCatalogId', $sourceId);
        $sourceName = str_replace('sourceCatalogId', 'sourceTitle', $sourceId);
        $match = [];
        $params = [$company,$marketplace];
        if ($keys) {
            $match[] = "l.sku IN ($in) OR l.remote_id IN ($in) OR $sourceId IN ($in) OR $catalogId IN ($in)";
            array_push($params, ...$keys, ...$keys, ...$keys, ...$keys);
        }
        if ($title !== '' && !$skus) {
            $match[] = "LOWER(TRIM(COALESCE(NULLIF($sourceName,''),p.title)))=LOWER(?)";
            $params[] = $title;
        }
        $condition = implode(' OR ', $match);
        try {
            $rows = $this->db->all("SELECT p.product_key,p.title,p.image_url,l.sku,l.remote_id,l.evidence
                FROM inventory_listings l JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key
                WHERE l.company_id=? AND l.provider=? AND ($condition)",$params);
            $this->inventoryAvailable = true;
            $matched = [];
            foreach ($rows as $row) {
                $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
                $exactCode = array_intersect($codes, [$row['remote_id'], $evidence['sourceCatalogId'] ?? '', $evidence['invoiceCatalogId'] ?? '']);
                $exactSku = in_array($row['sku'], $skus, true);
                $sourceTitle = self::productName($evidence['sourceTitle'] ?? '', $keys) ?: $row['title'];
                $exactTitle = $title !== '' && self::titleKey($title) === self::titleKey($sourceTitle);
                // A conflicting known SKU never falls back to a similar catalogue item.
                if (!$exactCode && (($title !== '' && !$exactTitle) || (!$exactSku && $skus))) continue;
                if (!$exactCode && !$exactSku && !$exactTitle) continue;
                $rank = $exactCode ? 3 : ($exactSku ? 2 : 1);
                $matched[$rank][$row['product_key']] = [...$row, 'sourceTitle'=>$sourceTitle];
            }
            $found = $matched[3] ?? $matched[2] ?? $matched[1] ?? [];
            if ($found) return count($found) === 1 ? reset($found) : null;
            // Older catalogues may have a product without a listing. Require an exact known title as well as SKU.
            if (!$skus || $title === '') return null;
            $skuIn = implode(',', array_fill(0, count($skus), '?'));
            $found = array_filter($this->db->all("SELECT product_key,title,sku,image_url FROM inventory_products WHERE company_id=? AND sku IN ($skuIn)",
                [$company, ...$skus]), static fn(array $row): bool=>in_array($row['sku'], $skus, true) && self::titleKey($row['title']) === self::titleKey($title));
            return count($found) === 1 ? reset($found) : null;
        } catch (\PDOException $error) {
            if ($error->getCode() !== '42S02' && !str_contains($error->getMessage(), 'no such table: inventory_')) throw $error;
            $this->inventoryAvailable = false;
            return null;
        }
    }

    private static function titleKey(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? ''), 'UTF-8');
    }
}
