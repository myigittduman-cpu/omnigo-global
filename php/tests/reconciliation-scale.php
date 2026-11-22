<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,ReconciliationReference,ReconciliationCompare};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$orders=[];$date=gmdate('c',time()-60);$month=(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m');
$db->transaction(function()use($db,&$orders,$date,$month){for($i=1;$i<=10000;$i++){$value=['externalId'=>'LOAD-'.$i,'month'=>$month,'createdAt'=>$date,'grossCents'=>10001,'cancelCents'=>0,'returnCents'=>0,'orders'=>1];$orders[]=$value;$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_n11',?,?)",[json_encode($value),$date]);}
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sales',?,?)",[json_encode(['channel'=>'n11','month'=>$month,'grossCents'=>100010000,'cancelCents'=>0,'returnCents'=>0,'orders'=>10000]),$date]);});
$state=[];$started=microtime(true);ReconciliationReference::complete($state,$orders,gmdate('c',time()-120),gmdate('c'));
$result=(new ReconciliationCompare($db,DeliveryCipher::withKey(str_repeat('s',32))))->inspect(1,'n11',$state['reconciliationReference']);$elapsed=microtime(true)-$started;
if($result['total']!==0||$elapsed>5||strlen(json_encode($state))>8*1024*1024)throw new RuntimeException('Mutabakat ölçek sınırı');
echo '10.000 örnek sipariş: '.round($elapsed*1000).' ms; referans '.strlen(json_encode($state))." bayt; yanlış fark yok.\n";
