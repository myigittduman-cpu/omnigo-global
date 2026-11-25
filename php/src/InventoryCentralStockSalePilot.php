<?php
declare(strict_types=1);
namespace Omnigo;

/** One sale-triggered trial using the existing marketplace outbox. */
final class InventoryCentralStockSalePilot
{
    private const CHANNELS = ['n11'=>'n11','trendyol'=>'Trendyol'];

    public function __construct(private Database $db, private IntegrationVault $vault, private ?\Closure $readListing = null) {}

    public function tick(string $run, string $sku, int $opening, string $administrator, \DateTimeImmutable $countedAt, \DateTimeImmutable $expiresAt): array
    {
        $now = new \DateTimeImmutable();
        if (!preg_match('/^[a-f0-9]{16}$/D', $run)) {
            throw new \InvalidArgumentException('Pilot işlem kimliği geçersiz.');
        }
        $company = $this->uniqueId('companies', 'TERZIOĞLU SHOP');
        $actor = $this->uniqueId('users', InventoryLiveSkuIdentity::normalize(str_replace('İ','I',$administrator)));
        $pilot = MarketplaceStockPilot::load();
        if (!MarketplaceStockJob::allowed($this->db, $pilot, $company) || !(new Security($this->db, []))->active($actor)) {
            throw new \DomainException('Pilot mağaza veya yönetici yetkisi doğrulanamadı.');
        }

        $sku = InventoryLiveSkuIdentity::normalize($sku);
        $preview = (new InventoryCentralStockLivePreview($this->db))->read($sku, $opening, $countedAt, $now);
        $card = $preview['card'];
        $wanted = $card['availableQuantity'];
        if ($preview['orderLines'] === 0 || $card['reserved'] === 0) {
            return ['state'=>$now >= $expiresAt ? 'expired' : 'waiting_sale', 'quantity'=>$wanted,
                'orderLines'=>$preview['orderLines'], 'holds'=>$card['holds']];
        }

        $key = InventoryLiveSkuIdentity::load($this->db, $company)->key($sku);
        $targets = array_column((new ActiveMarketplaceListings($this->db))->writableCardTargets($company, $key), null, 'provider');
        $outbox = new MarketplaceStockQueue($this->db, new MarketplaceStockPolicy($this->db), $pilot);
        $responsible = ['id'=>$actor,'role'=>'admin'];
        $deadline = time()+16;
        $result = ['state'=>'monitoring','quantity'=>$wanted,'orderLines'=>$preview['orderLines'],'channels'=>[],'holds'=>$card['holds']];
        $plans = [];
        foreach (self::CHANNELS as $provider=>$label) {
            $request = 'sale-pilot-'.$run.'-'.$provider.'-'.$wanted;
            $prior = $this->db->one('SELECT state FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',
                [$company,'pilot:'.$provider.':'.$request]);
            $market = $card['marketplaceQuantities'][$label] ?? [];
            $proposed = $card['transferPreview'][$label]['action'] ?? 'wait_for_verified_data';
            $target = $targets[$label] ?? null;
            $status = $prior['state'] ?? null;
            if ($card['holds'] || in_array($status, ['queued','sending','accepted','failed','uncertain'], true)
                || in_array($proposed, ['verify_previous','wait_queued','hold_stale_queued'], true)) {
                $result['channels'][$label] = ['state'=>$status ?? 'held','quantity'=>$market['observed']??null];
                continue;
            }

            $listing = $this->db->all("SELECT remote_id FROM inventory_listings
                WHERE company_id=? AND product_key=? AND provider=? AND sku=? AND remote_id NOT LIKE 'order-sku:%' LIMIT 2",
                [$company,$key,$label,$sku]);
            if (count($listing) !== 1) {
                $result['channels'][$label] = ['state'=>'identity_unverified'];
                continue;
            }
            try {
                $live = $this->readListing === null
                    ? $this->liveListing($company, $provider, $sku, $deadline)
                    : ($this->readListing)($company, $provider, $sku, $deadline);
            } catch (\Throwable) {
                $result['channels'][$label] = ['state'=>'read_unverified'];
                continue;
            }
            if ($live['merchantSku'] !== $sku || !in_array($listing[0]['remote_id'], $live['remoteIds'], true)) {
                $result['channels'][$label] = ['state'=>'identity_unverified'];
                continue;
            }

            $actual = $live['quantity'];
            $action = self::action($wanted, $actual, $proposed, $status, $live['saleActive'] === true);
            $result['channels'][$label] = ['state'=>$action,'quantity'=>$actual,'attemptState'=>$status];
            if ($action === 'enqueue' && $target && $target['quantity'] === $actual && $target['remoteId'] === $listing[0]['remote_id']) {
                $plans[$label] = ['productKey'=>$key,'listingKey'=>$target['key'],'provider'=>$label,
                    'sourceQuantity'=>$actual,'quantity'=>$wanted,'requestId'=>$request,'confirmed'=>true];
            } elseif ($action === 'enqueue') {
                $result['channels'][$label]['state'] = 'wait_for_catalog';
            }
        }

        $states = array_column($result['channels'],'state');
        if ($states === ['verified','verified']) {
            $current = (new InventoryCentralStockLivePreview($this->db))->read($sku, $opening, $countedAt, new \DateTimeImmutable());
            $result['state'] = $current['card']['availableQuantity'] === $wanted && !$current['card']['holds'] ? 'verified' : 'stock_changed';
        } elseif ($now >= $expiresAt) {
            $result['state'] = 'expired';
        } elseif (!array_intersect($states, ['failed','uncertain','identity_unverified','quantity_below_central','changed_after_success'])) {
            $current = $plans ? (new InventoryCentralStockLivePreview($this->db))->read($sku, $opening, $countedAt, new \DateTimeImmutable()) : null;
            if ($current && ($current['card']['availableQuantity'] !== $wanted || $current['card']['holds'])) {
                $result['state'] = 'stock_changed';
                return $result;
            }
            foreach ($plans as $label=>$payload) {
                $queued = $outbox->submit($company,$payload,$responsible);
                $result['channels'][$label]['state'] = $queued['state'];
            }
        } else {
            $result['state'] = 'held';
        }
        return $result;
    }

    public static function action(int $wanted, int $actual, string $proposed, ?string $prior, bool $active): string
    {
        return match (true) {
            $actual === $wanted => 'verified',
            $actual < $wanted => 'quantity_below_central',
            $prior === 'succeeded' => 'changed_after_success',
            !$active, !in_array($proposed, ['would_send_latest','would_retry_latest'], true) => 'wait_for_catalog',
            default => 'enqueue',
        };
    }

    private function liveListing(int $company, string $provider, string $sku, int $deadline): array
    {
        $configuration = MarketplaceStockJob::configuration($this->db, $this->vault, $company, $provider);
        if (empty($configuration['enabled'])) {
            throw new \DomainException('Bağlantı kapalı.');
        }
        return MarketplaceStockJob::gateway($provider)->read($configuration, $sku, $deadline);
    }

    private function uniqueId(string $kind, string $name): int
    {
        $rows = $kind === 'companies' ? $this->db->all('SELECT id,name FROM companies')
            : $this->db->all("SELECT id,name FROM users WHERE role='admin'");
        $matches = array_values(array_filter($rows, static fn($row)=>
            InventoryLiveSkuIdentity::normalize(str_replace('İ','I',$row['name'])) === $name));
        if (count($matches) !== 1) {
            throw new \DomainException('Pilot mağaza veya onaylayan yönetici tekil olarak doğrulanamadı.');
        }
        return (int)$matches[0]['id'];
    }
}
