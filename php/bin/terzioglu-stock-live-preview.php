<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, InventoryCentralStockLivePreview};

try {
    $input = json_decode(file_get_contents('php://stdin'), true, 8, JSON_THROW_ON_ERROR);
    if (($input['store'] ?? null) !== InventoryCentralStockLivePreview::STORE
        || !is_string($input['sku'] ?? null) || !is_int($input['quantity'] ?? null)
        || !is_string($input['countedAt'] ?? null)
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $input['countedAt'])) {
        throw new InvalidArgumentException('Terzioğlu Shop stok kodunu, sayım adedini ve saat dilimli sayım zamanını girin.');
    }
    $countedAt = new DateTimeImmutable($input['countedAt']);
    if (DateTimeImmutable::getLastErrors() !== false) {
        throw new InvalidArgumentException('Sayım zamanı geçersiz.');
    }

    $db = new Database((require dirname(__DIR__).'/config.php')['database']);
    if (!$db->mysql()) {
        throw new DomainException('Canlı önizleme için salt okunur MySQL işlemi gerekiyor.');
    }
    $db->run('START TRANSACTION READ ONLY');
    try {
        $result = (new InventoryCentralStockLivePreview($db))->read(
            $input['sku'], $input['quantity'], $countedAt, new DateTimeImmutable()
        );
    } finally {
        $db->run('ROLLBACK');
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $message = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
        ? $exception->getMessage() : 'Stok karşılaştırması tamamlanamadı.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
