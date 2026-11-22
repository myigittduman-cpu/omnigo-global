<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{
    Database, FinanceDeductionsReport, FinanceOverviewRoutes, FinanceSchema, FinanceStore,
    HttpError, PazaramaCommissionStore, Repository, Security
};

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function reject(callable $action, int $status): void
{
    try {
        $action();
    } catch (HttpError $error) {
        verify($error->getCode() === $status, $error->getMessage());
        return;
    }
    throw new RuntimeException('Geçersiz işlem reddedilmedi.');
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo = new Repository($db);
$first = $repo->createCompany('Örnek mağaza');
$second = $repo->createCompany('Diğer mağaza');
$admin = ['id' => 1, 'role' => 'admin'];
$client = ['id' => 2, 'role' => 'client', 'company_id' => $first];
$fees = new FinanceDeductionsReport($db);
$filters = ['from' => '2026-10-01', 'to' => '2026-10-31', 'channel' => 'all'];

$line = static fn(string $section, string $label, ?int $cents, ?int $rate = null): array =>
    ['section' => $section, 'label' => $label, 'amountCents' => $cents, 'rateBasisPoints' => $rate];
$insert = static function(int $company, string $channel, array $details, array $extra = []) use ($db): int {
    $data = [
        'channel' => $channel, 'status' => 'pending', 'expectedDate' => '2026-11-03',
        'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-05', 'expectedCents' => 900000,
        'verification' => 'confirmed', 'verificationSource' => 'Örnek satıcı ekstresi', 'source' => 'Omnigo girişi',
        'taxBasis' => 'included', 'details' => $details, ...$extra,
    ];
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
        [$company, 'payments', json_encode($data, JSON_THROW_ON_ERROR), '2026-11-03T07:00:00Z']);
    return $db->insertId();
};
$row = static function(array $report, int $id, string $label): array {
    foreach ($report['items'] as $item) {
        if ($item['paymentId'] === $id && $item['label'] === $label) return $item;
    }
    throw new RuntimeException('Beklenen kalem bulunamadı: '.$label);
};

$firstStatement = $insert($first, 'Hepsiburada', [
    $line('sales', 'Satış tutarı', 250000),
    $line('commission', 'Komisyon kesintisi', 5000, 1000),
    $line('service', 'Hizmet bedeli', 0),
    $line('tax', 'KDV Tutarı', 1500),
    $line('adjustment', 'Tahmini toplam gider', 7000),
    $line('commission', 'Komisyon tutarı', null, 1250),
]);
$insert($first, 'Hepsiburada', [
    $line('commission', 'Komisyon kesintisi', -500),
    $line('tax', 'E-Ticaret stopajı', 250),
], ['periodStart' => '2026-10-06', 'periodEnd' => '2026-10-08']);
$estimated = $insert($first, 'n11', [$line('commission', 'Komisyon Tutarı', 4000)],
    ['verification' => 'estimated', 'verificationSource' => '']);
$undated = $insert($first, 'PTT AVM', [$line('commission', 'Komisyon Kesintisi', 700)],
    ['periodStart' => '', 'periodEnd' => '', 'expectedDate' => '2026-10-10', 'paidDate' => '2026-10-11']);
$outside = $insert($first, 'Pazarama', [$line('commission', 'Komisyon Tutarı', 3000)],
    ['periodStart' => '2026-09-28', 'periodEnd' => '2026-10-02']);
$unclear = $insert($first, 'Koçtaş', [$line('service', 'Abonelik', 2000)], ['taxBasis' => 'mixed']);
$overlapA = $insert($first, 'idefix', [$line('tax', 'Stopaj', 200)],
    ['periodStart' => '2026-10-08', 'periodEnd' => '2026-10-12']);
$overlapB = $insert($first, 'idefix', [$line('tax', 'Stopaj', 200)],
    ['periodStart' => '2026-10-12', 'periodEnd' => '2026-10-15']);
$insert($first, 'Hepsiburada', [$line('commission', 'Komisyon kesintisi', 999999)], ['integration' => 'hepsiburada']);
$insert($first, 'Hepsiburada', [$line('commission', 'Komisyon kesintisi', 999999)], ['archivedAt' => '2026-11-04']);
$insert($first, 'Teknosa', [$line('commission', 'Komisyon kesintisi', 999999)]);
$insert($second, 'Hepsiburada', [$line('commission', 'Komisyon kesintisi', 999999)]);

$result = $fees->read($admin, $first, $filters);
verify($result['summary']['commissionCents'] === 4500 && $result['summary']['serviceCents'] === 0
    && $result['summary']['taxCents'] === 250 && $result['summary']['adjustmentCents'] === null
    && $result['summary']['totalCents'] === 4750, 'İşaretli kalem ve açık sıfır korunmalı, boş kategori sıfıra dönmemeli.');
verify($result['summary']['coverage'] === 'partial' && $result['summary']['officialCoverage'] === 'unavailable'
    && $result['officialSummary']['commissionCents'] === null, 'Elle kaydedilen kalemler resmi kapsama dönüşmemeli.');
verify($result['totalItems'] === 13 && !in_array('Teknosa', $result['marketplaces'], true),
    'Arşiv, net hakediş, satış satırı, gizli pazaryeri ve diğer firma dışarıda kalmalı.');
verify($row($result, $firstStatement, 'Komisyon tutarı')['amountCents'] === null
    && $row($result, $firstStatement, 'Komisyon tutarı')['rateBasisPoints'] === 1250, 'Oran, tutar olmadan da gösterilmeli.');
verify($row($result, $firstStatement, 'KDV Tutarı')['subtotalExclusion'] === 'reference_only'
    && $row($result, $firstStatement, 'Tahmini toplam gider')['subtotalExclusion'] === 'reference_only',
    'Olası çift KDV veya toplam kalemleri ara toplama eklenmemeli.');
verify($row($result, $estimated, 'Komisyon Tutarı')['subtotalExclusion'] === 'unverified'
    && $row($result, $unclear, 'Abonelik')['subtotalExclusion'] === 'tax_basis'
    && $row($result, $outside, 'Komisyon Tutarı')['subtotalExclusion'] === 'partial_period',
    'Eksik kaynak, kapsam ve KDV bilgisi görünür kalmalı.');
verify($row($result, $overlapA, 'Stopaj')['subtotalExclusion'] === 'overlap'
    && $row($result, $overlapB, 'Stopaj')['subtotalExclusion'] === 'overlap',
    'Aynı pazaryerinin kesişen ekstreleri çift sayılmamalı.');
$paymentFallback = $row($result, $undated, 'Komisyon Kesintisi');
verify($paymentFallback['date'] === '2026-10-10' && $paymentFallback['dateBasis'] === 'expected'
    && $paymentFallback['subtotalExclusion'] === 'no_statement_period', 'Ödeme tarihi ekstre dönemi yerine sayılmamalı.');
verify($fees->read($admin, $first, [...$filters, 'pageSize' => '10', 'page' => '2'])['items']
    === array_slice($result['items'], 10), 'Sayfalama sırayı ve toplamları korumalı.');

$insert($first, 'n11', [$line('commission', 'Komisyon Tutarı', 1300)],
    ['periodStart' => '2026-10-07', 'periodEnd' => '2026-10-09', 'taxBasis' => 'excluded']);
$mixed = $fees->read($admin, $first, $filters);
verify($mixed['summary']['commissionCents'] === null && $mixed['summary']['totalCents'] === null
    && $mixed['summary']['taxBasis'] === 'mixed', 'Farklı KDV esasları tek tutarda birleşmemeli.');
$empty = $fees->read($admin, $first, [...$filters, 'channel' => 'Pazarama', 'from' => '2026-10-03']);
verify($empty['items'] === [] && $empty['summary']['coverage'] === 'unavailable'
    && $empty['summary']['totalCents'] === null, 'Boş dönem sıfır kesinti sayılmamalı.');

FinanceSchema::install($db);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'integration_pazarama','{}',?)",
    [$first, '2026-11-03T07:00:00Z']);
$officialStore = new PazaramaCommissionStore($db);
(new FinanceStore($db))->writeState($first, PazaramaCommissionStore::PROVIDER, ['published' => [
    'sourceVersion' => $officialStore->sourceVersion($first), 'from' => '2026-10-01', 'to' => '2026-10-15',
    'updatedAt' => '2026-11-03T07:00:00Z', 'days' => ['2026-10-06' => 1200, '2026-10-09' => -200],
]], null);
$manualCommission = $insert($first, 'Pazarama', [
    $line('commission', 'Komisyon Tutarı', 1200),
    $line('service', 'Hizmet Ücreti', 750),
], ['periodStart' => '2026-10-06', 'periodEnd' => '2026-10-08']);
$insert($first, 'Pazarama', [$line('commission', 'Komisyon Tutarı', 300)],
    ['periodStart' => '2026-10-22', 'periodEnd' => '2026-10-24']);
$pazarama = $fees->read($admin, $first, [...$filters, 'channel' => 'Pazarama']);
verify($pazarama['officialSummary']['coverage'] === 'partial' && $pazarama['officialSummary']['commissionCents'] === 1000
    && $pazarama['officialSummary']['recordCount'] === 2 && $pazarama['source'] === 'mixed',
    'Yalnız kaynağın doğrulanmış kısmı ve işaretli komisyon hareketleri gösterilmeli.');
verify($pazarama['summary']['totalCents'] === 1050 && $pazarama['summary']['serviceCents'] === 750
    && $pazarama['summary']['commissionCents'] === 300
    && $row($pazarama, $manualCommission, 'Komisyon Tutarı')['subtotalExclusion'] === 'separate_source',
    'Aynı dönemdeki elle kaydedilen komisyon tekrar toplanmamalı; diğer tutarlar elle girilen ara toplamda kalmalı.');
$apiItem = array_values(array_filter($pazarama['items'], static fn(array $item): bool => $item['source'] === 'marketplace_api'))[0];
verify($apiItem['dateBasis'] === 'transaction' && $apiItem['taxBasis'] === 'unspecified',
    'Resmi kalem hareket tarihini ve belirtilmemiş KDV esasını korumalı.');
$provenZero = $fees->read($admin, $first, [...$filters, 'channel' => 'Pazarama', 'from' => '2026-10-10', 'to' => '2026-10-12']);
verify($provenZero['officialSummary']['coverage'] === 'complete' && $provenZero['officialSummary']['commissionCents'] === 0,
    'Tam doğrulanmış boş aralık açık sıfır göstermeli.');
$unknown = $fees->read($admin, $first, [...$filters, 'channel' => 'Pazarama', 'from' => '2026-10-26']);
verify($unknown['officialSummary']['coverage'] === 'unavailable' && $unknown['officialSummary']['commissionCents'] === null,
    'Resmi kapsamın dışı sıfır sayılmamalı.');
$financeStore = new FinanceStore($db);
$financeState = $financeStore->state($first, PazaramaCommissionStore::PROVIDER);
$financePayload = json_decode($financeState['payload'], true, 32, JSON_THROW_ON_ERROR);
$financePayload['published']['unreconciled'] = [['from'=>'2026-10-01','to'=>'2026-10-15']];
$financeStore->writeState($first, PazaramaCommissionStore::PROVIDER, $financePayload, $financeState);
$unreconciled = $fees->read($admin, $first, [...$filters, 'channel'=>'Pazarama']);
verify($unreconciled['officialSummary']['commissionCents'] === null && $unreconciled['officialSummary']['reconciled'] === false
    && $unreconciled['officialSummary']['recordCount'] === 2
    && in_array('source_total_unconfirmed', array_column($unreconciled['items'],'verification'), true),
    'Pazaryerinin kaynak satırları, uyuşmayan dönem toplamından bağımsız gösterilmeli.');

$routes = new FinanceOverviewRoutes($db, new Security($db, ['origin' => 'https://portal.example.test']));
$_GET = ['company' => (string)$first, ...$filters];
foreach (['/api/finance/deductions', '/api/finance/refunds'] as $path) {
    reject(fn() => $routes->handle($path, 'GET', $client), 403);
    reject(fn() => $routes->handle($path, 'PUT', $admin), 405);
}
reject(fn() => $fees->read($client, $first, $filters), 403);
reject(fn() => $fees->read($admin, $first, [...$filters, 'pageSize' => '5000']), 400);
reject(fn() => $fees->read($admin, $first, [...$filters, 'from' => '2024-01-01']), 400);
reject(fn() => $fees->read($admin, $first, [...$filters, 'channel' => 'Teknosa']), 404);
$_GET = ['company' => '99999', ...$filters];
reject(fn() => $routes->handle('/api/finance/deductions', 'GET', $admin), 404);

echo "Komisyon ve kesintiler: açık sıfır, ekstre kapsamı, KDV esası, kaynak ayrımı, yetki, mağaza ayrımı ve sayfalama doğrulandı.\n";
