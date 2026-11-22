<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/bootstrap.php';
$root=dirname(__DIR__);$config=require(getenv('OMNIGO_CONFIG')?:$root.'/config.php');
$lock=fopen($root.'/storage/inventory-image-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
    $db=new \Omnigo\Database($config['database']);
    $result=(new \Omnigo\InventoryImageQueue($db))->run(time()+48,new \Omnigo\InventoryImageFile($root.'/storage/inventory-images'));
    file_put_contents($root.'/storage/inventory-image-worker-status.json',json_encode(['at'=>gmdate('c'),'result'=>$result],JSON_THROW_ON_ERROR),LOCK_EX);
}catch(Throwable $error){error_log('Omnigo ürün görselleri: '.get_class($error));}
finally{flock($lock,LOCK_UN);fclose($lock);}
