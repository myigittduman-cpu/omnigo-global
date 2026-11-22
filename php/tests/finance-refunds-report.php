<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, FinanceRefundsReport, HttpError, RecentOrderSchema};

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message.' '.json_encode([$expected, $actual], JSON_UNESCAPED_UNICODE));
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES (1,'Birinci mağaza'),(2,'İkinci mağaza')");
$stamp = '2026-11-16T08:00:00+03:00';
$add = static function (int $company, string $kind, array $payload) use ($db, $stamp): void {
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)', [$company, $kind, json_encode($payload, JSON_THROW_ON_ERROR), $stamp]);
};
foreach (['Hepsiburada', 'n11', 'idefix', 'Pazarama', 'Koçtaş', 'PTT AVM', 'Teknosa', 'Trendyol'] as $name) {
    $add(1, 'channels', ['name' => $name, 'status' => 'active', 'selected' => $name !== 'Trendyol']);
}
$source = static fn(array $items): array => ['items' => $items, 'from' => '2026-11-01', 'through' => '2026-11-16', 'checkedAt' => $stamp];
$hb = static fn(string $id, string $order, string $status, array $extra = []): array => [
    'id' => $id, 'orderNumber' => $order, 'status' => $status, 'claimType' => 'Return',
    'orderedAt' => '2026-10-20T00:00:00+03:00',
    ...$extra,
];
$approved = $hb('2', 'HB-2', 'Accepted', ['createdAt' => '2026-11-05T10:00:00+03:00']);
$dated = $hb('1', 'HB-1', 'Refunded', ['amountCents' => 12500, 'refundedAt' => '2026-11-06T22:30:00Z']);
$invoice = $hb('3', 'HB-invoice', 'Refunded', ['claimType' => 'MissingInvoice', 'amountCents' => 200, 'refundedAt' => $stamp]);
$undelivered = $hb('4', 'HB-undelivered', 'Refunded', ['claimType' => 'UndeliveredProduct', 'amountCents' => 999, 'refundedAt' => '2026-11-07T09:00:00+03:00']);
$snapshotOnly = $hb('5', 'HB-unknown', 'Refunded', ['amountCents' => 78000, 'refundedAt' => '2026-11-07T12:00:00+03:00']);
$add(1, 'sync_hepsiburada', ['financial' => [
    'returnRequests' => $source([$approved, $dated, $invoice, $undelivered, $snapshotOnly]),
    'refunds' => ['1' => $dated, '3' => $invoice, '4' => $undelivered],
    'lastSuccessAt' => $stamp, 'from' => '2026-11-01', 'through' => '2026-11-16',
]]);
$add(2, 'sync_hepsiburada', ['financial' => [
    'returnRequests' => $source([]), 'lastSuccessAt' => $stamp, 'refunds' => ['1' => $dated],
]]);
$add(1, 'sync_n11', ['returnRequests' => $source([
    ['id' => '10', 'orderNumber' => 'N11-1', 'status' => 'APPROVED', 'createdAt' => '2026-11-03', 'amountCents' => 5499, 'buyerName' => 'Private name'],
    ['id' => '11', 'orderNumber' => 'N11-2', 'status' => 'MANUAL_REFUND', 'createdAt' => '2026-11-04', 'amountCents' => 5000],
    ['id' => '12', 'orderNumber' => 'N11-3', 'status' => 'DENIED', 'createdAt' => '2026-11-05'],
])]);
$add(1, 'sync_idefix', ['returnRequests' => $source([
    ['id' => '20', 'orderNumber' => 'IDE-1', 'status' => 'approved', 'updatedAt' => '2026-11-04', 'amountCents' => 15000],
])]);
$add(1, 'sync_koctas', ['returnRequests' => $source([
    ['id' => '30', 'orderNumber' => 'KC-1', 'status' => 'CLOSED', 'createdAt' => '2026-11-04', 'amountCents' => 15000],
])]);
$add(1, 'sync_pazarama', ['returnRequests' => $source([
    ['id' => '40', 'orderNumber' => 'PZ-1', 'status' => '10', 'orderedAt' => '2026-11-03', 'amountCents' => 15000],
])]);
$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)', [1, 'pttavm', 'PTT-1', json_encode([
    'externalId' => 'PTT-1', 'createdAt' => '2026-11-05T12:00:00+03:00', 'products' => [
        ['lineId' => '50', 'status' => 'iade', 'quantity' => 1, 'title' => 'Private product name', 'returnCents' => 3000],
    ],
], JSON_THROW_ON_ERROR), $stamp]);

$report = new FinanceRefundsReport($db);
$period = ['from' => '2026-11-01', 'to' => '2026-11-15'];
$result = $report->read(1, $period);
same(['Hepsiburada', 'n11', 'idefix', 'PTT AVM', 'Pazarama', 'Koçtaş'], $result['channels'], 'Only eligible configured marketplace filters');
same(['completedCents' => 13499, 'completedCount' => 2, 'approvedCount' => 3, 'unknownCount' => 2], $result['totals'], 'Amounts use distinct dated verified repayments only');
same(7, $result['total'], 'Undated, rejected, and invoice items do not leak into the period');
same(false, str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'Private'), 'Private order and claim fields never leave the report');
$rows = array_column($result['rows'], null, 'orderNumber');
same(['2026-11-07', 'payment', 12500, 'completed'], array_values(array_intersect_key($rows['HB-1'], array_flip(['eventDate', 'dateKind', 'state', 'amountCents']))), 'Payment date uses Turkish calendar');
same('approved', $rows['N11-1']['state'], 'An approved n11 claim stays approved');
same('unknown', $rows['N11-2']['state'], 'n11 source status does not prove paid money');
same('Manuel geri ödeme', $rows['N11-2']['statusLabel'], 'Marketplace status remains available independently');
same(null, $rows['IDE-1']['amountCents'], 'idefix order price is not repayment proof');
same('unknown', $rows['KC-1']['state'], 'Closed claim status does not prove payment');
$coverage = array_column($result['coverage'], null, 'channel');
same(true, $coverage['Hepsiburada']['paymentEvidence'], 'Hepsiburada retains payment evidence');
same(1, $coverage['Hepsiburada']['undatedCount'], 'Only financial snapshot may prove completion');
same(1, $coverage['Pazarama']['undatedCount'], 'Missing date is visible in coverage');
same(false, $coverage['n11']['paymentEvidence'], 'n11 stored claims lack independent payment proof');
$pazarama = $report->read(1, [...$period, 'channel' => 'Pazarama', 'orderNumber' => 'PZ-1']);
same(1, $pazarama['total'], 'Exact lookup can show an undated claim');
same(null, $pazarama['rows'][0]['eventDate'], 'Order timestamp is not a repayment event');
same(null, $pazarama['totals']['completedCents'], 'An empty verified sum is unknown, not zero');
same(0, $pazarama['totals']['unknownCount'], 'Undated claims stay outside dated totals');
same(0, $report->read(1, [...$period, 'orderNumber' => 'PZ'])['total'], 'Lookup matches exact order number');
$ptt = $report->read(1, [...$period, 'channel' => 'PTT AVM', 'orderNumber' => 'PTT-1']);
same('unknown', $ptt['rows'][0]['state'], 'A returned parcel is not a repayment confirmation');
same(null, $ptt['rows'][0]['amountCents'], 'PTT sales deduction is not repayment proof');
same(1, $report->read(2, $period)['total'], 'Other tenant results stay isolated');

$entries = [];
for ($i = 0; $i < 22; $i++) {
    $entries[] = ['id' => 'paged-'.$i, 'orderNumber' => 'N11-'.$i, 'status' => 'APPROVED', 'createdAt' => '2026-11-03'];
}
$db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_n11'", [json_encode(['returnRequests' => $source($entries)], JSON_THROW_ON_ERROR)]);
$paged = $report->read(1, [...$period, 'channel' => 'n11', 'page' => '2', 'pageSize' => '10']);
same(22, $paged['total'], 'Counts precede pagination');
same(2, $paged['page'], 'Page starts at one');
same(10, count($paged['rows']), 'Page size applies to matched results');
same(22, $paged['totals']['approvedCount'], 'Period totals span all filtered pages');
foreach ([
    [['channel' => 'Teknosa'], 404],
    [['pageSize' => 11], 400],
    [['page' => 0], 400],
    [['from' => '2024-01-01'], 400],
    [['orderNumber' => ['bad']], 400],
] as [$bad, $status]) {
    try {
        $report->read(1, [...$period, ...$bad]);
        throw new RuntimeException('Invalid filter accepted');
    } catch (HttpError $error) {
        same($status, $error->getCode(), 'Invalid filter is rejected');
    }
}

echo "İade kaynağı, finansal teyit, tarih, sipariş filtresi, sayfalama ve mağaza ayrımı doğrulandı.\n";
