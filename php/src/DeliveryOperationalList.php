<?php
declare(strict_types=1);
namespace Omnigo;

/** Use one product projection for city groups and their counts. */
final class DeliveryOperationalList
{
    public function __construct(private Database $db, private DeliveryRepository $repository) {}

    private function orders(int $company,string $provider,bool $visibleOnly,bool $forProductRows=false,?string $city=null,?array $ids=null): \Generator
    {
        $where = 'company_id=? AND active=1';
        $params = [$company];
        if ($visibleOnly) $where .= ' AND '.MarketplaceVisibility::sql();
        if ($provider !== 'all') {
            $where .= ' AND provider=?';
            $params[] = $provider;
        }
        if($city!==null){
            // Imports index the source city, or the review bucket for an incomplete order.
            // Its current projected products can resolve an issue, so always recheck both.
            $where.=' AND city IN (?,?)';
            array_push($params,$city,'Diğer / Kontrol');
        }
        if($ids!==null){
            rsort($ids,SORT_NUMERIC);
            foreach(array_chunk($ids,200) as $chunk){
                $marks=implode(',',array_fill(0,count($chunk),'?'));
                $rows=$this->db->all("SELECT * FROM delivery_orders WHERE $where AND id IN ($marks) ORDER BY id DESC",[...$params,...$chunk]);
                yield from $this->repository->operationalBatch($rows,forProductRows:$forProductRows);
            }
            return;
        }
        $cursor = PHP_INT_MAX;
        do {
            $rows = $this->db->all('SELECT * FROM delivery_orders WHERE '.$where.' AND id<? ORDER BY id DESC LIMIT 250',
                [...$params, $cursor]);
            foreach ($this->repository->operationalBatch($rows,forProductRows:$forProductRows) as $order) {
                $cursor = $order['id'];
                yield $order;
            }
        } while (count($rows) === 250);
    }

    private static function identity(array $order): string
    {
        return $order['provider'].':'.($order['orderNumber'] ?: $order['externalId']);
    }

    public function pendingCount(int $company): int
    {
        $filters=new DeliveryFilters(DeliveryFilters::storeScope($this->db,$company,true));
        $pending=[];
        foreach($this->orders($company,'all',true) as $order){
            if(!empty($order['planningProducts']['pending'])&&$filters->matches($order,false))$pending[self::identity($order)]=true;
        }
        return count($pending);
    }

    /** Dispatch eligibility is evaluated before counting orders or paginating individual products. */
    public function packages(int $company,string $provider,string $mode,bool $visibleOnly,
        DeliveryFilters $filters,bool $forProductRows=false,?string $city=null,?array $ids=null): \Generator
    {
        foreach($this->orders($company,$provider,$visibleOnly,$forProductRows,$city,$ids) as $order){
            $products=DeliveryPlan::products($order);
            if(!$products || ($mode==='pending'&&$order['documentId']) || ($mode==='prepared'&&!$order['documentId'])
                || !$filters->matches([...$order,'products'=>$products],false))continue;
            $route=DeliveryDispatchScope::city($order);
            if($city===null||$route===$city)yield [$order,$products,$route];
        }
    }

    public function read(int $company, string $city, string $provider, string $mode, int $page, int $limit,
        bool $visibleOnly, array $filterValues = []): array
    {
        $filters = new DeliveryFilters($filterValues);
        $groups = $ordered = $counts = $parts = $seen = $allSeen = [];
        foreach ($this->packages($company,$provider,$mode,$visibleOnly,$filters) as [$order,$products,$route]) {
            $key = DeliveryDispatchScope::key($order);
            $identity = self::identity($order);
            if (!isset($seen[$key])) {
                $counts[$route] = ($counts[$route] ?? 0) + 1;
                $seen[$key] = true;
            }
            if (!isset($allSeen[$identity])) {
                $counts['all'] = ($counts['all'] ?? 0) + 1;
                $allSeen[$identity] = true;
            }
            $quantity = array_sum(array_column($products, 'quantity'));
            $parts[$route] = ($parts[$route] ?? 0) + $quantity;
            $parts['all'] = ($parts['all'] ?? 0) + $quantity;
            if (($city !== 'all' && $route !== $city) || !$filters->matchesCity($route)) continue;
            $ordered[$key] ??= count($ordered);
            if ($limit && ($ordered[$key] < $page * $limit || $ordered[$key] >= ($page + 1) * $limit)) continue;
            $item = DeliveryDispatchScope::item($order, $products, $filterValues['showCancellationMark']??false);
            $groups[$key] = isset($groups[$key]) ? DeliveryDispatchScope::merge($groups[$key], $item) : $item;
        }

        $total = count($ordered);
        return [
            'items' => array_values($groups), 'total' => $total, 'page' => $page, 'hasMore' => $limit > 0 && ($page + 1) * $limit < $total,
            'cityCounts' => $counts, 'cityProducts' => $parts,
        ];
    }
}
