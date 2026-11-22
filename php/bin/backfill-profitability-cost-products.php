<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,ProfitabilityCostBinding,ProfitabilityCostHistory};

$config=require (getenv('OMNIGO_CONFIG')?:dirname(__DIR__).'/config.php');
$db=new Database($config['database']);
if(!(new ProfitabilityCostHistory($db))->available())throw new RuntimeException('Önce kârlılık maliyet tablolarını oluşturun.');
$options=getopt('',['after:']);
$cursor=filter_var($options['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
if($cursor===false)throw new InvalidArgumentException('Başlangıç kayıt numarası geçersiz.');
$last=(int)$db->one('SELECT MAX(id) AS last_id FROM delivery_orders')['last_id'];
$binder=new ProfitabilityCostBinding($db);
$cipher=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
while($cursor<$last){
    $result=$binder->batch($cipher,$cursor,$last);
    $cursor=$result['cursor'];
    echo "Kontrol edilen son kayıt $cursor/$last. Eşleşen yeni kalem: ".$result['bound'].".\n";
}
