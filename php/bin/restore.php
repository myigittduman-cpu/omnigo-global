<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
$options=getopt('',['target-config:','archive:','key-file:','target-storage:','confirm-empty']);
if (!isset($options['target-config'],$options['archive'],$options['key-file']) || !array_key_exists('confirm-empty',$options)) {
    fwrite(STDERR,"Kullanım: php restore.php --target-config=YENI_VERITABANI.php --archive=YEDEK --key-file=ANAHTAR --confirm-empty\n"); exit(2);
}
try {
    $path=$options['archive'];
    if (!is_file($path)) throw new RuntimeException('Yedek bulunamadı.');
    $config=require $options['target-config'];
    $key=file_get_contents($options['key-file']);
    $handle=fopen($path,'rb'); $header=fread($handle,strlen(\Omnigo\BackupStream::HEADER)); fclose($handle);
    if ($header===\Omnigo\BackupStream::HEADER) {
        if (!isset($options['target-storage'])) throw new RuntimeException('Yeni evrak dizini için --target-storage gerekiyor.');
        \Omnigo\BackupRestore::restore(new \Omnigo\Database($config['database']),$path,$key,$options['target-storage']);
    } else {
        if (filesize($path)>16842752) throw new RuntimeException('Eski yedek boyutu geçersiz.');
        \Omnigo\BackupArchive::restore(new \Omnigo\Database($config['database']),file_get_contents($path),$key);
    }
    echo "Boş hedefe geri yükleme tamamlandı. Oturumlar ve şifre yenileme bağlantıları geri yüklenmedi.\n";
} catch (Throwable $error) { fwrite(STDERR,"Geri yükleme reddedildi (".get_class($error).").\n"); exit(1); }
