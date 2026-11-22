<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationHealthSnapshot,SyncFreshness};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("CREATE TABLE finance_sync(company_id INT,provider TEXT,payload TEXT)");$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$now=time();$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode(['name'=>'n11','status'=>'active']),gmdate('c',$now)]);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'integration_n11','{}',?)",[gmdate('c',$now)]);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_n11',?,?)",[json_encode(['lastSuccessAt'=>gmdate('c',$now),'phase'=>'ready']),gmdate('c',$now)]);
$db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(1,'n11','pending',?),(1,'n11 Hakediş','pending',?)",[$now+1000,$now+3600]);
$db->run("INSERT INTO finance_sync VALUES(1,'n11',?)",[json_encode(['lastSuccessAt'=>gmdate('c',$now-3600)])]);
$health=new IntegrationHealthSnapshot($db);
foreach([119=>'healthy',120=>'healthy',121=>'warning',300=>'warning',301=>'error'] as $age=>$expected)check($health->company(1,$now+$age)['n11']['status']===$expected,'Gecikme sınırı: '.$age);
check($health->company(1,$now+301)['n11']['requiredJobs']===['n11'],'Hakediş kuyruğu yanlış gecikme üretmemeli');
$db->run("UPDATE records SET payload=? WHERE kind='channels'",[json_encode(['name'=>'n11','status'=>'inactive'])]);check($health->company(1,$now+301)['n11']['status']==='inactive','Pasif bağlantı uyarılmamalı');
echo "Aktarım tazeliği: iki/beş dakika sınırları, hakediş ayrımı ve pasif bağlantılar geçti.\n";
