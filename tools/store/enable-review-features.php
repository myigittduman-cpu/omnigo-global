<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||realpath(__DIR__)!=='/home/omnigogl/portal')exit;
umask(0077);$root=__DIR__;$runtime=$root.'/store-review/runtime';
$report=$root.'/storage/store-review-features.json';
$lock=fopen($root.'/storage/store-review-features.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)||is_file($report))exit;
$result=['checkedAt'=>gmdate('c'),'state'=>'failed'];$saved=[];
try{
 $config=require $runtime.'/config.php';
 if(($config['store_review']??false)!==true||$config['database']['dsn']!=='sqlite:'.$runtime.'/review.sqlite')throw new RuntimeException('Yalıtılmış veritabanı doğrulanamadı.');
 $plan=json_decode(file_get_contents($root.'/storage/store-review-feature-plan.json'),true,32,JSON_THROW_ON_ERROR);
 $allowed=['src/StoreReviewGateway.php','store-review/runtime/src/StoreReviewPolicy.php','store-review/runtime/src/AccountDeletionRequest.php','store-review/runtime/src/StoreReviewDeletionRequest.php','store-review/runtime/review-push-worker.php'];
 if(array_keys($plan['files'])!==$allowed)throw new RuntimeException('Dosya kapsamı farklı.');
 foreach($plan['files'] as $file=>$entry){$saved[$file]=is_file($root.'/'.$file)?file_get_contents($root.'/'.$file):null;if(($saved[$file]===null?null:hash('sha256',$saved[$file]))!==$entry['before'])throw new RuntimeException('Kaynak değişmiş: '.$file);}
 require_once $runtime.'/src/MobilePushConfig.php';
 $provider=json_decode(file_get_contents($root.'/storage/mobile-push.json'),true,16,JSON_THROW_ON_ERROR);
 $provider=['fcm'=>$provider['fcm'],'apns'=>['enabled'=>false]];
 if(($provider['fcm']['projectId']??'')!=='omnigoglobal-portal'||!\Omnigo\MobilePushConfig::ready('android',$provider))throw new RuntimeException('Mevcut Android sağlayıcısı doğrulanamadı.');
 if(is_file($runtime.'/storage/mobile-push.json'))throw new RuntimeException('Mevcut örnek bildirim ayarı korunuyor.');
 foreach($plan['files'] as $file=>$entry){$content=base64_decode($entry['content'],true);if($content===false||!hash_equals($entry['sha256'],hash('sha256',$content)))throw new RuntimeException('Dosya özeti farklı.');$tmp=$root.'/'.$file.'.review-tmp';file_put_contents($tmp,$content,LOCK_EX);chmod($tmp,0600);if(!rename($tmp,$root.'/'.$file))throw new RuntimeException('Dosya yazılamadı.');if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);}
 file_put_contents($runtime.'/storage/mobile-push.json',json_encode($provider,JSON_THROW_ON_ERROR),LOCK_EX);chmod($runtime.'/storage/mobile-push.json',0600);
 $result['state']='enabled';$result['androidReady']=true;$result['newCredentialCreated']=false;$result['productionDatabaseOpened']=false;
 $result['hashes']=[];foreach($plan['files'] as $file=>$entry)$result['hashes'][$file]=hash_file('sha256',$root.'/'.$file);
 unlink($root.'/storage/store-review-feature-plan.json');
}catch(Throwable $error){foreach($saved as $file=>$content){if($content===null){if(is_file($root.'/'.$file))unlink($root.'/'.$file);}else file_put_contents($root.'/'.$file,$content,LOCK_EX);if(function_exists('opcache_invalidate'))opcache_invalidate($root.'/'.$file,true);}$result['error']=$error->getMessage();}
file_put_contents($report,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($report,0600);flock($lock,LOCK_UN);fclose($lock);@unlink(__FILE__);
