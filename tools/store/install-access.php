<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||realpath(__DIR__)!=='/home/omnigogl/portal')exit;
umask(0077);ini_set('display_errors','0');
$root=__DIR__;$report=$root.'/storage/store-review-install.json';
$lock=fopen($root.'/storage/store-review-install.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($report))exit;
$result=['checkedAt'=>gmdate('c'),'state'=>'failed','productionDatabaseChanged'=>false];$saved=[];
try{
 $plan=json_decode(file_get_contents($root.'/storage/store-review-package.json'),true,64,JSON_THROW_ON_ERROR);
 $archive=$root.'/storage/store-review-package.zip';
 if(!hash_equals($plan['sha256'],hash_file('sha256',$archive)))throw new RuntimeException('Paket özeti eşleşmiyor.');
 if(is_dir($root.'/store-review')||is_dir($root.'/store-review-staging'))throw new RuntimeException('Mevcut inceleme alanı korunuyor.');
 $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Paket açılamadı.');
 $manifest=json_decode($zip->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
 for($i=0;$i<$zip->numFiles;$i++){
  $name=$zip->getNameIndex($i);
  if(str_contains($name,'..')||str_contains($name,'\\')||str_starts_with($name,'/')||($name!=='manifest.json'&&!isset($manifest['files'][$name])))throw new RuntimeException('Paket yolu geçersiz.');
 }
 foreach($manifest['before'] as $file=>$hash)if(!hash_equals($hash,hash_file('sha256',$root.'/'.$file)))throw new RuntimeException('Yayın öncesi kaynak değişmiş: '.$file);
 mkdir($root.'/store-review-staging',0700);$zip->extractTo($root.'/store-review-staging');$zip->close();
 foreach($manifest['files'] as $file=>$hash){$full=$root.'/store-review-staging/'.$file;if(!hash_equals($hash,hash_file('sha256',$full)))throw new RuntimeException('Paket dosyası uyuşmuyor.');chmod($full,0600);}
 $runtime=$root.'/store-review-staging/store-review/runtime';
 $db=new PDO('sqlite:'.$runtime.'/review.sqlite');
 if($db->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Örnek veritabanı doğrulanamadı.');
 if((int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==2||(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==3)throw new RuntimeException('Örnek kayıt sayıları farklı.');
 if((int)$db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()!==0||(int)$db->query('SELECT COUNT(*) FROM notification_devices')->fetchColumn()!==0)throw new RuntimeException('Dış işlem kaydı bulunmamalı.');
 $db=null;
 foreach($manifest['changes'] as $file){$saved[$file]=is_file($root.'/'.$file)?file_get_contents($root.'/'.$file):null;}
 // The API entry point is the last file, activating only the fully staged environment.
 if(!rename($root.'/store-review-staging/store-review',$root.'/store-review'))throw new RuntimeException('İnceleme alanı taşınamadı.');
 foreach($manifest['changes'] as $file){
  $target=$root.'/'.$file;$temporary=$target.'.review-tmp';
  if(file_put_contents($temporary,file_get_contents($root.'/store-review-staging/changes/'.$file),LOCK_EX)===false)throw new RuntimeException('Dosya hazırlanamadı.');
  chmod($temporary,str_starts_with($file,'public/')?0644:0600);
  if(!rename($temporary,$target))throw new RuntimeException('Dosya etkinleştirilemedi.');
  if(function_exists('opcache_invalidate'))opcache_invalidate($target,true);
 }
 $result['state']='installed';$result['files']=count($manifest['files']);$result['sourceHashes']=[];
 foreach($manifest['changes'] as $file)$result['sourceHashes'][$file]=hash_file('sha256',$root.'/'.$file);
 $result['isolatedCompanies']=2;$result['reviewUsers']=2;$result['externalWorkers']=0;
 // Remove only verified staging paths, never production or the new review runtime.
 $stage=$root.'/store-review-staging';
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($stage);
 unlink($archive);unlink($root.'/storage/store-review-package.json');
}catch(Throwable $error){
 foreach(array_reverse($saved,true) as $file=>$content){if($content===null){if(is_file($root.'/'.$file))unlink($root.'/'.$file);}else file_put_contents($root.'/'.$file,$content,LOCK_EX);if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);}
 $result['error']=$error->getMessage();$result['sourceRollbackAttempted']=(bool)$saved;
}
file_put_contents($report,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($report,0600);
flock($lock,LOCK_UN);fclose($lock);@unlink(__FILE__);
