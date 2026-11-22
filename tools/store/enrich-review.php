<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$runtime=str_replace('\\','/',realpath(__DIR__.'/../../work/store-review'));
require $runtime.'/bootstrap.php';
$config=require $runtime.'/config.php';
if($config['database']['dsn']!=='sqlite:'.$runtime.'/review.sqlite')throw new RuntimeException('Yalnızca yerel inceleme ortamı.');
$db=new \Omnigo\Database($config['database']);
date_default_timezone_set('Europe/Istanbul');
foreach($db->all("SELECT id,payload FROM records WHERE kind='sales'") as $row){
 $sale=json_decode($row['payload'],true);$days=[];$totals=\Omnigo\SalesCalendar::ZERO;
 for($i=1;$i<=(int)date('j');$i++){
  $day=date('Y-m-').str_pad((string)$i,2,'0',STR_PAD_LEFT);
  $value=['grossCents'=>(35000+$row['id']*120+$i*950)*($i%3+1),'cancelCents'=>0,'returnCents'=>0,'orders'=>$i%3+1];
  $days[$day]=$value;\Omnigo\SalesCalendar::add($totals,$value);
 }
 $sale=array_merge($sale,$totals,['dailySales'=>['from'=>date('Y-m-01').'T00:00:00+03:00','through'=>date(DATE_ATOM),'days'=>$days]]);
 $db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($sale,JSON_UNESCAPED_UNICODE),$row['id']]);
}
echo "Sentetik günlük satışlar hazır.\n";
