<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,DeliveryCipher,PushDevices,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'a@test.invalid','A','hash','admin',NULL),(2,'b@test.invalid','B','hash','client',1),(3,'c@test.invalid','C','hash','client',1),(4,'d@test.invalid','D','hash','client',2)");
$user=[...$db->one('SELECT * FROM users WHERE id=2'),'token'=>str_repeat('s',64)];$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$user['token'],2,'csrf',time()+3600]);
$devices=new PushDevices($db,DeliveryCipher::withKey(str_repeat('k',32)));$device=$devices->save($user,'desktop',['deviceId'=>str_repeat('c',64)]);
$insert=function(int $company,int $recipient,string $type='resolved',?int $keyUser=null)use($db){$db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[$company,'portal','support:'.($keyUser??$recipient).':'.bin2hex(random_bytes(8)),$type,json_encode(['audience'=>'client','recipientId'=>$recipient]),gmdate('c')]);return $db->insertId();};
$insert(1,3);$insert(2,4);$insert(1,2,'order');$insert(1,3,'resolved',2);$expected=[];foreach(range(1,101) as $i)$expected[]=$insert(1,2);
$page=$devices->poll($device['token'],0,true,true,true);check(count($page['items'])===99&&$page['hasMore'],'Yanlış alıcı atlanır; imleç sayfalaması korunur');
$next=$devices->poll($device['token'],$page['cursor'],true,true,true);check([...array_column($page['items'],'id'),...array_column($next['items'],'id')]===$expected,'Yalnız kendi firma ve alıcısının çözüm olayları gelir');
check($devices->poll($device['token'],$next['cursor'])['items']===[],'Eski olaylar yeniden bildirilmez');
check($devices->poll($device['token'],$next['cursor'],false,false,false,'client')['sound']===true,'Müşteri kendi panelinden sesli alır');
try{$devices->poll($device['token'],0,false,false,false,'admin');throw new RuntimeException('Başka panelde token kabul edildi');}catch(HttpError $e){check($e->getCode()===401,'Token panel rolüne bağlı');}
$db->run('DELETE FROM sessions WHERE user_id=2');
try{$devices->poll($device['token'],0);throw new RuntimeException('Oturum kapandıktan sonra bildirim sürdü');}catch(HttpError $e){check($e->getCode()===401,'Oturum sona erince bildirim erişimi kesilir');}
echo "Müşteri PC: firma/alıcı izolasyonu, olay sınırlaması, sayfalama ve oturum sonlandırma geçti.\n";
