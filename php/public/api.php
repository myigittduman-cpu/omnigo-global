<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/StoreReviewGateway.php';
\Omnigo\StoreReviewGateway::dispatch(dirname(__DIR__));
require dirname(__DIR__).'/bootstrap.php';
try {
    $configPath = getenv('OMNIGO_CONFIG') ?: dirname(__DIR__).'/config.php';
    if (!is_file($configPath)) \Omnigo\Http::fail(503,'Portal kurulum hazırlığı devam ediyor.');
    $config = require $configPath;
    if (is_file(dirname($configPath).'/mail.config.php')) $config['mail']=require dirname($configPath).'/mail.config.php';
    $config = \Omnigo\PortalAccess::configure($config, $_SERVER['HTTP_HOST'] ?? '');
    (new \Omnigo\Application(new \Omnigo\Database($config['database']),$config))->handle(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),$_SERVER['REQUEST_METHOD']);
} catch (\Omnigo\HttpError $error) {
    \Omnigo\Http::json(['error'=>$error->getMessage()],$error->getCode());
} catch (\Throwable $error) {
    error_log('Omnigo portal: '.get_class($error).' code='.$error->getCode());
    \Omnigo\Http::json(['error'=>'İşlem tamamlanamadı. Lütfen tekrar deneyin.'],500);
}
