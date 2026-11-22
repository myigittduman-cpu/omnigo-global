<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
umask(0077);
require __DIR__.'/bootstrap.php';
$config=require __DIR__.'/config.php';
if(($config['store_review']??false)!==true||$config['database']['dsn']!=='sqlite:'.str_replace('\\','/',__DIR__).'/review.sqlite')exit;
$lock=fopen(__DIR__.'/storage/review-push-worker.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
$result=['checkedAt'=>gmdate('c'),'state'=>'ok','processed'=>0];
try{
    $worker=new \Omnigo\PushWorker(new \Omnigo\Database($config['database']));
    $deadline=microtime(true)+25;
    while($result['processed']<20&&microtime(true)<$deadline&&$worker->runOne())$result['processed']++;
}catch(Throwable $error){$result['state']='failed';$result['error']=get_class($error);}
file_put_contents(__DIR__.'/storage/review-push-health.json',json_encode($result,JSON_THROW_ON_ERROR),LOCK_EX);
chmod(__DIR__.'/storage/review-push-health.json',0600);
flock($lock,LOCK_UN);fclose($lock);
