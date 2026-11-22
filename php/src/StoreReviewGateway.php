<?php
declare(strict_types=1);
namespace Omnigo;

/** Selects an isolated backend before any production database or autoloader opens. */
final class StoreReviewGateway
{
    public const COOKIE = 'og_store_review';

    public static function selects(array $config, string $path, string $method, ?array $body, array $cookies): bool
    {
        if ($path === '/api/login' && $method === 'POST') {
            $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
            return in_array($email, $config['accounts'], true);
        }
        return ($cookies[self::COOKIE] ?? '') === '1';
    }

    public static function dispatch(string $root): void
    {
        $file = $root.'/store-review/gateway.php';
        if (!is_file($file)) return;
        $config = require $file;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = null;
        if ($path === '/api/login' && $method === 'POST') {
            $raw = file_get_contents('php://input', false, null, 0, 65537);
            $body = strlen($raw) <= 65536 ? json_decode($raw, true, 32) : null;
            if (!is_array($body)) $body = null;
        }
        $selected = self::selects($config, $path, $method, $body, $_COOKIE);
        if ($path === '/api/mobile-push/refresh' && $method === 'POST' && !$selected) {
            // Native token refresh has no browser cookies. Match only this isolated device store.
            $secret = $_SERVER['HTTP_X_OMNIGO_DEVICE'] ?? '';
            $database = $root.'/store-review/runtime/review.sqlite';
            if (is_string($secret) && preg_match('/^[a-f0-9]{64}$/D',$secret) && is_file($database)) {
                try {
                    $reviewDb = new \PDO('sqlite:'.$database,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                    $reviewDb->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=3000;');
                    $query=$reviewDb->prepare("SELECT 1 FROM notification_devices WHERE token_hash=? AND kind='android'");
                    $query->execute([hash('sha256',$secret)]);$selected=(bool)$query->fetchColumn();
                    $query=null;$reviewDb=null;
                } catch (\Throwable) { self::fail(503,'Bildirim yenileme şu anda tamamlanamadı. Lütfen tekrar deneyin.'); }
            }
        }
        $switching = $path === '/api/login' && $method === 'POST';
        if (!$selected && !$switching) return;
        $role = null; $origin = null;
        foreach ($config['origins'] as $candidate => $url) {
            $parts = parse_url($url);
            $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            if (($_SERVER['HTTP_HOST'] ?? '') === $host) { $role = $candidate; $origin = $url; }
        }
        if (!$origin || (str_starts_with($origin, 'https://') && ($_SERVER['HTTPS'] ?? '') !== 'on') ||
            ($method !== 'GET' && (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' ||
                (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin)))) {
            self::fail(403, 'İstek kaynağı doğrulanamadı.');
        }
        if ($switching) {
            $options = ['path'=>'/', 'secure'=>str_starts_with($origin,'https://'), 'httponly'=>true, 'samesite'=>'Strict'];
            setcookie(self::COOKIE, $selected ? '1' : '', ['expires'=>$selected ? time()+30*86400 : time()-3600, ...$options]);
            if ($selected) {
                // Logging out of review must never reveal a pre-existing production session.
                setcookie('og_'.$role.'_session', '', ['expires'=>time()-3600, ...$options]);
                unset($_COOKIE['og_'.$role.'_session']);
            } else {
                setcookie('og_review_'.$role.'_session', '', ['expires'=>time()-3600, ...$options]);
            }
        }
        if (!$selected) return;
        $runtime = $root.'/store-review/runtime';
        if (!is_file($runtime.'/entry.php') || !is_file($runtime.'/config.php') || !is_file($runtime.'/review.sqlite')) {
            self::fail(503, 'Örnek inceleme alanı şu anda hazırlanıyor. Lütfen tekrar deneyin.');
        }
        // No fallback to production is allowed, even when review storage is unavailable.
        require $runtime.'/entry.php';
        exit;
    }

    private static function fail(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
