<?php
declare(strict_types=1);
require_once __DIR__.'/src/Http.php';
spl_autoload_register(function(string $name): void {
    if (!str_starts_with($name,'Omnigo\\')) return;
    if (in_array($name,['Omnigo\\ReadOnlyAdapter','Omnigo\\SyncFailure'],true)) { require_once __DIR__.'/src/SyncQueue.php'; return; }
    $file = __DIR__.'/src/'.substr($name,7).'.php';
    if (is_file($file)) require_once $file;
});
