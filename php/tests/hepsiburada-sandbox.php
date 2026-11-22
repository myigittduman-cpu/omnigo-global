<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Omnigo\{HepsiburadaCredentials, HepsiburadaEnvironment, HepsiburadaReadClient, SyncFailure};
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$credentials = HepsiburadaCredentials::fromArray([
    'merchantId' => '12345678-1234-1234-1234-123456789012',
    'username' => 'fake-user', 'serviceKey' => 'fake-secret', 'userAgent' => 'fake_dev',
]);
$calls = [];
$transport = static function ($url, $headers) use (&$calls): array {
    $calls[] = $url;
    check(in_array('User-Agent: fake_dev', $headers, true), 'User-Agent korunmalı.');
    check(in_array('Authorization: Basic ' . base64_encode('fake-user:fake-secret'), $headers, true), 'Yetki başlığı korunmalı.');
    return ['status' => 200, 'body' => '{"items":[],"totalCount":0}'];
};
(new HepsiburadaReadClient($transport))->openOrders($credentials, time() + 20);
(new HepsiburadaReadClient($transport, HepsiburadaEnvironment::Sandbox))->openOrders($credentials, time() + 20);
check(parse_url($calls[0], PHP_URL_HOST) === 'oms-external.hepsiburada.com', 'Mevcut üretim davranışı değişmemeli.');
check(parse_url($calls[1], PHP_URL_HOST) === 'oms-external-sit.hepsiburada.com', 'Test isteği yalnız SIT adresine gitmeli.');
check(parse_url($calls[0], PHP_URL_PATH) === parse_url($calls[1], PHP_URL_PATH), 'Aynı okuma yolu kullanılmalı.');
check(HepsiburadaEnvironment::tryFrom('https://example.test') === null, 'Serbest URL kabul edilmemeli.');
$calls = [];
try {
    (new HepsiburadaReadClient(static function ($url) use (&$calls): array {
        $calls[] = $url;
        return ['status' => 401];
    }, HepsiburadaEnvironment::Sandbox))->openOrders($credentials, time() + 20);
    throw new RuntimeException('401 reddedilmeliydi.');
} catch (SyncFailure $error) {
    check($error->httpStatus === 401 && count($calls) === 1, 'Hatalı test bağlantısında üretime geçilmemeli.');
}
echo "Hepsiburada: SIT/üretim ayrımı, sabit adres, yetki başlıkları ve hatada üretime geçmeme kontrolleri geçti.\n";
