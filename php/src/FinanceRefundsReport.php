<?php
declare(strict_types=1);
namespace Omnigo;

/** Tenant-scoped read model for claim status and separately evidenced marketplace repayments. */
final class FinanceRefundsReport
{
    private const SOURCES = [
        'hepsiburada' => 'Hepsiburada iade API’si',
        'n11' => 'n11 iade API’si',
        'idefix' => 'idefix iade API’si',
        'pazarama' => 'Pazarama sipariş satırı',
        'koctas' => 'Koçtaş iade API’si',
        'pttavm' => 'PTT AVM sipariş durumu',
        'trendyol' => 'Trendyol sipariş durumu',
    ];

    public function __construct(private Database $db) {}

    public function read(int $company, array $filters): array
    {
        $from = Validation::date($filters['from'] ?? null);
        $to = Validation::date($filters['to'] ?? null);
        ReportPeriod::range($from, $to);
        $channel = Validation::choice($filters['channel'] ?? 'all', ['all', ...Repository::CHANNELS]);
        MarketplaceVisibility::requireVisible($channel);
        $orderNumber = Validation::text($filters['orderNumber'] ?? null, 'Sipariş numarası', 180, true);
        $page = $this->pageInput($filters['page'] ?? 1);
        $pageSize = $this->pageInput($filters['pageSize'] ?? 20);
        if (!in_array($pageSize, [10, 20, 50], true)) Http::fail(400, 'Sayfa boyutu geçersiz.');

        $snapshots = $this->snapshots($company);
        $channels = $this->channels($company, $snapshots);
        $coverage = [];
        $matches = [];
        $totals = ['completedCents' => null, 'completedCount' => 0, 'approvedCount' => 0, 'unknownCount' => 0];

        foreach (ReturnRequestList::PROVIDERS as $provider => $label) {
            if (!in_array($label, $channels, true) || ($channel !== 'all' && $label !== $channel)) continue;
            $sync = $snapshots[$provider] ?? [];
            $state = $provider === 'hepsiburada' ? ($sync['financial'] ?? []) : $sync;
            $snapshot = $state['returnRequests'] ?? [];
            $orderStatuses = in_array($provider, ['pttavm', 'trendyol'], true);
            $items = $orderStatuses
                ? $this->orderStatusItems($company, $provider)
                : $this->claimItems($provider, $snapshot, $state);
            $undatedCount = 0;
            foreach ($items as $item) {
                $row = $this->row($provider, $label, $item);
                if ($row === null) continue;
                $date = $row['eventDate'];
                if ($date === null) $undatedCount++;
                if ($orderNumber !== '' && strcasecmp($row['orderNumber'], $orderNumber) !== 0) continue;
                if ($date === null) {
                    if ($orderNumber !== '') $matches[] = $row;
                    continue;
                }
                if ($date < $from || $date > $to) continue;
                $matches[] = $row;
                $totals[$row['state'].'Count']++;
                if ($row['state'] === 'completed') {
                    $totals['completedCents'] = ($totals['completedCents'] ?? 0) + $row['amountCents'];
                }
            }
            $coverage[] = $this->coverage($provider, $label, $snapshot, $state, $sync, $undatedCount, $from, $to);
        }

        usort($matches, static fn(array $a, array $b): int =>
            strcmp($b['eventDate'] ?? '', $a['eventDate'] ?? '')
            ?: strcmp($a['channel'], $b['channel'])
            ?: strcmp($a['orderNumber'], $b['orderNumber'])
            ?: strcmp($a['claimId'], $b['claimId'])
        );
        $total = count($matches);
        $page = min($page, max(1, (int)ceil($total / $pageSize)));
        $rows = array_slice($matches, ($page - 1) * $pageSize, $pageSize);
        return compact('from', 'to', 'channel', 'orderNumber', 'page', 'pageSize', 'total', 'channels', 'rows', 'totals', 'coverage');
    }

    private function pageInput(mixed $value): int
    {
        $valid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($valid === false) Http::fail(400, 'Sayfa seçimi geçersiz.');
        return $valid;
    }

    private function snapshots(int $company): array
    {
        $result = [];
        foreach (array_keys(self::SOURCES) as $provider) {
            $row = $this->db->one('SELECT payload FROM records WHERE id = (
                SELECT MAX(id) FROM records WHERE company_id=? AND kind=?
            )', [$company, 'sync_'.$provider]);
            if ($row) $result[$provider] = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    private function channels(int $company, array $snapshots): array
    {
        $configured = (new Repository($this->db))->records($company, 'channels', ['role' => 'admin']);
        $records = array_column($configured, null, 'name');
        $names = [];
        foreach ($configured as $channel) {
            if (!empty($channel['hidden'])) continue;
            if (($channel['name'] ?? '') === 'Trendyol' && empty($channel['selected']) && !isset($snapshots['trendyol'])) continue;
            $names[] = $channel['name'] ?? '';
        }
        foreach (array_keys($snapshots) as $provider) {
            $name = ReturnRequestList::PROVIDERS[$provider];
            // Stored evidence remains visible for previously configured providers, subject to explicit hiding.
            if (empty($records[$name]['hidden'])) $names[] = $name;
        }
        $supported = array_intersect_key(ReturnRequestList::PROVIDERS, self::SOURCES);
        return array_values(array_filter(Repository::CHANNELS, static fn(string $name): bool =>
            MarketplaceVisibility::visible($name) && in_array($name, $supported, true) && in_array($name, $names, true)
        ));
    }

    private function claimItems(string $provider, array $snapshot, array $state): iterable
    {
        $items = [];
        foreach ($snapshot['items'] ?? [] as $item) {
            if (is_array($item) && (string)($item['id'] ?? '') !== '') {
                unset($item['refundedAt']);
                $items[(string)$item['id']] = $item;
            }
        }
        if ($provider === 'hepsiburada') {
            // The claim listing omits the payment date. The financial scan retains the verified refund.
            foreach ($state['refunds'] ?? [] as $refund) {
                if (!is_array($refund) || (string)($refund['id'] ?? '') === '') continue;
                $id = (string)$refund['id'];
                if (($refund['status'] ?? '') !== 'Refunded') continue;
                if (isset($items[$id])) {
                    $claim = $items[$id];
                    if (($claim['orderNumber'] ?? null) !== ($refund['orderNumber'] ?? null)
                        || ($claim['status'] ?? null) !== 'Refunded') continue;
                }
                $items[$id] = [...($items[$id] ?? []), ...$refund];
            }
        }
        return $items;
    }

    private function orderStatusItems(int $company, string $provider): iterable
    {
        $projection = $provider === 'pttavm' ? PttavmReturnProjection::class : TrendyolReturnProjection::class;
        // Prefilter stored status tokens so ordinary order payloads stay out of the read model.
        $tokens = $provider === 'pttavm' ? ['%"iade"%', '%"gondericisine_teslim_edildi"%'] : ['%"returned"%'];
        $statusFilter = implode(' OR ', array_fill(0, count($tokens), 'payload LIKE ?'));
        if (RecentOrderSchema::available($this->db)) {
            $records = $this->db->run("SELECT payload FROM recent_orders WHERE company_id=? AND provider=? AND ($statusFilter)", [$company, $provider, ...$tokens]);
        } elseif ($provider === 'pttavm') {
            $records = $this->db->run("SELECT payload FROM records WHERE company_id=? AND kind='order_pttavm' AND ($statusFilter)", [$company, ...$tokens]);
        } else {
            return;
        }
        // These sources only report undated order status. The same scan provides coverage counts and exact-order lookup.
        foreach ($records as $record) {
            $order = json_decode($record['payload'], true, 64, JSON_THROW_ON_ERROR);
            yield from $projection::orders([$order]);
        }
    }

    private function row(string $provider, string $channel, array $item): ?array
    {
        if ($provider === 'hepsiburada' && !HepsiburadaClaims::reportsReturn($item)) return null;
        $claimId = ReturnRequestMapper::text($item['id'] ?? null);
        $orderNumber = ReturnRequestMapper::text($item['orderNumber'] ?? null);
        $status = (string)($item['status'] ?? '');
        if ($claimId === '' || $orderNumber === '' || $status === '') return null;
        [$group, $statusLabel] = ReturnRequestMapper::status($provider, $status);
        if (in_array($group, ['rejected', 'cancelled'], true)) return null;
        $source = self::SOURCES[$provider];

        $paymentDate = $provider === 'hepsiburada' && $status === 'Refunded' ? self::date($item['refundedAt'] ?? null) : null;
        $amount = $item['amountCents'] ?? null;
        if ($paymentDate !== null && is_int($amount) && $amount >= 0 && $amount <= 1000000000000) {
            return [...compact('claimId', 'orderNumber', 'channel', 'source', 'statusLabel'),
                'eventDate' => $paymentDate, 'dateKind' => 'payment', 'amountCents' => $amount, 'state' => 'completed'];
        }

        $claimDate = self::date($item['createdAt'] ?? null);
        $statusDate = self::date($item['updatedAt'] ?? null);
        $state = $group === 'approved' && !in_array($provider, ['pttavm', 'trendyol'], true) ? 'approved' : 'unknown';
        // No price, requested amount or order timestamp is used as proof of a repayment.
        return [...compact('claimId', 'orderNumber', 'channel', 'source', 'statusLabel', 'state'),
            'eventDate' => $statusDate ?? $claimDate, 'dateKind' => $statusDate !== null ? 'status' : ($claimDate !== null ? 'claim' : null), 'amountCents' => null];
    }

    private function coverage(string $provider, string $channel, array $snapshot, array $state, array $sync, int $undatedCount, string $selectedFrom, string $selectedTo): array
    {
        $source = self::SOURCES[$provider];
        $from = self::date($snapshot['from'] ?? $state['from'] ?? $sync['startDate'] ?? null);
        $to = self::date($snapshot['through'] ?? $state['through'] ?? $sync['watermark'] ?? null);
        $checkedAt = $snapshot['checkedAt'] ?? $state['lastSuccessAt'] ?? $sync['lastSuccessAt'] ?? null;
        if (self::date($checkedAt) === null) $checkedAt = null;
        $paymentEvidence = $provider === 'hepsiburada' && isset($state['refunds']) && self::date($state['lastSuccessAt'] ?? null) !== null;
        $hasData = $checkedAt !== null || $undatedCount > 0;
        $wholePeriod = $from !== null && $to !== null && $from <= $selectedFrom && $to >= $selectedTo;
        $hasError = !empty($state['returnRequestError']) || !empty($state['error']) || !empty($state['failure']);
        $coverageState = !$hasData ? 'unavailable' : ($paymentEvidence && $wholePeriod && !$hasError && $undatedCount === 0 ? 'available' : 'partial');
        return [...compact('channel', 'source', 'from', 'to', 'checkedAt', 'paymentEvidence', 'undatedCount'), 'state' => $coverageState];
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $parts = array_map('intval', explode('-', $value));
            return checkdate($parts[1], $parts[2], $parts[0]) ? $value : null;
        }
        $iso = ReturnRequestMapper::date($value);
        return $iso === null ? null : SalesCalendar::day($iso);
    }
}
