<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,InventorySkuMigration,TransactionRetry};

$root=dirname(__DIR__);
$config=require (getenv('OMNIGO_CONFIG')?:$root.'/config.php');
$db=new Database($config['database']);
$companyIds=array_map('intval',array_column($db->all(
    'SELECT company_id FROM inventory_products UNION SELECT company_id FROM inventory_listings ORDER BY company_id'
),'company_id'));
$migration=new InventorySkuMigration($db);
$apply=array_key_exists('apply',getopt('',['apply']));
$cipher=new DeliveryCipher($root.'/storage/backup.key');
$batch=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
$report=static function()use($db,$migration,$companyIds,$apply,$cipher,$root,$batch):array{
    $results=[];
    foreach($companyIds as $company){
        $backup=static function(array $snapshot)use($cipher,$root,$company,$batch):void{
            $path=$root.'/storage/sku-cards-'.$company.'-'.$batch.'.enc';
            $sealed=$cipher->seal($company,'sku-cards:'.$batch,$snapshot);
            if(file_put_contents($path,$sealed,LOCK_EX)!==strlen($sealed)||!hash_equals($sealed,(string)file_get_contents($path)))
                throw new RuntimeException('Stok kartı yedeği doğrulanamadı.');
        };
        $results[$company]=$migration->run($company,preview:!$apply,backup:$backup);
    }
    return $results;
};
$results=$apply?TransactionRetry::run($db,$report):$report();
echo json_encode(['mode'=>$apply?'uygulandı':'önizleme','backupBatch'=>$apply?$batch:null,'stores'=>$results],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;

