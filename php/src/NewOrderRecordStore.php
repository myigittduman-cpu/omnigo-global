<?php
declare(strict_types=1);
namespace Omnigo;

/** Adds orders atomically while respecting every existing operational identity. */
final class NewOrderRecordStore
{
    public function __construct(private Database $db, private DeliveryRepository $deliveries) {}

    public function hepsiburadaShippingLabels(int $company,string $merchant,array $state): HepsiburadaShippingLabelCache
    {
        return $this->deliveries->hepsiburadaShippingLabels($company,$merchant,$state);
    }

    public static function knownIds(Database $db,int $company,string $provider,int $from,int $through): ?array
    {
        $at=gmdate('c',$from-300);
        $rows=$db->all("SELECT payload FROM recent_orders WHERE company_id=? AND provider=? AND updated>=?
            UNION ALL SELECT payload FROM records WHERE company_id=? AND kind=? AND updated>=? LIMIT 10001",
            [$company,$provider,$at,$company,'order_'.$provider,$at]);
        if(count($rows)>10000)return null;
        $ids=[];
        foreach($rows as $row){
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            $date=strtotime($order['createdAt']??'');
            if(($order['orders']??0)>0&&is_string($order['externalId']??null)&&$date!==false&&$date>=$from&&$date<=$through){
                $ids[hash('sha256',$order['externalId'])]=true;
            }
        }
        return array_keys($ids);
    }

    public function insert(int $company, string $provider, array $items, array $window, callable $check, ?callable $checkpoint=null): int
    {
        $write = function () use ($company,$provider,$items,$window,$check,$checkpoint): int {
            if (!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company])) throw new SyncFailure(404);
            $check();
            $count = 0;
            foreach ($items as $item) {
                $order = $item['order'];
                $number = $order['externalId'] ?? '';
                $created = strtotime($order['createdAt'] ?? '');
                if (!is_string($number) || trim($number)==='' || strlen($number)>180 || $created===false) throw new SyncFailure(422);
                if (($order['orders'] ?? 0)<1 || $created<$window['from'] || $created>$window['through']) continue;
                $target=$window['target']??[];
                if(($target['mode']??'')==='full'){
                    $count+=TableOrderReplay::restore($this->db,$company,$target['table'],$provider,$item,$target['requestedAt']);
                }
                $seen=$this->seen($company,$provider,$number,$item['deliveries']);
                if ($seen && (($target['mode']??'')!=='full'||!in_array($target['table'],['orders','returns'],true)||$this->hasSource($company,$provider,$number)))continue;

                if(!$seen)foreach ($item['deliveries'] as $delivery) $this->deliveries->ingest($company,$delivery);
                $payload = json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if ($provider==='koctas') {
                    // The ordinary Koçtaş worker uses this same financial record when building rollups.
                    $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
                        [$company,'order_koctas',$payload,gmdate('c')]);
                } else {
                    $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',
                        [$company,$provider,$number,$payload,gmdate('c')]);
                }
                $count++;
            }
            if($checkpoint!==null)$checkpoint($count);
            return $count;
        };
        return $this->db->pdo->inTransaction() ? $write() : TransactionRetry::run($this->db,$write);
    }

    private function hasSource(int $company,string $provider,string $number): bool
    {
        $external = $this->db->mysql() ? "JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))" : "JSON_EXTRACT(payload,'$.externalId')";
        return (bool)($this->db->one('SELECT id FROM recent_orders WHERE company_id=? AND provider=? AND external_id=?',[$company,$provider,$number])
            ||$this->db->one("SELECT id FROM records WHERE company_id=? AND kind=? AND $external=? LIMIT 1",[$company,'order_'.$provider,$number]));
    }

    public function seen(int $company, string $provider, string $number, array $deliveries = []): bool
    {
        if($this->hasSource($company,$provider,$number))return true;
        $channel = CustomerMarketplaces::PROVIDERS[$provider];
        $deliveryIds = array_values(array_unique([$number,...array_column($deliveries,'externalId')]));
        $marks = implode(',',array_fill(0,count($deliveryIds),'?'));
        if ($this->db->one("SELECT id FROM delivery_orders WHERE company_id=? AND provider=? AND external_id IN ($marks) LIMIT 1",
            [$company,$channel,...$deliveryIds])) return true;

        $numbers = array_values(array_unique([$number,...array_column($deliveries,'orderNumber')]));
        $orders = array_map(static fn(string $value): string=>hash('sha256',$value),$numbers);
        $marks = implode(',',array_fill(0,count($orders),'?'));
        if ($this->db->one("SELECT id FROM order_notifications WHERE company_id=? AND provider=? AND order_key IN ($marks) LIMIT 1",
            [$company,$provider,...$orders])) return true;

        $sources = [];
        foreach ($deliveries as $delivery) foreach ($delivery['shipmentItems'] ?? [] as $shipment) {
            $source = hash('sha256',$channel.':'.$shipment['key']);
            $sources[$source] = true;
        }
        if (!$sources) return false;
        $keys = array_keys($sources);
        $marks = implode(',',array_fill(0,count($keys),'?'));
        // A source-linked row is evidence of previous intake even after manual removal.
        return (bool)$this->db->one("SELECT id FROM shipment_entries WHERE company_id=? AND source_key IN ($marks) LIMIT 1",
            [$company,...$keys]);
    }
}
