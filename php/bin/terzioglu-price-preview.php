<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, InventoryCentralPricePreview, InventoryCentralStockLivePreview};

try {
    $input = json_decode(file_get_contents('php://stdin'), true, 16, JSON_THROW_ON_ERROR);
    if (($input['store'] ?? null) !== InventoryCentralStockLivePreview::STORE
        || !is_string($input['sku'] ?? null) || !is_array($input['rules'] ?? null)) {
        throw new InvalidArgumentException('Terzioğlu Shop stok kodunu ve kanal fiyat kurallarını girin.');
    }
    $db = new Database((require dirname(__DIR__).'/config.php')['database']);
    if (!$db->mysql()) {
        throw new DomainException('Canlı fiyat önizlemesi için salt okunur MySQL işlemi gerekiyor.');
    }
    $db->run('START TRANSACTION READ ONLY');
    try {
        $result = (new InventoryCentralPricePreview($db))->read($input['sku'], $input['rules']);
    } finally {
        $db->run('ROLLBACK');
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof InvalidArgumentException || $error instanceof DomainException
        ? $error->getMessage() : 'Fiyat önizlemesi tamamlanamadı.').PHP_EOL);
    exit(1);
}
