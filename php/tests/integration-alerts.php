<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,IntegrationAlertMonitor,MailQueue,PushDevices,PushWorker,DeliveryCipher,OrderNotifications,NotificationFeed,NotificationState,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);Omnigo\ShipmentSchema::install($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Firma'),(2,'Diğer Firma')");$db->run("INSERT INTO users VALUES(1,'admin@omnigoglobal.com','Yönetici','hash','admin',NULL)");
$user=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>str_repeat('a',64)];$cipher=DeliveryCipher::withKey(str_repeat('a',32));$devices=new PushDevices($db,$cipher);
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();$subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/test1','keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]];
$devices->save($user,'web',['subscription'=>$subscription,'sound'=>true]);$devices->save($user,'web',['subscription'=>[...$subscription,'endpoint'=>'https://web.push.apple.com/test2'],'sound'=>false]);
$native=$devices->save($user,'desktop',['deviceId'=>str_repeat('b',64)]);
foreach([['idefix','idefix','active'],['Teknosa','teknosa','inactive'],['PTT AVM','pttavm','active']] as [$name,$provider,$active]){
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode(['name'=>$name,'status'=>$active]),gmdate('c')]);
 if($provider!=='pttavm')$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(1,?,?,?)',['integration_'.$provider,'{}',gmdate('c')]);
 $db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(1,?,'blocked',?,1)",[$name,time()]);
}
$mail=new MailQueue($db,['mail'=>['enabled'=>true,'from'=>'no-reply@test.invalid','transport'=>'mail','key'=>base64_encode(str_repeat('m',32))]]);
$pushes=0;$monitor=new IntegrationAlertMonitor($db,$mail,function()use(&$pushes){$pushes++;});$now=time()-1000;
$monitor->run($now);$monitor->run($now+899);
check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0&&$pushes===0,'Kısa kesinti zil veya push üretmez');
check((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===0,'Kısa kesinti e-postası yok');
$monitor->run($now+900);$monitor->run($now+901);
check((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===1,'Uzun kesinti tek e-posta üretir; pasif ve kurulmamış kanallar dışarıdadır');
check((int)$db->one('SELECT COUNT(*) n FROM push_deliveries')['n']===0&&$devices->poll($native['token'],0,true,true,true)['items']===[],'Hiçbir cihaz bağlantı uyarısı almaz');
check((new NotificationFeed($db))->listing(1,1,0)['counts']['all']===0,'Bağlantı sorunu zil sayacını artırmaz');
check(count($db->all('SELECT * FROM notification_devices WHERE enabled=1'))===3,'Normal bildirim cihazları açık kalır');
$state=json_decode($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='integration_health'")['payload'],true);
check($state['idefix']['open']&&$state['idefix']['outageMailQueued'],'Bağlantı sayfasının kesinti kaydı korunur');
echo "Bağlantı: sessiz kısa kesinti, gecikmeli tek e-posta, pasif kanal ayrımı, zil/push kaldırma ve diğer cihazları koruma geçti.\n";
