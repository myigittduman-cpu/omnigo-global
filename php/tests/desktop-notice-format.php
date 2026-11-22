<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DesktopNoticeFormat,HttpError,NotificationSchema,PushDevices};

function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek 1'),(2,'Örnek 2')");
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode(['workspacePolicy'=>[
    'pages'=>['inventory'=>'shown','payments'=>'shown'],'defaults'=>['payments'=>['view'=>true]],
]])]);
$db->run("INSERT INTO users VALUES(1,'a@test.invalid','A','hash','admin',NULL),(2,'b@test.invalid','B','hash','client',1),(3,'c@test.invalid','C','hash','client',2)");
$devices=new PushDevices($db,DeliveryCipher::withKey(str_repeat('k',32)));
$register=function(int $id)use($db,$devices):string{
    $user=$db->one('SELECT * FROM users WHERE id=?',[$id]);
    $user['token']=str_repeat((string)$id,64);
    $db->run('INSERT INTO sessions VALUES(?,?,?,?)',[$user['token'],$id,'csrf',time()+3600]);
    return $devices->save($user,'desktop',['deviceId'=>str_repeat((string)$id,64)])['token'];
};
$admin=$register(1);$client=$register(2);
$add=function(string $event,string $audience,int $company=1)use($db):int{
    $key=bin2hex(random_bytes(12));
    $detail=['events'=>[$event],'audience'=>$audience,'section'=>$event==='store_setup_completed'?'overview':'inventory'];
    $db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',
        [$company,'portal',$key,'operation',json_encode($detail,JSON_THROW_ON_ERROR),gmdate('c')]);
    return $db->insertId();
};
$read=static fn(string $token,string $role,int $cursor,bool $current=false)=>$devices->poll($token,$cursor,true,true,true,$role,false,null,$current);

for($i=0;$i<100;$i++)$add('inventory_restocked','admin');
$legacy=$read($admin,'admin',0);
// One more event gives the previous format a known control on the next physical page.
$stock=$add('inventory_adjusted','admin');
$setupAdmin=$add('store_setup_completed','admin');$setupClient=$add('store_setup_completed','client');$add('store_setup_completed','client',2);
$payment=$add('payment_updated','client');
verify($legacy['items']===[]&&$legacy['cursor']===100,'Eski pakette sessiz sayfa ilerlemeli');
$legacyFirst=$read($admin,'admin',0);verify($legacyFirst['hasMore']&&$legacyFirst['cursor']===100,'Sonraki sayfa bilgisi kaybolmamalı');
$legacyNext=$read($admin,'admin',$legacyFirst['cursor']);verify(array_column($legacyNext['items'],'id')===[$stock],'Yeni olay eski pakette sonraki stok bildirimini engellememeli');
$current=$read($admin,'admin',0,true);verify(count($current['items'])===100&&$current['items'][0]['events']===['inventory_restocked'],'Yeni paket kesin stok olayı almalı');
$currentNext=$read($admin,'admin',$current['cursor'],true);verify(array_column($currentNext['items'],'id')===[$stock,$setupAdmin],'Yeni yönetici paketi yeni ve önceki olayları almalı');
verify($read($admin,'admin',$legacyNext['cursor'])['items']===[],'Tamamlanan olay tekrarlanmamalı');
verify(array_column($read($client,'client',0)['items'],'id')===[$payment],'Eski mağaza paketi yalnız tanıdığı ödeme olayını almalı');
$clientItems=$read($client,'client',0,true)['items'];
verify(array_column($clientItems,'id')===[$setupClient,$payment],'Yeni paket başka mağazanın veya yöneticinin olayını görmemeli');
verify(str_starts_with($clientItems[0]['url'],'/?view=overview&orderCompany=1&noticeType=operation&noticeId='),'Yeni paket kurulumun mağaza kapsamlı hedefini almalı');
verify((int)$db->one('SELECT COUNT(*) AS n FROM order_notifications')['n']===105,'Eski alıcı kalıcı zil kayıtlarını değiştirmemeli');
try{$read($client,'admin',0,true);throw new RuntimeException('Biçim rol denetimini geçememeli');}catch(HttpError $error){verify($error->getCode()===401,'Yetkisiz cihaz');}
verify(!DesktopNoticeFormat::supports(['type'=>'operation','events'=>['unknown_event']],'admin',true),'Bilinmeyen işlem imleci kilitlememeli');
echo "Önceki ve yeni Windows alıcısı, imleç, tekrar, firma ve rol yalıtımı geçti.\n";
