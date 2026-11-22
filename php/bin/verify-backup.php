<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/bootstrap.php';
try {
    $key=base64_decode(trim(stream_get_contents(STDIN)),true);
    if ($key===false || strlen($key)!==32 || !isset($argv[1])) throw new RuntimeException('Doğrulama girdisi geçersiz.');
    echo json_encode(\Omnigo\BackupVerification::inspect($argv[1],$key),JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) { fwrite(STDERR,"Yedek doğrulaması başarısız.\n"); exit(1); }
