<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/bootstrap.php';
$root=dirname(__DIR__);$config=require(getenv('OMNIGO_CONFIG')?:$root.'/config.php');
$lock=fopen($root.'/storage/inventory-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
    $db=new \Omnigo\Database($config['database']);$cipher=new \Omnigo\DeliveryCipher($root.'/storage/backup.key');
    $vault=new \Omnigo\IntegrationVault($db,$root.'/storage/backup.key');$deadline=time()+48;$results=[];
    $activation=new \Omnigo\InventoryActivation($db,$vault);
    $activationRequests=new \Omnigo\InventoryActivationRequest($db);
    // Only explicitly activated tenants; no connection, order or shipment setting is changed.
    foreach($db->all('SELECT company_id FROM inventory_jobs WHERE enabled=1 ORDER BY next_run,company_id') as $job){
        if(time()+6>=$deadline)break;$company=(int)$job['company_id'];
        try{$activationRequests->process($company,$activation);}
        catch(DomainException $e){/* A changed configuration waits for the administrator's next start. */}
        catch(Throwable $e){error_log('Omnigo bekleyen stok başlangıcı: '.get_class($e));}
        try{
            (new \Omnigo\InventoryCatalogQueue($db,$vault))->tick($company,min($deadline,time()+22));
            $results[$company]=(new \Omnigo\InventoryWorker($db,$cipher))->run($company,$deadline);
            (new \Omnigo\InventoryAlerts($db,$cipher))->publish($company);
        }catch(Throwable $e){$results[$company]=['error'=>get_class($e)];error_log('Omnigo stok görevi: '.get_class($e));}
    }
    file_put_contents($root.'/storage/inventory-worker-status.json',json_encode(['at'=>gmdate('c'),'results'=>$results],JSON_THROW_ON_ERROR),LOCK_EX);
}catch(Throwable $e){error_log('Omnigo stok görevi: '.get_class($e));}
finally{flock($lock,LOCK_UN);fclose($lock);}
