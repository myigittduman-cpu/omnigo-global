<?php
declare(strict_types=1);
// One-time, CLI-only recovery exercise in the explicitly named isolated database.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{BackupArchive,Database,ManagementSchema};
umask(0077);
$root=dirname(__DIR__); $storage=$root.'/storage';
$report=$storage.'/restore-drill-result.json';
try {
    $lock=fopen($storage.'/restore-drill.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
    if (is_file($report)) exit(0);
    $config=require $root.'/config.php';
    $settings=$config['database'];
    if (!preg_match('/(?:^|;)dbname=omnigogl_portal(?:;|$)/',$settings['dsn'])) throw new RuntimeException('Kaynak adı beklenenden farklı.');
    $settings['dsn']=preg_replace('/dbname=omnigogl_portal(?=;|$)/','dbname=omnigogl_portal_restore',$settings['dsn'],1);
    $target=new Database($settings);
    if (!$target->mysql() || $target->one('SELECT DATABASE() AS name')['name']!=='omnigogl_portal_restore') throw new RuntimeException('Yanlış hedef.');
    if ($target->all('SHOW TABLES')) throw new RuntimeException('Hedef veritabanı boş değil.');
    $status=json_decode(file_get_contents($storage.'/backup-status.json'),true,32,JSON_THROW_ON_ERROR);
    $name=$status['file']??'';
    if (!preg_match('/^portal-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.ogbackup$/D',$name)) throw new RuntimeException('Yedek adı geçersiz.');
    $path=$storage.'/backups/'.$name;
    if (filesize($path)>16842752 || !hash_equals($status['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Yedek dosyası doğrulanamadı.');
    $archive=file_get_contents($path); $key=file_get_contents($storage.'/backup.key');
    $snapshot=BackupArchive::read($archive,$key);
    $schema=file_get_contents($root.'/schema.mysql.sql');
    foreach(explode(';',$schema) as $statement) if (trim($statement)!=='') $target->pdo->exec($statement);
    ManagementSchema::install($target);
    \Omnigo\SupportSchema::migrate($target);
    \Omnigo\NotificationSchema::migrate($target);
    \Omnigo\ChatSchema::install($target);
    BackupArchive::restore($target,$archive,$key);
    $counts=[];
    $fingerprints=static function(array $rows): array {
        $hashes=[];
        foreach($rows as $row) {
            ksort($row);
            foreach($row as &$value) if ($value!==null) $value=(string)$value;
            unset($value);
            $hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        }
        sort($hashes); return $hashes;
    };
    foreach(BackupArchive::TABLES as $table) {
        $expected=$snapshot['tables'][$table]['rows']??[];
        if ($table==='jobs') foreach($expected as &$row) { $row['state']='blocked'; $row['lease_until']=null; $row['lease_token']=null; $row['error']='Geri yükleme sonrası bağlantı kontrolü gerekiyor.'; }
        unset($row);
        if ($table==='inbox_jobs') foreach($expected as &$row) if(in_array($row['state'],['pending','running','unknown'],true)) { $row['state']=$row['kind']==='reply'?'unknown':'failed';$row['lease_until']=null;$row['lease_token']=null;$row['error']='Geri yükleme sonrası kontrol gerekiyor. Otomatik işlem yapılmadı.'; }
        unset($row);
        $actual=$target->all("SELECT * FROM `$table`");
        if ($fingerprints($actual)!==$fingerprints($expected)) throw new RuntimeException('Geri yüklenen içerik eşleşmedi.');
        $counts[$table]=count($actual);
    }
    $result=['success'=>true,'completedAt'=>gmdate('c'),'target'=>'omnigogl_portal_restore','backup'=>$name,'verifiedTableCounts'=>$counts,'productionChanged'=>false];
    if (file_put_contents($report,json_encode($result,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Rapor kaydedilemedi.');
    echo "Ayrı MySQL hedefinde kurtarma ve tüm satırların içerik doğrulaması başarılı.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'Kurtarma provası tamamlanamadı ('.get_class($error)."). Üretim veritabanı değiştirilmedi.\n"); exit(1);
}
