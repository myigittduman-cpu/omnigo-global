<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,PushSchema,NotificationSchema,PushDevices,DeliveryCipher,HttpError};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);PushSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");$db->run("INSERT INTO users VALUES(1,'a@example.test','Bir','hash','client',1),(2,'b@example.test','İki','hash','client',2)");
$one=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>str_repeat('a',64)];$two=[...$db->one('SELECT * FROM users WHERE id=2'),'token'=>str_repeat('b',64)];
foreach([$one,$two] as $u)$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$u['token'],$u['id'],'csrf',time()+3600]);
$cipher=DeliveryCipher::withKey(str_repeat('w',32));$devices=new PushDevices($db,$cipher);$pair=\Minishlink\WebPush\VAPID::createVapidKeys();
$data=['subscription'=>['endpoint'=>'https://web.push.apple.com/example','keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]],'clientContext'=>['origin'=>'https://portal.example.test','platform'=>'ios','standalone'=>true]];
$first=$devices->save($one,'web',$data);$before=$devices->current($one,$first['id']);$devices->test($one,$first['id']);
$newSession=[...$one,'token'=>str_repeat('c',64)];$db->run('DELETE FROM sessions WHERE user_id=1');$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$newSession['token'],1,'csrf',time()+3600]);
check(!$devices->usable($before),'Eski oturum bağlaması geçersizdir');
$again=$devices->save($newSession,'web',$data);$after=$devices->current($one,$again['id']);
check($first['id']===$again['id']&&$devices->usable($after),'Aynı iPhone doğru yeni oturuma bağlanır');
check($before['generation']===$after['generation'],'Oturum yenileme kuyruktaki bildirimi iptal etmez');
check($after['session_token']===$newSession['token'],'Yeni oturum saklanır');
$metadata=$cipher->open(1,'push:'.$after['device_key'],$after['payload']);check($metadata['clientContext']===$data['clientContext'],'iPhone ve kurulum kaynağı şifreli eşleştirilir');
check(!str_contains($after['payload'],'web.push.apple.com'),'Abonelik açık saklanmaz');
$devices->change($one,$first['id'],['enabled'=>false,'sound'=>true]);check(!$devices->usable($devices->current($one,$first['id'])),'Kapatılan cihaz pasiftir');
$devices->save($newSession,'web',$data);$reenrolled=$devices->current($one,$first['id']);check((int)$reenrolled['generation']===(int)$before['generation']+1,'Yeniden izinle kayıt eski kuyruğu canlandırmaz');
$transfer=$devices->save($two,'web',$data);$other=$devices->current($two,$transfer['id']);check((int)$other['generation']===(int)$reenrolled['generation']+1,'Hesap değişimi eski alıcının kuyruğunu ayırır');
try{$devices->current($one,$first['id']);throw new RuntimeException('Firma sınırı');}catch(HttpError $e){check($e->getCode()===404,'Eski hesap yeni cihaz eşleşmesini göremez');}
echo "Web Push: oturum yenileme, iPhone eşleşmesi, kuyruk koruması, tekrar kayıt, kapatma ve hesap izolasyonu geçti.\n";
