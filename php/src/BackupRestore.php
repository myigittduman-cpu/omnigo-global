<?php
declare(strict_types=1);
namespace Omnigo;

/** Restore is restricted to an empty database and a new private storage directory. */
final class BackupRestore
{
    public static function restore(Database $target, string $archive, string $key, string $storage): array
    {
        $verified=BackupVerification::inspect($archive,$key); $digest=hash_file('sha256',$archive);
        if(isset($verified['rows']['profitability_activation']))ProfitabilitySchema::install($target);
        if(isset($verified['rows']['upcoming_payout_reviews']))UpcomingPayoutReviewStore::install($target);
        if(isset($verified['rows'][FileCenterSchema::ARCHIVES])||isset($verified['rows'][FileCenterSchema::HIDDEN_PRODUCTS]))FileCenterSchema::install($target);
        if(isset($verified['rows'][MarketplaceListingTransfers::TABLE]))MarketplaceListingTransfers::install($target);
        $tables=BackupArchive::checkSchema($target);
        if (file_exists($storage) || is_link($storage) || !is_dir(dirname($storage))) throw new \RuntimeException('Yeni ve boş bir evrak hedefi gerekiyor.');
        $stage=$storage.'.restore-'.bin2hex(random_bytes(8));
        if (!mkdir($stage,0700)) throw new \RuntimeException('Kurtarma alanı oluşturulamadı.');
        $file=null; $published=false;
        try {
            $target->transaction(function() use($target,$tables,$archive,$key,$storage,$stage,$digest,&$file,&$published) {
                foreach ($tables as $name) {
                    $rows=$target->all("SELECT * FROM `$name` LIMIT 2".$target->lock());
                    if ($rows && !($name==='install_state' && count($rows)===1 && (int)$rows[0]['id']===1)) throw new \RuntimeException('Hedef boş değil; üzerine yazılmadı.');
                }
                $target->run('DELETE FROM install_state'); $table=null; $sql=null;
                BackupVerification::inspect($archive,$key,function($record) use($target,$tables,$stage,&$table,&$sql,&$file) {
                    switch ($record['type']) {
                        case 'manifest':
                            if (array_diff($record['tables'],$tables)) throw new \RuntimeException('Hedef şema eksik.');
                            break;
                        case 'table':
                            $table=$record['name']; $columns=BackupArchive::columns($target,$table);
                            if ($columns!==$record['columns']) throw new \RuntimeException('Hedef sütunlar uyuşmuyor. Aynı sürümün şemasını kullanın.');
                            $sql="INSERT INTO `$table` (`".implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
                            break;
                        case 'row':
                            $row=$record['value'];
                            if ($table==='jobs') { $row['state']='blocked'; $row['lease_until']=null; $row['lease_token']=null; $row['error']='Geri yükleme sonrası bağlantı kontrolü gerekiyor.'; }
                            if ($table==='inbox_jobs' && in_array($row['state'],['pending','running','unknown'],true)) { $row['state']=$row['kind']==='reply'?'unknown':'failed'; $row['lease_until']=null; $row['lease_token']=null; $row['error']='Geri yükleme sonrası kontrol gerekiyor. Otomatik işlem yapılmadı.'; }
                            $target->run($sql,array_values(BackupArchive::restoredRow($table,$row))); break;
                        case 'file':
                            $path=$stage.'/'.$record['path'];
                            if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) throw new \RuntimeException('Evrak klasörü oluşturulamadı.');
                            $file=fopen($path,'xb'); if (!$file) throw new \RuntimeException('Evrak oluşturulamadı.'); chmod($path,0600); break;
                        case 'chunk': BackupStream::put($file,base64_decode($record['data'],true)); break;
                        case 'fileEnd':
                            if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new \RuntimeException('Evrak diske yazılamadı.');
                            fclose($file); $file=null; break;
                    }
                });
                if (!hash_equals($digest,hash_file('sha256',$archive))) throw new \RuntimeException('Yedek işlem sırasında değişti.');
                if (file_exists($storage) || !rename($stage,$storage)) throw new \RuntimeException('Evrak hedefi yayımlanamadı.');
                $published=true;
            });
            return $verified;
        } catch (\Throwable $error) {
            if (is_resource($file)) fclose($file);
            BackupFiles::removeStaging($published?$storage:$stage); throw $error;
        }
    }
}
