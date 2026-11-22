<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
try {
    $config = require __DIR__.'/config.php';
    if (($config['store_review'] ?? false) !== true || $config['database']['dsn'] !== 'sqlite:'.str_replace('\\','/',__DIR__).'/review.sqlite') {
        \Omnigo\Http::fail(503, 'Örnek inceleme alanı hazır değil.');
    }
    $config = \Omnigo\PortalAccess::configure($config, $_SERVER['HTTP_HOST'] ?? '');
    $db = new \Omnigo\Database($config['database']);
    $path = parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    \Omnigo\StoreReviewAppearance::dispatch($db, $config, $path, $method);
    (new \Omnigo\Application($db, $config))->handle($path, $method);
} catch (\Omnigo\HttpError $error) {
    \Omnigo\Http::json(['error'=>$error->getMessage()],$error->getCode());
} catch (\Throwable $error) {
    error_log('Omnigo review: '.get_class($error).' code='.$error->getCode());
    \Omnigo\Http::json(['error'=>'Örnek inceleme işlemi tamamlanamadı. Lütfen tekrar deneyin.'],500);
}
