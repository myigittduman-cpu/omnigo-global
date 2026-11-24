<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\InventoryCentralStockPreview;

try {
    // The input contains only pilot stock counts and post-count order facts.
    $input = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
    if (($input['store'] ?? null) !== 'Terzioğlu Shop'
        || !is_array($input['cards'] ?? null) || !array_is_list($input['cards']) || !$input['cards']
        || !is_array($input['snapshots'] ?? null) || !array_is_list($input['snapshots'])) {
        throw new InvalidArgumentException('Terzioğlu Shop başlangıç stok kartlarını ve sipariş kayıtlarını girin.');
    }
    $cards = InventoryCentralStockPreview::calculate($input['cards'], $input['snapshots']);
    echo json_encode(['store' => $input['store'], 'mode' => 'preview', 'cards' => $cards],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $message = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
        ? $exception->getMessage()
        : 'Deneme verisi okunamadı. JSON biçimini ve alanları kontrol edin.';
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
