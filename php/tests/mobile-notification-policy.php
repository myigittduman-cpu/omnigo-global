<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,DeliveryCipher,PushDevices,MobilePushDevices,PushWorker,MobileNotificationPolicy};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme Firma')");
$db->run("INSERT INTO users VALUES(1,'admin@test.invalid','Yönetici','hash','admin',NULL),(2,'client@test.invalid','Müşteri','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('k',32));$devices=new PushDevices($db,$cipher);$mobile=new MobilePushDevices($db,$cipher);
foreach([1=>'admin',2=>'client'] as $id=>$role){
 $token=str_repeat((string)$id,64);$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$token,$id,'csrf',time()+3600]);
 $user=[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>$token];
 foreach(['android','ios'] as $kind)$mobile->save($user,['kind'=>$kind,'appId'=>'com.omnigoglobal.'.$role,'installationId'=>hash('sha256',$role.$kind),'token'=>$kind==='android'?str_repeat('x',140):str_repeat('a',64),'environment'=>'production','sound'=>true]);
}
$insert=function(string $type,string $payload='{}')use($db){$db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[1,'n11',bin2hex(random_bytes(32)),$type,$payload,gmdate('c')]);return $db->insertId();};
$blocked=[];
foreach(['integration','unknown'] as $type){
 $id=$insert($type);$blocked[]=$id;$devices->enqueue($id);
 check((int)$db->one('SELECT COUNT(*) n FROM push_deliveries WHERE event_id=?',[$id])['n']===0,'Bağlantı/bilinmeyen mobil olay sıraya girmemeli: '.$type);
 check(!MobileNotificationPolicy::allows(['kind'=>'web'],['event_type'=>$type]),'Web bağlantı uyarısı sessiz kalmalı.');
 check(!MobileNotificationPolicy::allows(['kind'=>'desktop'],['event_type'=>$type]),'Windows bağlantı uyarısı sessiz kalmalı.');
 // Simulate jobs queued before deployment. Invalid old payload must be cancelled, not retried.
 foreach($db->all('SELECT * FROM notification_devices') as $device)$db->run('INSERT INTO push_deliveries(id,device_id,event_id,generation,next_run,created) VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(32)),$device['id'],$id,$device['generation'],0,time()]);
}
$snapshot=hash('sha256',json_encode($db->all('SELECT * FROM order_notifications ORDER BY id')));
$sent=[];$worker=new PushWorker($db,$devices,function($subscription,$message)use(&$sent){$sent[]=$message;return ['success'=>true,'expired'=>false,'status'=>200];});
while($worker->runOne()){}
check($sent===[],'Eski kuyruktaki bağlantı/bilinmeyen olay telefona gitmemeli.');
check((int)$db->one("SELECT COUNT(*) n FROM push_deliveries WHERE state='cancelled'")['n']===8,'Eski Android/iOS işleri tekrar denenmeden kapatılmalı.');
check($snapshot===hash('sha256',json_encode($db->all('SELECT * FROM order_notifications ORDER BY id'))),'Bildirim penceresinin olay kayıtları değişmemeli.');
$order=$insert('order');$devices->enqueue($order);$devices->enqueue($order);
check((int)$db->one('SELECT COUNT(*) n FROM push_deliveries WHERE event_id=?',[$order])['n']===4,'Yeni sipariş cihaz başına bir kez kuyruğa alınmalı.');
while($worker->runOne()){}
check(count($sent)===2,'Yeni siparişi yalnız yönetici Android ve iOS cihazları almalı.');
foreach($sent as $message)check($message['title']==='Omnigo Global Yönetici Portalı'&&str_contains($message['body'],'yeni bir sipariş')&&str_contains($message['url'],'view=orders'),'Yeni sipariş başlığı ve hedefi korunmalı.');
foreach(['operation','shipment','support','user','resolved'] as $type){
 $payload=$type==='resolved'?['audience'=>'client','recipientId'=>2,'targetId'=>5,'operation'=>'resolved']:['audience'=>'admin','actorId'=>2,'targetId'=>5,'auditId'=>123,'operation'=>'created','events'=>['cancelled']];
 $id=$insert($type,json_encode($payload));$devices->enqueue($id);$devices->enqueue($id);
 check((int)$db->one('SELECT COUNT(*) n FROM push_deliveries WHERE event_id=?',[$id])['n']===4,'İzin verilen olay cihaz başına bir kez sıraya alınmalı: '.$type);
 $before=count($sent);while($worker->runOne()){}
 check(count($sent)===$before+2,'İzin verilen olayı yalnız doğru roldeki Android/iOS cihazları almalı: '.$type);
 if($type==='operation')foreach(array_slice($sent,$before) as $message)check(str_contains($message['body'],'Sipariş iptal edildi'),'İptal bildirimi genel güncelleme başlığıyla gösterilmemeli.');
 $roles=array_column($db->all("SELECT u.role FROM push_deliveries p JOIN notification_devices d ON d.id=p.device_id JOIN users u ON u.id=d.user_id WHERE p.event_id=? AND p.state='delivered'",[$id]),'role');
 check($roles===[$type==='resolved'?'client':'admin',$type==='resolved'?'client':'admin'],'Müşteri yalnız kendi destek çözümünü almalı: '.$type);
}
$retryOrder=$insert('order');$devices->enqueue($retryOrder);
$retry=new PushWorker($db,$devices,fn()=>['success'=>false,'expired'=>false,'status'=>429,'retryAfter'=>300]);$retry->runOne();
check((int)$db->one("SELECT next_run FROM push_deliveries WHERE event_id=? AND state='pending' AND attempts=1",[$retryOrder])['next_run']>=time()+299,'Yeni siparişin hız sınırı ve tekrar denemesi korunmalı.');
check(MobileNotificationPolicy::allows(['kind'=>'android'],null),'Kullanıcının isteğiyle yapılan cihaz testi ayrı kalmalı.');
echo "Mobil kapsam: kullanıcı/pazaryeri, bağlantı engeli, Android/iOS ve rol ayrımı, eski kuyruk iptali, tekrar engeli, retry, bildirim kayıtlarının korunması ve web/Windows sınırı geçti.\n";
