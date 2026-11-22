<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{StreamingBackup,Database};

umask(0077);
$root=dirname(__DIR__); $storage=$root.'/storage'; $directory=$storage.'/backups';
try {
    if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Yedek dizini oluşturulamadı.');
    $lock=fopen($storage.'/backup.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { echo "Yedekleme zaten çalışıyor.\n"; exit(0); }
    $keyFile=$storage.'/backup.key';
    if (!is_file($keyFile)) {
        if (glob($directory.'/*.ogbackup')) throw new RuntimeException('Mevcut yedeklerin anahtarı eksik; yeni anahtar oluşturulmadı.');
        $handle=fopen($keyFile,'x');
        if (!$handle || fwrite($handle,random_bytes(32))!==32) throw new RuntimeException('Anahtar kaydedilemedi.');
        fclose($handle); chmod($keyFile,0600);
    }
    $key=file_get_contents($keyFile);
    $config=require (getenv('OMNIGO_CONFIG') ?: $root.'/config.php');
    $path=$directory.'/portal-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.ogbackup';
    $temporary=$path.'.tmp';
    $details=StreamingBackup::create(new Database($config['database']),$key,$temporary,$storage);
    if (!rename($temporary,$path)) throw new RuntimeException('Yedek tamamlanamadı.');
    $status=['lastAttempt'=>gmdate('c'),'lastSuccess'=>gmdate('c'),'state'=>'verified','file'=>basename($path),'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),...$details];
    $publicKey=$storage.'/backup-recovery-public.pem';
    if (is_file($publicKey)) $status['transport']=\Omnigo\BackupOffsite::prepare($path,$status,$key,file_get_contents($publicKey));
    $statusFile=$storage.'/backup-status.json';
    if (file_put_contents($statusFile.'.tmp',json_encode($status,JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($statusFile.'.tmp',$statusFile)) throw new RuntimeException('Yedek durumu kaydedilemedi.');
    // Retention is intentionally manual until an off-server copy has been established.
    echo "Şifreli yedek oluşturuldu ve bütünlüğü doğrulandı.\n";
} catch (Throwable $error) {
    if (isset($temporary) && is_file($temporary)) unlink($temporary);
    $statusFile=$storage.'/backup-status.json';
    $status=is_file($statusFile)?(json_decode(file_get_contents($statusFile),true)?:[]):[];
    $status['lastAttempt']=gmdate('c'); $status['state']='failed'; $status['error']='Yedekleme tamamlanamadı; son başarılı yedek korundu.';
    if (is_dir($storage)) { file_put_contents($statusFile.'.tmp',json_encode($status),LOCK_EX); rename($statusFile.'.tmp',$statusFile); }
    // No SQL, customer records, credentials or key material in cron output.
    fwrite(STDERR,"Yedekleme tamamlanamadı (".get_class($error)."). Sistem yöneticisi kontrol etmeli.\n"); exit(1);
}
