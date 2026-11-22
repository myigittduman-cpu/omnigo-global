<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/vendor/push/autoload.php';

use Omnigo\{AccountDirectorySchema,BackupArchive,ChatAccess,ChatDeletion,ChatNotifications,ChatSchema,ChatStore,Database,DeliveryCipher,HttpError,NotificationSchema,PushDevices,PushSchema,PushWorker,SupportSchema};

function check(bool $passed,string $message):void
{
    if(!$passed)throw new RuntimeException($message);
}
function denied(callable $action,int $status):void
{
    try{$action();}catch(HttpError $error){check($error->getCode()===$status,'Beklenmeyen erişim sonucu.');return;}
    throw new RuntimeException('Erişim engellenmeliydi.');
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
AccountDirectorySchema::ensure($db);NotificationSchema::migrate($db);PushSchema::install($db);SupportSchema::migrate($db);ChatSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Mağaza bir'),(2,'Mağaza iki')");
foreach([[1,'admin',null],[2,'admin',null],[3,'client',1],[4,'client',1],[5,'client',2]] as [$id,$role,$company]){
    $db->run('INSERT INTO users VALUES(?,?,?,?,?,?)',[$id,$id.'@example.test','Örnek '.$id,'hash',$role,$company]);
    $db->run('INSERT INTO sessions VALUES(?,?,?,?)',[str_repeat((string)$id,64),$id,'csrf',time()+3600]);
}
$user=fn(int $id)=>[...$db->one('SELECT * FROM users WHERE id=?',[$id]),'token'=>str_repeat((string)$id,64)];
$admin=$user(1);$otherAdmin=$user(2);$client=$user(3);$colleague=$user(4);$outsider=$user(5);
$chat=new ChatStore($db);$access=new ChatAccess($db);
$chat->presence($colleague);
check(array_column($access->contacts($client,2),'id')===[1,2,4],'Müşteri yalnız kendi mağazasındaki kişileri ve Omnigo’yu görmeli.');
check($access->contacts($client,2)[2]['online'],'Kısa aralıklı çevrimiçi bilgisi paylaşılmalı.');
denied(fn()=>$chat->create($client,['kind'=>'direct','members'=>[5]]),403);
denied(fn()=>$chat->create($client,['kind'=>'group','members'=>[1,4],'company'=>1,'title'=>'Yanlış grup','nonce'=>'client-admin-group-001']),403);
denied(fn()=>$chat->create($admin,['kind'=>'group','members'=>[5],'company'=>1,'title'=>'Yanlış mağaza','nonce'=>'admin-wrong-store-001']),403);
$clientGroup=$chat->create($client,['kind'=>'group','members'=>[4],'company'=>1,'title'=>'Mağaza ekibi','nonce'=>'client-same-store-001']);
check((int)$access->thread($colleague,$clientGroup)['company_id']===1,'Müşteri grup sohbeti kendi mağazasında açılır.');
denied(fn()=>$chat->detail($admin,$clientGroup,0),404);
denied(fn()=>$chat->create($admin,['kind'=>'group','members'=>[3,5],'title'=>'Çapraz mağaza','nonce'=>'cross-store-test-001']),403);
$direct=$chat->create($client,['kind'=>'direct','members'=>[1]]);
check($direct===$chat->create($admin,['kind'=>'direct','members'=>[3]]),'Birebir sohbet iki tarafta da aynı olmalı.');
$group=$chat->create($admin,['kind'=>'group','members'=>[2,3,4],'title'=>'Vardiya','nonce'=>'group-session-0001']);
$staff=$chat->create($admin,['kind'=>'group','members'=>[2],'title'=>'Ekip','nonce'=>'admin-session-0001']);
check($access->thread($admin,$staff)['company_id']===null,'Yönetici grubu bir müşteri mağazasına bağlı olmamalı.');
denied(fn()=>$chat->detail($outsider,$group,0),404);
denied(fn()=>$chat->detail($otherAdmin,$direct,0),404);

$key=tempnam(sys_get_temp_dir(),'omnigo-chat-test-');file_put_contents($key,random_bytes(32));
try{
    $devices=new PushDevices($db,new DeliveryCipher($key));
    $pair=\Minishlink\WebPush\VAPID::createVapidKeys();
    $subscription=static fn(string $tag)=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/'.$tag,
        'keys'=>['p256dh'=>$pair['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]];
    foreach([$admin,$otherAdmin,$client,$colleague,$outsider] as $member)$devices->save($member,'web',['subscription'=>$subscription((string)$member['id']),'sound'=>true]);
    $payload=['text'=>'Örnek özel sohbet metni','nonce'=>'message-request-001'];
    $id=$chat->send($admin,$group,$payload);
    check($id===$chat->send($admin,$group,$payload),'Yinelenen istek ikinci mesaj oluşturmamalı.');
    $targets=$db->all('SELECT d.user_id FROM push_deliveries q JOIN notification_devices d ON d.id=q.device_id ORDER BY d.user_id');
    check(array_map('intval',array_column($targets,'user_id'))===[2,3,4],'Bildirim yalnız grubun alıcı cihazlarına gitmeli.');
    check($chat->overview($client)['unread']===1&&$chat->overview($outsider)['unread']===0,'Okunmamış işareti alıcıya özgü olmalı.');
    $notices=(new ChatNotifications($db))->desktop($client,null);
    check(count($notices['items'])===1&&$notices['items'][0]['url']==='/?chatThread='.$group,'Masaüstü kuyruğu doğru sohbete gitmeli.');
    check((new ChatNotifications($db))->desktop($outsider,0)['items']===[],'Masaüstü bildirimi mağaza sınırını aşmamalı.');
    $sent=[];
    $worker=new PushWorker($db,$devices,function($sub,$notice)use(&$sent){$sent[]=$notice;return ['success'=>true,'status'=>201,'expired'=>false];});
    while($worker->runOne()){}
    check(count($sent)===3&&preg_match('/^[a-f0-9]{64}$/D',$sent[0]['tag']),'Cihaz kuyruğu üç özel bildirimi platforma uygun biçimde teslim etmeli.');
    check(!str_contains(json_encode($sent),$payload['text']),'Kilit ekranına mesaj metni çıkmamalı.');
    $chat->read($client,$group,$id);
    check($chat->overview($client)['unread']===0,'Açılan sohbet okunmuş sayılmalı.');
    denied(fn()=>$chat->delete($client,$group,$id),403);
    denied(fn()=>$chat->delete($otherAdmin,$group,$id),404);
    $chat->delete($admin,$group,$id);
    $message=$chat->detail($client,$group,0)['messages'][0];
    check($message['deleted']&&$message['text']===''&&!$message['canDelete'],'Yazar yönetici mesajı iki taraftan da silebilmeli.');

    $deletion=new ChatDeletion($db);
    $mine=[];
    for($i=0;$i<45;$i++){
        $db->run('INSERT INTO chat_messages(thread_id,sender_id,nonce,body,created) VALUES(?,?,?,?,?)',
            [$group,$admin['id'],'history-message-'.$i,'Önceki mesaj',time()-120]);
        $mine[]=$db->insertId();
    }
    $another=$chat->send($otherAdmin,$group,['text'=>'Diğer yöneticinin mesajı','nonce'=>'another-message-0001']);
    $initial=$chat->detail($client,$group,0,true);
    check($initial['hasMore']&&!in_array($mine[0],array_column($initial['messages'],'id'),true),'Eski mesaj ilk sayfanın dışında olmalı.');
    denied(fn()=>$deletion->messages($client,$group,['ids'=>[$mine[0]]]),403);
    denied(fn()=>$deletion->messages($admin,$group,['ids'=>[$mine[0],$another]]),404);
    check($deletion->availableMessages($admin,$group)['total']===45,'Karışık yetkili seçimde hiçbir mesaj silinmemeli.');
    check($deletion->messages($admin,$group,['ids'=>[$mine[1],$mine[2]]])===2,'Yönetici seçili mesajları birlikte silebilmeli.');
    $all=$deletion->availableMessages($admin,$group);
    $fresh=$chat->send($admin,$group,['text'=>'Seçimden sonraki mesaj','nonce'=>'fresh-message-0001']);
    $selection=['through'=>$all['through'],'except'=>[$mine[44]]];
    check($all['total']===43&&$deletion->messages($admin,$group,$selection)===42,'Tümünü seç, eski sayfaları kapsamalı ve istisnayı korumalı.');
    check($deletion->messages($admin,$group,$selection)===0,'Silmeyi yeniden denemek yeni mesajı etkilememeli.');
    foreach([$admin,$client,$colleague] as $participant){
        $visible=$chat->detail($participant,$group,0,true,$mine[0],$fresh);
        check(array_column($visible['messages'],'id')===[$mine[44],$another,$fresh],'Sohbet silinen mesajları yer tutucu olmadan kaldırmalı.');
        check(in_array($mine[0],$visible['removedIds'],true)&&count($visible['removedIds'])===44,'Önceden açılmış eski sayfa da silme bilgisini almalı.');
    }

    $hasThread=static fn(array $user,int $id):bool=>in_array($id,array_column($chat->overview($user)['threads'],'id'),true);
    $dismiss=[['id'=>$direct,'through'=>0],['id'=>$group,'through'=>$fresh]];
    denied(fn()=>$deletion->threads($client,$dismiss),403);
    denied(fn()=>$deletion->threads($admin,[...$dismiss,['id'=>9999,'through'=>0]]),404);
    check($hasThread($admin,$direct)&&$hasThread($admin,$group),'Geçersiz toplu konuşma seçimi atomik olmalı.');
    check($deletion->threads($admin,$dismiss)===2,'Birden fazla konuşma listeden kaldırılabilmeli.');
    check(!$hasThread($admin,$direct)&&!$hasThread($admin,$group)&&$hasThread($client,$direct)&&$hasThread($otherAdmin,$group),
        'Konuşma yalnız seçen yöneticinin listesinden kalkmalı.');
    $incoming=$chat->send($client,$group,['text'=>'Yeni gelen mesaj','nonce'=>'fresh-client-message-01']);
    $deletion->threads($admin,$dismiss);
    check($incoming>$fresh&&$hasThread($admin,$group)&&$chat->overview($admin)['unread']===1,'Yeni mesaj yeniden görünmeli ve eski isteğin tekrarından etkilenmemeli.');
    check($chat->create($admin,['kind'=>'direct','members'=>[3]])===$direct&&$hasThread($admin,$direct),'Birebir sohbet yeniden açılabilmeli.');

    $backup=BackupArchive::read(BackupArchive::create($db,str_repeat('x',32)),str_repeat('x',32));
    check(count($backup['tables']['chat_threads']['rows'])===4&&$backup['tables']['chat_presence']['rows']===[],'Sohbet geçmişi yedeklenmeli, anlık varlık kaydı geri yüklenmemeli.');
    check(count($backup['tables']['chat_thread_dismissals']['rows'])===1,'Kişisel konuşma durumu yedekte korunmalı.');
}finally{unlink($key);}
echo "Sohbet üyeliği, mağaza sınırı, çevrimiçi durum, tekrar güvenliği, bildirimler, okunma ve silme kontrolleri geçti.\n";
