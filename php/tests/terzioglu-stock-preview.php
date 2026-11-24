<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\InventoryCentralStockPreview;

function expectPreview(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Terzioğlu Shop stok önizlemesi doğrulanamadı.');
    }
}

$cards = [
    ['sku' => 'SKU-1', 'openingQuantity' => 10, 'marketplaceQuantities' => [
        'Trendyol' => 9, 'Hepsiburada' => 10, 'n11' => null,
    ]],
    ['sku' => 'SKU-2', 'openingQuantity' => 1],
];
$sale = ['sku' => ' sku-1 ', 'provider' => 'Trendyol', 'lineKey' => 'A', 'revision' => 1, 'ordered' => 1];
$cancel = array_replace($sale, ['revision' => 2, 'cancelled' => 1]);
$returnRequest = array_replace($sale, ['revision' => 2, 'returned' => 1]);
$restock = array_replace($sale, ['revision' => 3, 'restocked' => 1]);

[$first, $second] = InventoryCentralStockPreview::calculate($cards, [$sale, $sale]);
expectPreview($first['availableQuantity'], 9);
expectPreview($first['marketplaceQuantities']['Trendyol']['difference'], 0);
expectPreview($first['marketplaceQuantities']['Hepsiburada']['difference'], 1);
expectPreview($first['marketplaceQuantities']['n11']['difference'], null);
expectPreview($second['availableQuantity'], 1);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$cancel, $sale])[0]['availableQuantity'], 10);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$returnRequest, $sale])[0]['availableQuantity'], 9);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$restock, $returnRequest, $sale])[0]['availableQuantity'], 10);
$anotherChannel = array_replace($sale, ['provider' => 'n11']);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$sale, $anotherChannel])[0]['availableQuantity'], 8);
$oversold = array_replace($sale, ['sku' => 'SKU-2', 'ordered' => 2]);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$oversold])[1]['availableQuantity'], 0);
expectPreview(InventoryCentralStockPreview::calculate($cards, [$oversold])[1]['shortage'], 1);
try {
    InventoryCentralStockPreview::calculate($cards, [$sale, array_replace($sale, ['ordered' => 2])]);
    throw new RuntimeException('Çelişkili sipariş kaydı kabul edildi.');
} catch (DomainException) {
}
echo "Terzioğlu Shop stok önizlemesi doğrulandı.\n";
