<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,DeliveryCipher,StreamingBackup,BackupVerification,BackupRestore,BackupFiles,BackupStream};
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new RuntimeException('İşlem reddedilmeliydi.'); }
function fresh(string $file): Database { $db=new Database(['dsn'=>'sqlite:'.$file]); $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql')); ShipmentSchema::install($db); return $db; }
$folder=sys_get_temp_dir().'/omnigo-backup-test-'.bin2hex(random_bytes(8)); mkdir($folder,0700);
try {
    $db=fresh($folder.'/source.sqlite'); $key=random_bytes(32); $cipher=DeliveryCipher::withKey($key);
    $db->run("INSERT INTO companies VALUES(1,'Yedek Testi')");
    $db->run("INSERT INTO users VALUES(1,'backup@example.test','Test','fake','admin',NULL)");
    $db->run("INSERT INTO sessions VALUES('old',1,'csrf',9999999999)");
    $db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(1,'n11','running',0)");
    $db->transaction(function() use($db) { for ($i=0;$i<1100;$i++) $db->run("INSERT INTO records(company_id,kind,payload,version,updated) VALUES(1,'sales',?,1,'2026-10-11')",[json_encode(['note'=>bin2hex(random_bytes(12288))])]); });
    $fileKey=bin2hex(random_bytes(24)); $relative='shipment-documents/'.$fileKey.'.bin'; mkdir($folder.'/shipment-documents',0700);
    $document=random_bytes(1500000); file_put_contents($folder.'/'.$relative,$document);
    $payload=$cipher->seal(1,'shipment:1',['documents'=>['invoice'=>['key'=>$fileKey]]]);
    $db->run("INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(1,1,'Ankara','n11','2026-10',?,'2026-10-11','2026-10-11')",[$payload]);
    unset($document); $path=$folder.'/test.ogbackup';
    $start=microtime(true); $summary=StreamingBackup::create($db,$key,$path,$folder);
    check($summary['rows']['records']===1100 && $summary['documents']===1,'Yedek eksik.');
    $target=fresh($folder.'/target.sqlite'); BackupRestore::restore($target,$path,$key,$folder.'/restored');
    check(hash_file('sha256',$folder.'/'.$relative)===hash_file('sha256',$folder.'/restored/'.$relative),'Evrak uyuşmuyor.');
    foreach (['records','shipment_entries','companies','users'] as $table) {
        $a=$db->run("SELECT * FROM $table ORDER BY id"); $b=$target->run("SELECT * FROM $table ORDER BY id");
        while ($row=$a->fetch()) check($row===$b->fetch(),'Geri yükleme veri farkı.'); check($b->fetch()===false,'Fazla satır.');
    }
    check(!$target->one('SELECT * FROM sessions') && $target->one('SELECT state FROM jobs')['state']==='blocked','Eski oturum/iş yeniden etkinleşti.');
    rejects(fn()=>BackupRestore::restore($target,$path,$key,$folder.'/occupied'));
    check(!file_exists($folder.'/occupied'),'Başarısız hedef yayımlandı.');
    rejects(fn()=>BackupVerification::inspect($path,random_bytes(32)));
    $bad=$folder.'/bad.ogbackup'; copy($path,$bad); $f=fopen($bad,'r+b'); fseek($f,-1,SEEK_END); fwrite($f,"!"); fclose($f);
    rejects(fn()=>BackupVerification::inspect($bad,$key));
    copy($path,$bad); $f=fopen($bad,'r+b'); ftruncate($f,filesize($path)-30); fclose($f); rejects(fn()=>BackupVerification::inspect($bad,$key));
    copy($path,$bad); file_put_contents($bad,'extra',FILE_APPEND); rejects(fn()=>BackupVerification::inspect($bad,$key));
    $rollback=fresh($folder.'/rollback.sqlite'); $rollback->pdo->exec("CREATE TRIGGER reject_restore BEFORE INSERT ON records BEGIN SELECT RAISE(ABORT,'test'); END;");
    rejects(fn()=>BackupRestore::restore($rollback,$path,$key,$folder.'/rollback-files'));
    check(!$rollback->one('SELECT * FROM companies') && !file_exists($folder.'/rollback-files'),'İşlem geri alınmadı.');
    unlink($folder.'/'.$relative); rejects(fn()=>StreamingBackup::create($db,$key,$folder.'/missing.ogbackup',$folder));
    $badRecords=[['type'=>'manifest','format'=>2,'createdAt'=>'test','tables'=>['companies']],['type'=>'table','name'=>'companies','columns'=>['id','name']],['type'=>'file','path'=>'../escape.bin']];
    BackupStream::write($folder.'/unsafe.ogbackup',$key,$badRecords); rejects(fn()=>BackupVerification::inspect($folder.'/unsafe.ogbackup',$key));
    echo json_encode(['passed'=>true,'sourceBytesOver'=>27000000,'archiveBytes'=>filesize($path),'documents'=>$summary['documents'],'seconds'=>round(microtime(true)-$start,2),'phpPeakBytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR)."\n";
} finally { unset($db,$target,$rollback,$a,$b); gc_collect_cycles(); BackupFiles::removeStaging($folder); }
