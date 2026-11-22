<?php
declare(strict_types=1);
namespace Omnigo;

/** The documented seller GET endpoint. Do not retain customer notes or addresses. */
final class TrendyolClaimClient
{
    public const WINDOW = 14 * 86400000;
    private const SIZE = 50;

    public function __construct(private QuestionReadTransport $transport = new QuestionReadTransport()) {}

    public function page(array $config, int $from, int $to, int $page, bool $created, int $deadline): array
    {
        $seller = (string)($config['supplierId'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $seller) || $from < 0 || $to < $from
            || $to - $from > self::WINDOW || $page < 0 || $page >= 400) throw new SyncFailure(422);

        $query = ['startDate'=>$from, 'endDate'=>$to, 'page'=>$page, 'size'=>self::SIZE];
        if ($created) $query['claimItemStatus'] = 'Created';
        $url = 'https://apigw.trendyol.com/integration/order/sellers/'.$seller.'/claims?'.http_build_query($query);
        $headers = ['Accept: application/json', 'User-Agent: '.$seller.' - OmnigoGlobal',
            'Authorization: Basic '.base64_encode($config['apiKey'].':'.$config['apiSecret'])];
        try {
            $body = json_decode($this->transport->read($url, $headers, $deadline), true, 48,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new SyncFailure(502);
        }
        if (!is_array($body)) throw new SyncFailure(422);
        $claims = $body['content'] ?? null;
        $total = $body['totalElements'] ?? null;
        $pages = $body['totalPages'] ?? null;
        if (array_key_exists('content', $body) && $claims === null && $total === 0 && $pages === 0
            && $page === 0 && ($body['size'] ?? null) === 0) {
            $claims = [];
        }
        if (!is_array($claims) || !array_is_list($claims) || !is_int($total) || $total < 0
            || ($body['page'] ?? null) !== $page || $pages !== (int)ceil($total/self::SIZE)
            || $pages > 400 || count($claims) !== max(0, min(self::SIZE, $total - $page*self::SIZE))) {
            throw new SyncFailure(422);
        }

        $items = [];
        $ids = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) throw new SyncFailure(422);
            $claimId = self::id($claim['claimId'] ?? $claim['id'] ?? null);
            $claimDate = self::timestamp($claim['claimDate'] ?? null);
            if ($claimDate < $from || $claimDate > $to || isset($ids[$claimId])) throw new SyncFailure(409);
            $ids[$claimId] = true;
            foreach (self::items($claim, $claimId, $claimDate) as $item) $items[] = $item;
        }
        return ['items'=>$items, 'ids'=>array_keys($ids), 'total'=>$total, 'complete'=>$page+1 >= $pages];
    }

    private static function items(array $claim, string $claimId, int $claimDate): array
    {
        $order = self::id($claim['orderNumber'] ?? null);
        $updated = self::timestamp($claim['lastModifiedDate'] ?? $claimDate);
        if (!is_array($claim['items'] ?? null) || !array_is_list($claim['items']) || !$claim['items']) {
            throw new SyncFailure(422);
        }
        $items = [];
        foreach ($claim['items'] as $line) {
            $orderLine = $line['orderLine'] ?? null;
            $units = $line['claimItems'] ?? null;
            if (!is_array($orderLine) || !is_array($units) || !array_is_list($units) || !$units) throw new SyncFailure(422);
            foreach ($units as $unit) {
                $status = ReturnRequestMapper::text($unit['claimItemStatus']['name'] ?? '', 60);
                if ($status === '') throw new SyncFailure(422);
                $items[] = [
                    'id'=>self::id($unit['id'] ?? null), 'claimId'=>$claimId, 'orderNumber'=>$order,
                    'lineId'=>self::id($unit['orderLineItemId'] ?? $orderLine['id'] ?? null), 'status'=>$status,
                    'createdAt'=>gmdate(DATE_ATOM, intdiv($claimDate, 1000)),
                    'updatedAt'=>gmdate(DATE_ATOM, intdiv($updated, 1000)), 'sourceUpdatedMs'=>$updated,
                    'quantity'=>1, 'amountCents'=>null, 'product'=>ReturnRequestMapper::text($orderLine['productName'] ?? '', 500),
                    'reason'=>ReturnRequestMapper::text($unit['customerClaimItemReason']['name'] ?? '', 180),
                ];
            }
        }
        return $items;
    }

    private static function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', (string)$value)) {
            throw new SyncFailure(422);
        }
        return (string)$value;
    }

    private static function timestamp(mixed $value): int
    {
        if (!is_int($value) || $value < 1000000000000 || $value > 9999999999999) throw new SyncFailure(422);
        return $value;
    }
}
