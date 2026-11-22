<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/bootstrap.php';$root=dirname(__DIR__);$configuration=require $root.'/config.php';
$lock=fopen($root.'/storage/marketplace-stock-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
try{
    $db=new Omnigo\Database($configuration['database']);$policy=new Omnigo\MarketplaceStockPolicy($db);$vault=new Omnigo\IntegrationVault($db,$root.'/storage/backup.key');
    $worker=new Omnigo\MarketplaceStockWorker($db,$policy,new Omnigo\HepsiburadaStockGateway(),
        static fn(int $company,string $provider)=>Omnigo\MarketplaceStockJob::configuration($db,$vault,$company,$provider),
        Omnigo\MarketplaceStockPilot::load());$deadline=time()+45;
    $prices=new Omnigo\MarketplacePriceWorker($db,Omnigo\MarketplaceStockPilot::load(),static fn(int $c,string $p)=>Omnigo\MarketplaceStockJob::configuration($db,$vault,$c,$p));
    while(time()+5<$deadline){
        $stock=$worker->tick($deadline);
        $price=time()+7<$deadline&&$prices->tick($deadline);
        if(!$stock&&!$price)break;
    }
}catch(Throwable $error){error_log('Omnigo pazaryeri stok kuyruğu: '.get_class($error));}
finally{flock($lock,LOCK_UN);fclose($lock);}
