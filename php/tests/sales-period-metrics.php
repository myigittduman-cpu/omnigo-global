<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database, MarketplaceSalesSummary, OverviewPaymentPeriods, Repository, SalesPeriodMetrics};

$clock=new DateTimeImmutable('2026-11-12T12:00:00+03:00');
$windows=SalesPeriodMetrics::windows($clock);
if(array_map(static fn($date)=>$date->format('Y-m-d'),$windows['previousMonth'])!==['2026-10-01','2026-10-31'])
    throw new RuntimeException('Geçen ayın İstanbul takvim sınırları.');
$approved=['channel'=>'Koçtaş','status'=>'paid','actualCents'=>50000,'paymentApproval'=>['at'=>'2026-11-11']];
$payments=OverviewPaymentPeriods::summarize([
    [...$approved,'paidDate'=>'2026-11-10','expectedDate'=>'2026-10-30'],
    [...$approved,'paidDate'=>'2026-10-31','actualCents'=>40000],
    [...$approved,'paidDate'=>'2026-11-11','paymentApproval'=>null],
    [...$approved,'paidDate'=>'2026-10-01','excludedFromTotals'=>true],
    [...$approved,'paidDate'=>'2026-11-11','channel'=>'Hepsiburada'],
    [...$approved,'paidDate'=>'2026-11-28'],
],['Koçtaş'],$clock);
if($payments['month']['paidCents']!==50000||$payments['previousMonth']['paidCents']!==40000)
    throw new RuntimeException('Ay karşılaştırması banka tarihi ve onaylı kayıtla sınırlı olmalı.');
$order=static fn(string $day,int $amount,int $refund):array=>[
    'at'=>new DateTimeImmutable($day.'T09:00:00+03:00'),'orders'=>1,'grossCents'=>$amount,'cancelCents'=>0,
    'returnCents'=>$refund,'turnoverCents'=>$amount,'salesTurnoverCents'=>$amount,'cancelledTurnoverCents'=>0,
    'quantity'=>1,'salesQuantity'=>1,
];
$first=SalesPeriodMetrics::marketplace([
    $order('2026-06-03',900,0),$order('2026-09-21',2000,300),$order('2026-11-10',1000,100),
    $order('2026-11-13',9900,0),
],$windows,new DateTimeImmutable('2026-06-01T00:00:00+03:00'),$clock,true);
$second=SalesPeriodMetrics::marketplace([
    $order('2026-11-11',3500,0),
],$windows,new DateTimeImmutable('2026-09-01T00:00:00+03:00'),$clock,false);
$totals=SalesPeriodMetrics::summarize($windows,['PTT AVM'=>$first,'n11'=>$second,'Pazarama'=>[]]);
if($totals['all']['month']['turnoverCents']!==4500||$totals['all']['month']['orders']!==2
    ||$totals['all']['month']['averageCents']!==2250||$totals['all']['month']['returnCents']!==null
    ||$totals['all']['year']['from']!=='2026-06-01'||$totals['all']['year']['coverageFrom']!=='2026-06-01'
    ||$totals['all']['year']['turnoverCents']!==7400||!$totals['all']['year']['partial']
    ||$totals['all']['threeMonths']['turnoverCents']!==6500||$totals['PTT AVM']['threeMonths']['returnCents']!==400
    ||$totals['Pazarama']['month']['turnoverCents']!==null)throw new RuntimeException('Satış dönemlerinin veri kapsamı veya toplamı geçersiz.');
echo "Ay, yıl ve üç aylık satış kapsamı ile hesapları doğrulandı.\n";

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo = new Repository($db);
$company = $repo->createCompany('Örnek', ['Koçtaş', 'Pazarama']);
$other = $repo->createCompany('Diğer', ['Koçtaş']);
$channels = $repo->records($company, 'channels', ['role'=>'admin']);
$save = static function (int $firm, string $kind, array $value) use ($db, $clock): int {
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
        [$firm, $kind, json_encode($value, JSON_THROW_ON_ERROR), $clock->format(DATE_ATOM)]);
    return $db->insertId();
};
$start = '2026-06-01T00:00:00+03:00';
$through = '2026-11-11T12:00:00+03:00';
$koctasState = $save($company, 'sync_koctas', [
    'startDate'=>$start, 'watermark'=>$through, 'scanEnd'=>$clock->format(DATE_ATOM),
]);
$published = static function (string $period, int $gross, int $orders) use ($start, $through): array {
    $amounts = ['grossCents'=>$gross, 'cancelCents'=>$period==='11'?100:0, 'returnCents'=>$period==='11'?70:0, 'orders'=>$orders];
    return [
        'channel'=>'Koçtaş', 'integration'=>'koctas', 'month'=>'2026-'.$period, ...$amounts,
        'dailySales'=>['from'=>$start, 'through'=>$through, 'days'=>['2026-'.$period.'-01'=>$amounts]],
    ];
};
$months = [];
foreach (['06'=>600, '07'=>700, '08'=>800, '09'=>900, '10'=>1000, '11'=>1100] as $period=>$amount) {
    $months[$period] = $save($company, 'sales', $published((string)$period, $amount, 1));
}
$save($other, 'sales', $published('11', 99900, 99));
$sourceOrder = static fn (string $id, string $at, int $gross): array => [
    'externalId'=>$id, 'createdAt'=>$at, 'grossCents'=>$gross, 'orders'=>1, 'cancelCents'=>0, 'returnCents'=>0,
];
// In-place scanning may replace even orders created before the last completed snapshot.
$save($company, 'order_koctas', $sourceOrder('existing', '2026-11-10T13:00:00+03:00', 90000));
$save($company, 'order_koctas', $sourceOrder('new', '2026-11-12T08:00:00+03:00', 99000));
$save($company, 'sync_pazarama', ['startDate'=>'2026-09-01T00:00:00+03:00', 'watermark'=>$clock->format(DATE_ATOM)]);
$save($company, 'order_pazarama', $sourceOrder('pazarama', '2026-11-12T09:00:00+03:00', 2500));

$service = new MarketplaceSalesSummary($db);
$read = static fn (bool $admin): array => $service->dashboard($company, $channels, '2026-11', $clock,
    ['from'=>'2026-11-10', 'to'=>'2026-11-12'], includePeriodMetrics:$admin);
$admin = $read(true);
$client = $read(false);
$metrics = $admin['periodMetrics'];
$koctas = $metrics['Koçtaş'];
if ($koctas['month']['turnoverCents'] !== 1100 || $koctas['month']['returnCents'] !== 70
    || $koctas['month']['orders'] !== 1 || !$koctas['month']['partial']
    || $koctas['year']['turnoverCents'] !== 5100 || $koctas['year']['orders'] !== 6
    || $koctas['year']['coverageFrom'] !== '2026-06-01' || $koctas['year']['from'] !== '2026-06-01'
    || $koctas['threeMonths']['turnoverCents'] !== 3000 || $koctas['threeMonths']['orders'] !== 3
    || $metrics['Pazarama']['year']['coverageFrom'] !== '2026-09-01'
    || $metrics['all']['month']['turnoverCents'] !== 3600 || $client['currentMonthTurnoverCents'] !== 3600
    || array_column($admin['channels'], null, 'channel')['Koçtaş']['available'] !== false) {
    throw new RuntimeException('Koçtaş taramasında yalnız tamamlanmış dönem özeti kullanılmalı, diğer mağaza ve seçili aralık korunmalı.');
}

$invalid = $published('11', 999900, 99);
$invalid['dailySales']['through'] = '2026-11-10T12:00:00+03:00';
$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode($invalid, JSON_THROW_ON_ERROR), $months[11]]);
$metrics = $read(true)['periodMetrics'];
if ($metrics['Koçtaş']['month']['turnoverCents'] !== null || $metrics['all']['month']['turnoverCents'] !== 2500
    || !$metrics['all']['month']['partial']) {
    throw new RuntimeException('Aktarım kesimiyle eşleşmeyen tamamlanmış özet kullanılmamalı.');
}

$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode($published('11', 1100, 1), JSON_THROW_ON_ERROR), $months[11]]);
$db->run('DELETE FROM records WHERE id=?', [$months[10]]);
$metrics = $read(true)['periodMetrics'];
if ($metrics['Koçtaş']['month']['turnoverCents'] !== 1100 || $metrics['Koçtaş']['threeMonths']['turnoverCents'] !== null
    || $metrics['Koçtaş']['year']['turnoverCents'] !== null || !$metrics['all']['threeMonths']['partial']) {
    throw new RuntimeException('Kapsam içindeki eksik ay sessizce atlanmamalı, doğrulanmış cari ay korunmalı.');
}

$sameDayThrough = '2026-11-12T10:00:00+03:00';
$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode([
    'startDate'=>$start, 'watermark'=>$sameDayThrough, 'scanEnd'=>$clock->format(DATE_ATOM),
], JSON_THROW_ON_ERROR), $koctasState]);
$sameDaySale = $published('11', 1200, 1);
$sameDaySale['dailySales']['through'] = $sameDayThrough;
$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode($sameDaySale, JSON_THROW_ON_ERROR), $months[11]]);
$save($company, 'order_koctas', $sourceOrder('later-today', '2026-11-12T11:00:00+03:00', 75000));
$metrics = $read(true)['periodMetrics'];
$koctas = $metrics['Koçtaş']['month'];
if ($koctas['turnoverCents'] !== 1200 || $koctas['returnCents'] !== 70 || $koctas['orders'] !== 1 || $koctas['partial']
    || $metrics['all']['month']['turnoverCents'] !== 3700 || $read(false)['currentMonthTurnoverCents'] !== 3700) {
    throw new RuntimeException('Aynı gün devam eden taramada tamamlanmış brüt ciro ve iade ayrı kalmalı, sonraki sipariş eklenmemeli.');
}
$futureThrough = '2026-11-12T12:00:01+03:00';
$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode([
    'startDate'=>$start, 'watermark'=>$futureThrough, 'scanEnd'=>$clock->format(DATE_ATOM),
], JSON_THROW_ON_ERROR), $koctasState]);
$sameDaySale['dailySales']['through'] = $futureThrough;
$db->run('UPDATE records SET payload=? WHERE id=?', [json_encode($sameDaySale, JSON_THROW_ON_ERROR), $months[11]]);
$metrics = $read(true)['periodMetrics'];
if ($metrics['Koçtaş']['month']['turnoverCents'] !== null || !$metrics['all']['month']['partial']
    || $read(false)['currentMonthTurnoverCents'] !== 2500) {
    throw new RuntimeException('Aynı gün istek saatinden ileri kesimli özet kullanılmamalı.');
}
echo "Koçtaş taramasında doğrulanmış aylık özet, yanlış kesim, kapsam boşluğu ve müşteri-yönetici eşliği geçti.\n";
