<?php
declare(strict_types=1);
namespace Omnigo;

final class HttpError extends \RuntimeException {}
final class Http
{
    public static function fail(int $status, string $message): never { throw new HttpError($message, $status); }
    public static function body(int $limit=65536): array
    {
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) self::fail(415, 'JSON içeriği gerekiyor.');
        $raw = file_get_contents('php://input', false, null, 0, $limit+1);
        if (strlen($raw) > $limit) self::fail(413, 'Kayıt çok büyük.');
        try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::fail(400, 'Geçersiz istek.'); }
        if (!is_object($object)) self::fail(400, 'Geçersiz istek.');
        return (array)$object;
    }
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    public static function protect(array $config): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https://koctas.mirakl.net https://img.pzrmcdn.com; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        $expected = parse_url($config['origin']);
        $host = $expected['host'] . (isset($expected['port']) ? ':'.$expected['port'] : '');
        if (($_SERVER['HTTP_HOST'] ?? '') !== $host) self::fail(403, 'Adres doğrulanamadı.');
        if (($expected['scheme'] ?? '') === 'https' && ($_SERVER['HTTPS'] ?? '') !== 'on') self::fail(403, 'Güvenli bağlantı gerekiyor.');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $config['origin']) self::fail(403, 'İstek kaynağı doğrulanamadı.');
            if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') self::fail(403, 'İstek kaynağı doğrulanamadı.');
        }
    }
}
