<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\InventoryCatalogEligibility as Eligibility;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$now = new DateTimeImmutable('2026-10-29T12:00:00+03:00');
$from = '2026-06-29T12:00:00+03:00';
$to = $now->format(DATE_ATOM);
$zero = ['sourceKey' => 'hepsiburada:SKU-1', 'periods' => [
    ['from' => $from, 'to' => $to, 'maximumQuantity' => 0, 'complete' => true],
]];
$positive = ['sourceKey' => 'n11:SKU-2', 'observations' => [['at' => '2026-08-12T09:00:00+03:00', 'quantity' => 1]]];
$evaluate = static fn(array $sources, bool $complete = true): array => Eligibility::evaluate($sources, $complete, $now);
check($evaluate([$zero])['status'] === 'exclude', 'Kesintisiz dört aylık sıfır stok dışlanır.');
check($evaluate([$zero, $positive])['status'] === 'include', 'Başka pazaryerinde tek adet stok bile ürünü dahil eder.');
check($evaluate([$positive], false)['status'] === 'include', 'Stok kanıtı varsa eksik katalog dahil etmeyi engellemez.');
check($evaluate([$zero], false)['status'] === 'review', 'Başarısız katalog aktarımı dışlamaya yol açmaz.');
check($evaluate([])['status'] === 'review', 'Boş kaynak listesi stok yok anlamına gelmez.');
check($evaluate([$zero, ['sourceKey' => 'pttavm:SKU-3']])['status'] === 'review', 'Bir kaynağın geçmişi eksikse ürün dışlanmaz.');
$snapshot = ['sourceKey' => 'idefix:SKU-4', 'observations' => [['at' => $to, 'quantity' => 0]], 'salesCount' => 0];
check($evaluate([$snapshot])['status'] === 'review', 'Bugün stok ve satış sıfır olması dört ayı kanıtlamaz.');
$snapshot['observations'][] = ['at' => $from, 'quantity' => 0];
check($evaluate([$snapshot])['status'] === 'review', 'İki sıfır stok fotoğrafı aradaki dönemi kanıtlamaz.');
$snapshot['observations'][] = ['at' => $to, 'quantity' => 3];
check($evaluate([$snapshot])['status'] === 'include', 'Hiç satılmamış fakat stokta bulunan ürün dahil edilir.');
$future = ['sourceKey' => 'koctas:SKU-5', 'observations' => [['at' => '2026-10-30T12:00:00+03:00', 'quantity' => 10]]];
check($evaluate([$future])['status'] === 'review', 'Gelecek tarihli gözlem dahil etme kanıtı değildir.');
$old = ['sourceKey' => 'pazarama:SKU-6', 'observations' => [['at' => '2026-06-29T11:59:59+03:00', 'quantity' => 4]]];
check($evaluate([$old])['status'] === 'review', 'Dört aydan eski stok gözlemi döneme dahil edilmez.');
$split = $zero;
$split['periods'] = [
    ['from' => '2026-08-01T00:00:00+03:00', 'to' => $to, 'maximumQuantity' => 0, 'complete' => true],
    ['from' => $from, 'to' => '2026-08-01T00:00:00+03:00', 'maximumQuantity' => 0, 'complete' => true],
];
check($evaluate([$split])['status'] === 'exclude', 'Sırasız bitişik dönemler birlikte değerlendirilir.');
$split['periods'][1]['to'] = '2026-07-31T23:59:59+03:00';
check($evaluate([$split])['status'] === 'review', 'Bir saniyelik bile veri boşluğu kesin dışlamayı engeller.');
$unverified = $zero; $unverified['periods'][0]['complete'] = false;
check($evaluate([$unverified])['status'] === 'review', 'Eksik tarihçe dışlama kanıtı sayılmaz.');
check($evaluate([$zero, $zero])['status'] === 'review', 'Yinelenen kaynak kimlikleri eksiksiz katalog sayılmaz.');
$malformed = $zero; $malformed['periods'][0]['from'] = '2026-02-30T00:00:00+03:00';
check($evaluate([$malformed])['status'] === 'review', 'Geçersiz tarih normalize edilerek kabul edilmez.');
$contradiction = $zero; $contradiction['observations'] = $positive['observations'];
check($evaluate([$contradiction])['status'] === 'include', 'Pozitif gözlem sıfır stok iddiasına göre önceliklidir.');
$wide = $zero; $wide['periods'][] = ['from' => '2026-01-01T00:00:00+03:00', 'to' => $to, 'maximumQuantity' => 2, 'complete' => true];
check($evaluate([$wide])['status'] === 'review', 'Dönem dışına taşan pozitif özet kesin karar verdirmez.');
check(Eligibility::windowStart(new DateTimeImmutable('2026-06-30T08:00:00+03:00'))->format('Y-m-d H:i:s') === '2026-02-28 08:00:00', 'Ay sonu doğru kırpılır.');
check(Eligibility::windowStart(new DateTimeImmutable('2024-06-30T08:00:00+03:00'))->format('Y-m-d') === '2024-02-29', 'Artık yıl korunur.');
check(Eligibility::windowStart(new DateTimeImmutable('2026-03-31T23:30:00Z'))->format('Y-m-d H:i:s') === '2025-12-01 02:30:00', 'Türkiye saati ve yıl geçişi korunur.');
check($evaluate([$zero])['visible'] === false && $evaluate([$unverified])['visible'] === true, 'Belirsiz ürünler görünür kalır.');
echo "Aktif stok kapsamı: 23 kontrol geçti.\n";
