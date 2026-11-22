<?php
declare(strict_types=1);
namespace Omnigo;

/** Calls existing marketplace readers with the manual run's saved position. */
final class NewOrderReadPage
{
    private const WINDOW_SECONDS = 13 * 86400;

    public function __construct(
        private Database $db,
        private ?RecentOrderReader $recent = null,
        private ?MarketplaceReadClient $marketplace = null,
        private ?TrendyolReadClient $trendyol = null,
        private ?HepsiburadaReadClient $hepsiburada = null,
        private ?\Closure $koctas = null,
    ) {}

    public function read(int $company, string $provider, array $config, array $state, array $cursor, int $deadline, NewOrderRecordStore $store): array
    {
        if ($provider==='hepsiburada') return $this->hepsiburada($company,$config,$state,$cursor,$deadline,$store);

        $from = $cursor['windowFrom'];
        $to = min($from+self::WINDOW_SECONDS,$cursor['through']);
        $step = match($provider) {
            'n11','pazarama','idefix' => $this->recent($provider,$config,$state,$cursor,$from,$to,$deadline),
            'koctas' => $this->koctas($config,$state,$cursor,$from,$to,$deadline),
            'pttavm' => $this->pttavm($company,$config,$from,$to,$deadline),
            'trendyol' => $this->trendyol($config,$cursor,$from,$to,$deadline),
            default => throw new SyncFailure(422),
        };
        $next = [...$cursor,...$step['position']];
        $done = $step['complete'] && $to===$cursor['through'];
        if ($step['complete'] && !$done) {
            $next['windowFrom'] = $to;
            foreach (['page','offset','streamCursor','fingerprint'] as $field) unset($next[$field]);
        }
        return ['cursor'=>$next,'done'=>$done,'items'=>$step['items']];
    }

    private function recent(string $provider, array $config, array $state, array $cursor, int $from, int $to, int $deadline): array
    {
        $page = (int)($cursor['page'] ?? ($provider==='n11' ? 0 : 1));
        if ($page>=1000) throw new SyncFailure(422);
        $queryTo = $provider==='pazarama' ? (intdiv($to,60)+1)*60 : $to;
        $result = ($this->recent ?? new RecentOrderReader())->page($provider,$config,['from'=>$from,'through'=>$queryTo,'page'=>$page],$deadline);
        $position = ['page'=>$page+1];
        if ($provider==='n11' && $result['sellerId']!==null) {
            $seller = (string)($state['sellerId'] ?? $cursor['sellerId'] ?? $result['sellerId']);
            if ($seller!==(string)$result['sellerId']) throw new SyncFailure(403);
            $position['sellerId'] = $seller;
        }

        $groups = [];
        foreach ($result['items'] as $item) {
            $unit = $item['unit'];
            $number = $provider==='n11' ? $unit['orderNumber'] : $unit['externalId'];
            $delivery = $item['delivery'];
            $lineIds = array_column($unit['products'] ?? [],'lineId');
            sort($lineIds);
            $key = $unit['packageId'] ?? ($provider==='n11' ? hash('sha256',json_encode($lineIds,JSON_THROW_ON_ERROR)) : $number);
            $groups[$number]['packages'][(string)$key] = $unit;
            $groups[$number]['deliveries'][$delivery['externalId']] = $delivery;
        }
        $orders = [];
        foreach ($groups as $group) {
            $order = RecentOrderSummary::combine($provider,array_values($group['packages']));
            if ($order===null) continue;
            if ($provider==='n11') $order['refundsVerified'] = false;
            $orders[] = ['order'=>[...$order,'arrivalPackages'=>$group['packages']],'deliveries'=>array_values($group['deliveries'])];
        }
        return ['items'=>$orders,'complete'=>$result['complete'],'position'=>$position];
    }

    private function koctas(array $config, array $state, array $cursor, int $from, int $to, int $deadline): array
    {
        if (($state['verifiedRevision'] ?? null)!==$config['revision']) throw new SyncFailure(503,60);
        $offset = (int)($cursor['offset'] ?? 0);
        $query = ['shop_id'=>$config['shopId'],'max'=>100,'offset'=>$offset,'sort'=>'dateCreated','order'=>'asc',
            'order_tax_mode'=>'TAX_INCLUDED','start_date'=>gmdate('c',$from),'end_update_date'=>gmdate('c',$to)];
        // OR11 distinguishes creation time from last update. An order may have been edited after this historic window.
        if(isset($cursor['target'])){
            unset($query['end_update_date']);
            $query['end_date']=gmdate('c',$to);
        }
        $result = $this->koctas ? ($this->koctas)('/api/orders',$query,$deadline) : (new KoctasHttpClient($config['apiKey']))->get('/api/orders',$query,$deadline);
        $rows = $result['orders'] ?? null;
        $total = $result['total_count'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>100 || !is_int($total) || $total<0 || $total>10000) {
            throw new SyncFailure(422);
        }
        $next = $offset+count($rows);
        if (!$rows && $next<$total) throw new SyncFailure(422);
        $items = [];
        foreach ($rows as $raw) {
            $order = KoctasOrderMapper::map($raw);
            $delivery = DeliveryMapper::koctas($raw);
            $delivery['details'] = OrderDetailMapper::koctas($raw,$delivery);
            $delivery['shipmentItems'] = ShipmentOrderMapper::koctas($raw,$delivery);
            $items[] = ['order'=>$order,'deliveries'=>[$delivery]];
        }
        return ['items'=>$items,'complete'=>$next>=$total,'position'=>['offset'=>$next]];
    }

    private function pttavm(int $company, array $config, int $from, int $to, int $deadline): array
    {
        $zone = new \DateTimeZone('Europe/Istanbul');
        $date = static fn(int $at): string=>(new \DateTimeImmutable('@'.$at))->setTimezone($zone)->format('Y-m-d H:i:s');
        $result = ($this->marketplace ?? new MarketplaceReadClient())->orders('pttavm',$config,$date($from),$date($to),1,$deadline);
        $inventory = new InventoryOrderIdentity($this->db);
        $mapped = PttavmOrderMapper::batch($result['items'],$config['storeName'] ?? null,fn(string $id): string=>$inventory->catalogCode($company,$id));
        $items = array_map(static fn(array $item): array=>['order'=>$item['order'],'deliveries'=>[$item['delivery']]],$mapped);
        return ['items'=>$items,'complete'=>true,'position'=>[]];
    }

    private function trendyol(array $config, array $cursor, int $from, int $to, int $deadline): array
    {
        $result = ($this->trendyol ?? new TrendyolReadClient())->orders($config,$from*1000,$to*1000,$cursor['streamCursor'] ?? null,$deadline);
        $groups = [];
        foreach ($result['items'] as $package) {
            $number = TrendyolOrderMapper::identity($package['orderNumber'] ?? '');
            $groups[$number] = TrendyolOrderMapper::merge($package,$groups[$number]['delivery'] ?? []);
        }
        $items = array_map(static fn(array $item): array=>['order'=>$item['order'],'deliveries'=>[$item['delivery']]],array_values($groups));
        return ['items'=>$items,'complete'=>$result['complete'],'position'=>['streamCursor'=>$result['cursor']]];
    }

    private function hepsiburada(int $company, array $config, array $state, array $cursor, int $deadline, NewOrderRecordStore $store): array
    {
        $reader = $this->hepsiburada ?? new HepsiburadaReadClient();
        $credentials = HepsiburadaCredentials::fromArray($config);
        $feeds = HepsiburadaOrderDiscovery::FEEDS;
        $feed = (int)($cursor['feed'] ?? 0);
        $pending = (array)($cursor['pending'] ?? []);
        $items = [];
        if ($pending) {
            $number = (string)array_shift($pending);
            if (($cursor['target']['mode']??'')==='full'||!$store->seen($company,'hepsiburada',$number)) {
                $raw = $reader->order($credentials,$number,$deadline);
                if ((string)($raw['orderNumber'] ?? '')!==$number) throw new SyncFailure(422);
                $mapState = ['packageShipping'=>$state['packageShipping'] ?? []];
                $labels = $store->hepsiburadaShippingLabels($company,$credentials->merchantId,isset($cursor['packageLabels'])?$cursor:$state);
                $order = HepsiburadaPackageShipping::map($raw,$reader,$credentials,$deadline,$mapState,$labels);
                $items[] = HepsiburadaClaimProjection::apply($order,$state['fulfillmentClaims'] ?? []);
            }
            $cursor['pending'] = $pending;
            if(!$pending)unset($cursor['packageLabels']);
        } elseif (isset($feeds[$feed])) {
            $offset = (int)($cursor['offset'] ?? 0);
            $page = $reader->page($credentials,$feeds[$feed],$offset,$deadline);
            $numbers = HepsiburadaOrderDiscovery::numbers($page['items'],$credentials->merchantId);
            $pending = ($cursor['target']['mode']??'')==='full'?array_values($numbers):
                array_values(array_filter($numbers,fn(string $number): bool=>!$store->seen($company,'hepsiburada',$number)));
            if($pending && in_array($feeds[$feed],['packages','shipped'],true)){
                $labels=$store->hepsiburadaShippingLabels($company,$credentials->merchantId,[]);
                $labels->rememberFeed($page['items'],$feeds[$feed]==='packages');
                $labels->save($cursor);
            }
            $cursor = [...$cursor,'feed'=>$page['hasMore'] ? $feed : $feed+1,
                'offset'=>$page['hasMore'] ? $offset+10 : 0,'pending'=>$pending];
        }
        return ['cursor'=>$cursor,'done'=>empty($cursor['pending']) && !isset($feeds[(int)($cursor['feed'] ?? 0)]),'items'=>$items];
    }
}
