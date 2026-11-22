<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/bootstrap.php';
$provider=['koctas'=>'Koçtaş','n11'=>'n11','pazarama'=>'Pazarama','idefix'=>'idefix','hepsiburada'=>'Hepsiburada','pttavm'=>'PTT AVM'][$argv[1]??'']??null;if(!$provider)exit(1);
$root=dirname(__DIR__);$config=require(getenv('OMNIGO_CONFIG')?:$root.'/config.php');$db=new \Omnigo\Database($config['database']);
if(!is_file($root.'/storage/order-workers.enabled')||!\Omnigo\RecentOrderSchema::available($db))exit(1);
$lock=fopen($root.'/storage/order-worker-'.($argv[1]).'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
    $vault=new \Omnigo\IntegrationVault($db,$root.'/storage/backup.key');$deliveries=new \Omnigo\DeliveryRepository($db,new \Omnigo\DeliveryCipher($root.'/storage/backup.key'));
    $alerts=new \Omnigo\IntegrationAlertMonitor($db,new \Omnigo\MailQueue($db,[]));
    $notifyFailure=static fn(int $company)=>$alerts->check($company);
    $queue=new \Omnigo\SyncQueue($db,\Omnigo\OrderAdapterFactory::create($db,$vault,$deliveries),$notifyFailure);$end=time()+50;
    while(time()<$end-10){
        if($queue->runOne(null,[$provider],$end))continue;
        $next=$db->one("SELECT MIN(next_run) due FROM jobs WHERE provider=? AND state='pending'",[$provider])['due']??null;
        if($next===null||(int)$next>$end-15)break;
        // Absorb cron's second-level drift without shortening the API's 60-second interval.
        sleep(max(1,min(10,(int)$next-time())));
    }
    // Separate claims retries preserve order imports if the financial API is unavailable.
    if($provider==='Hepsiburada'&&time()<$end-10){
        $sales=new \Omnigo\SyncQueue($db,['Hepsiburada Satış'=>new \Omnigo\HepsiburadaSalesSync($db,$vault)],$notifyFailure);
        foreach($db->all("SELECT company_id FROM jobs WHERE provider='Hepsiburada' ORDER BY id LIMIT 100") as $job){
            $settings=$vault->get((int)$job['company_id'],'hepsiburada');
            if(!empty($settings['salesEnabled'])&&!empty($settings['ordersEnabled'])&&!empty($settings['enabled']))$sales->enqueue((int)$job['company_id'],'Hepsiburada Satış');
        }
        while(time()<$end-10&&$sales->runOne(null,['Hepsiburada Satış'],$end)){}
    }
    // RT11 has its own durable cadence/lease. Unavailable returns never block order imports.
    if($provider==='Koçtaş'&&time()<$end-5){
        $returns=new \Omnigo\KoctasReturnSync($db,$vault);
        foreach($db->all("SELECT company_id FROM jobs WHERE provider='Koçtaş' ORDER BY id LIMIT 100") as $job){
            if(time()>=$end-5)break;
            try{$returns->runDue((int)$job['company_id'],$end);}catch(Throwable $error){error_log('Omnigo iade kontrolü: '.get_class($error));}
        }
    }
    // A separate bounded local pass applies approved quantities without delaying API reads.
    $returnEnd=time()+5;$returnSync=new \Omnigo\ApprovedReturnSync($db,new \Omnigo\DeliveryCipher($root.'/storage/backup.key'));
    foreach($db->all('SELECT company_id FROM jobs WHERE provider=? ORDER BY id LIMIT 100',[$provider]) as $job){
        if(time()>=$returnEnd)break;
        try{$returnSync->run((int)$job['company_id'],$provider,$root.'/storage',$returnEnd);}
        catch(Throwable $error){error_log('Omnigo onaylanan iade aktarımı: '.get_class($error));}
    }
    // Audit only after the queue has released its lease. Failures never change sync results.
    if(is_file($root.'/storage/order-reconciliation.enabled')&&isset(\Omnigo\ReconciliationReference::PROVIDERS[$argv[1]])){
        $auditEnd=time()+5;$monitor=new \Omnigo\ReconciliationMonitor($db,new \Omnigo\DeliveryCipher($root.'/storage/backup.key'));
        foreach($db->all("SELECT company_id FROM jobs WHERE provider=? AND state='pending' ORDER BY next_run LIMIT 20",[$provider]) as $job){
            if(time()>=$auditEnd)break;
            try{$monitor->check((int)$job['company_id'],$argv[1]);}catch(Throwable $error){error_log('Omnigo sipariş mutabakatı: '.get_class($error));}
        }
    }
    // PttAVM rotates its historical windows continuously. Close recovery only at a completed,
    // independently reconciled boundary, before the next minute starts another scan.
    if($provider==='PTT AVM'){
        foreach($db->all("SELECT company_id FROM jobs WHERE provider='PTT AVM' AND state='pending' LIMIT 100") as $job){
            try{$alerts->check((int)$job['company_id']);}catch(Throwable $error){error_log('Omnigo PttAVM bağlantı kontrolü: '.get_class($error));}
        }
    }
}catch(Throwable $e){error_log('Omnigo sipariş görevi: '.get_class($e));}
finally{flock($lock,LOCK_UN);fclose($lock);}
