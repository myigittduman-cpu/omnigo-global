<?php
foreach (['warm', 'cold', 'duplicate', 'cron', 'private', 'other'] as $scenario) {
    $result = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/sitemap-provider.php') . ' ' . escapeshellarg($scenario));
    if (in_array($scenario, ['cron', 'private', 'other'], true)) {
        $data = json_decode($result, true);
        if ($data !== ['fallback' => true, 'built' => 0]) throw new RuntimeException($scenario . ': sağlayıcıya devredilmedi.');
    } else {
        if (!str_starts_with($result, "XML\n")) throw new RuntimeException('XML başlıkları çağrılmadı.');
        $data = json_decode(substr($result, 4), true);
        if ($data['urls'] !== ['https://example.test/', 'https://example.test/gizlilik-ve-kisisel-veriler/', 'https://example.test/referanslarimiz/', 'https://example.test/is-ortaklarimiz/']) throw new RuntimeException($scenario . ': eksik veya yinelenen adres.');
        if ($data['built'] !== ($scenario === 'cold' ? 1 : 0)) throw new RuntimeException('Önbellek gereksiz oluşturuldu.');
    }
}
echo "Site haritası: 6 önbellek, gizlilik ve yinelenen adres kontrolü geçti.\n";
