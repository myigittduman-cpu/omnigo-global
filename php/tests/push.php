<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,PushSchema,NotificationSchema,PushDevices,PushSubscriptionValidator,PushWorker,DeliveryCipher,OrderNotifications,HttpError,Security,BackupArchive,SupportSchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();throw new RuntimeException('Hata bekleniyordu');}catch(HttpError $e){check($e->getCode()===$status,'Beklenmeyen durum');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);PushSchema::install($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme Firma')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Yönetici','hash','admin',NULL),(2,'b@example.test','Müşteri','hash','client',1)");
$user=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>str_repeat('a',64)];
$client=[...$db->one('SELECT * FROM users WHERE id=2'),'token'=>str_repeat('b',64)];
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();
$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/example','keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]];
foreach(['https://127.0.0.1/push','http://fcm.googleapis.com/x','https://fcm.googleapis.com.evil.test/x','https://a@fcm.googleapis.com/x','https://fcm.googleapis.com:80/x'] as $url)fails(fn()=>PushSubscriptionValidator::validate([...$subscription,'endpoint'=>$url]),400);
$key=tempnam(sys_get_temp_dir(),'push-key');file_put_contents($key,random_bytes(32));
try{
 $devices=new PushDevices($db,new DeliveryCipher($key));
 $clientDesktop=$devices->save($client,'desktop',['deviceId'=>str_repeat('b',64)]);
 check($clientDesktop['userId']===2,'Müşteri masaüstü kaydı kendi hesabına aittir');
 fails(fn()=>$devices->poll($clientDesktop['token'],0),401);
 $registration=$devices->save($user,'web',['subscription'=>$subscription,'sound'=>true]);
 $stored=$devices->current($user,$registration['id']);check(!str_contains($stored['payload'],'fcm.googleapis.com'),'Abonelik şifreli saklanır');
 $orders=new OrderNotifications($db);$order=['provider'=>'Pazarama','orderNumber'=>'private-order','createdAt'=>gmdate('c')];
 $db->transaction(fn()=>$orders->observe(1,$order));$db->transaction(fn()=>$orders->observe(1,$order));
 check(count($db->all('SELECT * FROM push_deliveries'))===1,'Tek sipariş tekrar kuyruğa girmez');
 $seen=[];$worker=new PushWorker($db,$devices,function($sub,$payload)use(&$seen){$seen[]=$payload;return ['success'=>true,'expired'=>false,'status'=>201];});
 check($worker->runOne()&&!$worker->runOne(),'Kuyruk yalnızca bir kez teslim edilir');
 check($seen[0]['sound']===true&&str_contains($seen[0]['body'],'Pazarama')&&!str_contains(json_encode($seen),'private-order'),'Ses ve pazaryeri var; özel sipariş bilgisi yok');
 $devices->test($user,$registration['id']);fails(fn()=>$devices->test($user,$registration['id']),429);
 $retry=new PushWorker($db,$devices,fn()=>['success'=>false,'expired'=>false,'status'=>429,'retryAfter'=>3600]);$retry->runOne();
 check((int)$db->one("SELECT next_run FROM push_deliveries WHERE event_id IS NULL")['next_run']>=time()+3599,'Retry-After korunur');
 $native=$devices->save($user,'desktop',['deviceId'=>str_repeat('c',64),'sound'=>true]);
 check($native['cursor']===1&&$devices->poll($native['token'],$native['cursor'])['items']===[],'Cihaz kaydında geçmiş sesler tekrarlanmaz');
 fails(fn()=>$devices->poll(str_repeat('d',64),0),401);
 $db->run("UPDATE users SET password='changed' WHERE id=1");fails(fn()=>$devices->poll($native['token'],0),401);
 $db->run("UPDATE users SET password='hash' WHERE id=1");$db->run('INSERT INTO account_controls(user_id,active) VALUES(1,0)');fails(fn()=>$devices->poll($native['token'],0),401);
 $db->run('UPDATE account_controls SET active=1 WHERE user_id=1');
 $devices->change($user,$registration['id'],['enabled'=>false,'sound'=>true]);
 fails(fn()=>$devices->change($user,$registration['id'],['enabled'=>true,'sound'=>true]),409);
 $backup=BackupArchive::read(BackupArchive::create($db,str_repeat('k',32)),str_repeat('k',32));
 check(!$backup['tables']['notification_devices']['rows']&&!$backup['tables']['push_deliveries']['rows'],'Yedek geri yükleme eski cihaz erişimini ve bildirimi yeniden açmaz');
 echo "Push: yetki, SSRF, şifreleme, tekrar engeli, kuyruk/retry, parola/hesap iptali, geçmiş sessizliği ve yedek güvenliği geçti.\n";
}finally{unlink($key);}

