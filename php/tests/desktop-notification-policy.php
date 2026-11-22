<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';
use Omnigo\{Database,NotificationSchema,DeliveryCipher,PushDevices,PushWorker,OrderNotifications,MobileNotificationPolicy};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme Firma')");
$db->run("INSERT INTO users VALUES(1,'admin@test.invalid','Yönetici','hash','admin',NULL)");
$user=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>str_repeat('a',64)];
$devices=new PushDevices($db,DeliveryCipher::withKey(str_repeat('k',32)));
$desktop=$devices->save($user,'desktop',['deviceId'=>str_repeat('d',64)]);
$pair=\Minishlink\WebPush\VAPID::createVapidKeys();
$web=$devices->save($user,'web',['subscription'=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/policy-test','keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]]]);
$insert=function(string $type,array $detail=[])use($db,$devices){$db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[1,in_array($type,['support','user'],true)?'portal':'n11',bin2hex(random_bytes(32)),$type,json_encode($detail),gmdate('c')]);$id=$db->insertId();$devices->enqueue($id);return $id;};
// A page containing only silent events must still advance and not hide the next page.
foreach(range(1,101) as $i)$insert('integration',['status'=>$i%2?'error':'recovered']);
check((int)$db->one('SELECT COUNT(*) n FROM push_deliveries')['n']===0,'Web bağlantı olayları kuyruğa alınmamalı.');
$page=$devices->poll($desktop['token'],0,true,true,true);
check($page['items']===[]&&$page['cursor']===100&&$page['hasMore'],'Sessiz ilk sayfa imleci korunmalı.');
$expected=[];
foreach([['order',[]],['operation',['events'=>['transferred']]],['operation',['events'=>['cancelled']]],['operation',['events'=>['transferred','cancelled']]],['shipment',['auditId'=>1,'operation'=>'edit']],['support',['targetId'=>1,'operation'=>'created']],['user',['targetId'=>1,'operation'=>'reply']]] as [$type,$detail]){
 $id=$insert($type,$detail);if($type!=='operation'||in_array('cancelled',$detail['events'],true))$expected[]=$id;
}
$page=$devices->poll($desktop['token'],$page['cursor'],true,true,true);
check(array_column($page['items'],'id')===$expected&&!$page['hasMore'],'Windows yeni sipariş, iptal ve tüm kullanıcı olaylarını almalı.');
check($devices->poll($desktop['token'],$page['cursor'],true,true,true)['items']===[],'İmleç ilerleyince olay tekrar gönderilmemeli.');
$snapshot=hash('sha256',json_encode($db->all('SELECT * FROM order_notifications ORDER BY id')));
// Emulate connection work queued by an older release before its payload is decoded.
$device=$devices->current($user,$web['id']);
$db->run('INSERT INTO push_deliveries(id,device_id,event_id,generation,next_run,created) VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(32)),$device['id'],1,$device['generation'],0,time()]);
$sent=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$sent){$sent[]=$message;return ['success'=>true,'expired'=>false,'status'=>200];});while($worker->runOne()){}
check(count($sent)===count($expected),'Web yalnız izinli olayları göndermeli; eski bağlantı işi iptal edilmeli.');
check($db->one('SELECT state FROM push_deliveries WHERE event_id=1')['state']==='cancelled','Eski kuyruk bağlantı işi tekrar denenmemeli.');
check($snapshot===hash('sha256',json_encode($db->all('SELECT * FROM order_notifications ORDER BY id'))),'Zil listesindeki hiçbir kayıt değiştirilmemeli.');
$silent=(new OrderNotifications($db))->arrivals(0,true,true,true);check($silent['items']===[]&&$silent['cursor']===100&&$silent['hasMore'],'Kaldırılan bağlantı olayları zil akışında gösterilmez; imleç ilerler.');
foreach(['web','desktop','android','ios'] as $kind)foreach(['{}','null','invalid','{"events":"cancelled"}','{"events":["transferred"]}'] as $payload)check(!MobileNotificationPolicy::allows(['kind'=>$kind],['event_type'=>'operation','payload'=>$payload]),'Eksik veya bozuk iptal kanıtı uyarı vermemeli.');
echo "Windows/web: sessiz sayfalama, kalıcı kayıtlar, iptal seçimi, kullanıcı olayları, eski kuyruk ve tekrarsızlık geçti.\n";
