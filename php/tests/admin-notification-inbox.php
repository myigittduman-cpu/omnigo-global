<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AdminNotificationInbox,CustomerNotificationPolicy,Database,HttpError,NotificationAudience,NotificationFeed,NotificationResumePolicy,NotificationSchema,ShipmentSchema,SupportSchema};

function expect(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function denied(callable $action,int $status):void {
    try{$action();}catch(HttpError $error){expect($error->getCode()===$status,'Hatalı durum kodu.');return;}
    throw new RuntimeException('Erişim reddedilmeliydi.');
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'İlk mağaza'),(2,'İkinci mağaza'),(3,'Yeni mağaza')");
$db->run("INSERT INTO users VALUES(1,'a@test.test','A','hash','admin',NULL),(2,'b@test.test','B','hash','client',1),(3,'c@test.test','C','hash','client',2),(4,'d@test.test','D','hash','admin',NULL)");
$users=[];foreach($db->all('SELECT id,role,company_id FROM users') as $user)$users[$user['id']]=$user;
foreach([1,2,3] as $company){
    $policy=['pages'=>['orders'=>[1=>'shown',2=>'hidden',3=>'client'][$company],'inventory'=>$company===3?'hidden':'shown','payments'=>'shown'],'defaults'=>['payments'=>['view'=>true]]];
    $db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>$policy])]);
}
$sequence=0;
$notice=function(int $company,string $type,array $payload=[])use($db,&$sequence):array{
    $sequence++;
    $db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[$company,'n11',hash('sha256',(string)$sequence),$type,json_encode($payload),gmdate('c',1700000000+$sequence)]);
    return $db->one('SELECT * FROM order_notifications WHERE id=?',[$db->insertId()]);
};
$global=new AdminNotificationInbox($db);
$feed=new NotificationFeed($db);
$allows=fn($user,$event)=>NotificationAudience::permits($user,$event)&&CustomerNotificationPolicy::recipient($db,$user,$event);
$before=$global->listing($users[1],'unread','stocks');
expect($before['counts']['all']===0&&in_array('stocks',$before['availableAdminCategories'],true),'Boş ortak liste kullanılabilir alanları korur.');
foreach([1,2,3] as $company){
    $events[$company]=$notice($company,'operation',['events'=>['inventory_restocked'],'quantity'=>3,'section'=>'inventory']);
    $newOrders[$company]=$notice($company,'order');
    $payments[$company]=$notice($company,'operation',['events'=>['payment_updated'],'operation'=>'created','audience'=>'admin']);
    $clients[$company]=$notice($company,'operation',['events'=>['payment_updated'],'operation'=>'created','audience'=>'client']);
}
$stocks=$global->listing($users[1],'all','stocks');
expect(array_column($stocks['items'],'companyId')===[2,1]&&$stocks['counts']['all']===2,'Yeni stoğun mağaza kaynağı ve global sırası doğru olmalı.');
expect($global->listing($users[1],'all','marketplaces')['counts']['all']===3,'Pazaryeri sekmesi yalnız siparişleri göstermeli.');
$paymentList=$global->listing($users[1],'all','payments');
expect($paymentList['counts']['all']===3&&in_array('payments',$paymentList['availableAdminCategories'],true),'Hakedişler kendi sekmesinde sayılmalı.');
$paymentBatch=$global->bulk($users[1],(array)json_decode(json_encode($paymentList['through'],JSON_THROW_ON_ERROR)),true,'payments');
expect($global->listing($users[1],'all','payments')['counts']['all']===0&&$global->listing($users[1],'all','marketplaces')['counts']['all']===3,'Hakediş temizliği siparişlere taşmamalı.');
$global->undo($users[1],$paymentBatch,'marketplaces');
expect($global->listing($users[1],'all','payments')['counts']['all']===0,'Pazaryeri geri alması hakedişleri getirmemeli.');
$global->undo($users[1],$paymentBatch,'payments');
expect($global->listing($users[1],'all','payments')['counts']['all']===3,'Hakediş geri alması kendi kayıtlarını getirmeli.');
foreach($newOrders as $company=>$event){
    expect($allows($users[1],$event)&&$allows($users[4],$event),'Siparişler menüsü gizli olsa da her iki yönetici yeni siparişi almalı.');
    expect(!$allows($users[2],$event)&&!$allows($users[3],$event),'Genel yeni sipariş bildirimi mağaza kullanıcılarına gönderilmemeli.');
}
expect($feed->listing(2,1,0,'all','payments')['counts']['all']===1&&$feed->listing(2,1,0,'all','stocks')['counts']['all']===0,'Mağaza kullanıcısı yalnız kendi hakediş zarfını alır.');
expect($allows($users[1],$payments[2])&&$allows($users[4],$payments[1])&&$allows($users[3],$clients[2]),'Push doğru alıcılara ulaşır.');
expect(!$allows($users[2],$clients[2])&&!$allows($users[2],$payments[1])&&!$allows($users[1],$clients[2])&&!$allows($users[4],$events[3]),'Push mağaza, rol, kaynak ve menü sınırlarını korur.');
denied(fn()=>$global->listing($users[2],'all','stocks'),403);
denied(fn()=>$feed->listing(2,2,0,'all','payments'),403);
$boundaries=(array)json_decode(json_encode($stocks['through']));
denied(fn()=>$global->bulk($users[1],$boundaries+[999=>(object)[]],false,'stocks'),400);
expect($global->listing($users[1],'unread','stocks')['counts']['unread']===2,'Hatalı mağaza kimliği kısmi güncelleme yapamaz.');
$notice(1,'operation',['events'=>['inventory_low'],'section'=>'inventory','quantity'=>0]);
$global->bulk($users[1],$boundaries,false,'stocks');
expect($global->listing($users[1],'all','stocks')['counts']===['all'=>3,'unread'=>1,'read'=>2],'Okundu işlemi anlık sınır sonrasını korur.');
expect($global->listing($users[4],'all','stocks')['counts']['unread']===3,'Diğer yöneticinin durumu korunur.');
$batch=$global->bulk($users[1],$boundaries,true,'stocks');
expect($global->listing($users[1],'all','stocks')['counts']['all']===1,'İki mağazada temizlik yalnız seçili kategori ve sınıra uygulanır.');
$global->undo($users[1],$batch,'users');
expect($global->listing($users[1],'all','stocks')['counts']['all']===1,'Başka kategorinin geri alması stok kayıtlarını etkilemez.');
$global->undo($users[1],$batch,'stocks');
expect($global->listing($users[1],'all','stocks')['counts']['all']===3,'Tek kişisel toplu işlem iki mağazada geri alınır.');
for($i=0;$i<40;$i++)$notice($i%2+1,'order');
$globalOrders=$global->listing($users[1],'all','marketplaces');
expect(count($globalOrders['items'])===30&&$globalOrders['counts']['all']===43,'Ortak liste tek sayfa nüfusu kadar ayrıntı döndürür.');
$ids=array_column($globalOrders['items'],'id');$sorted=$ids;rsort($sorted,SORT_NUMERIC);
expect($sorted===$ids&&count(array_unique($ids))===30,'Mağazalar birleştirilirken sıra ve ayrıntı kimliği korunur.');
$orders=new \Omnigo\OrderNotifications($db);
$sample=['provider'=>'Trendyol','createdAt'=>gmdate('c',time()-3600),'orderNumber'=>'Deneme-1'];
expect(!$orders->observe(2,$sample),'Eski Trendyol siparişi bağlanma anında uyarı üretemez.');
$sample['createdAt']=gmdate('c');
expect($orders->observe(2,$sample)&&!$orders->observe(2,$sample),'Aynı Trendyol siparişi yalnız bir kez yeni sipariş bildirimi üretir.');
expect($global->listing($users[1],'unread','marketplaces')['items'][0]['provider']==='trendyol','Mağazanın yeni Trendyol siparişi ortak zilde görünür.');
$profile=json_decode($db->one('SELECT payload FROM company_profiles WHERE company_id=2')['payload'],true,32,JSON_THROW_ON_ERROR);
$disabled=$profile;
$disabled['workspacePolicy']['notifications']['newOrder']=false;
$db->run('UPDATE company_profiles SET payload=? WHERE company_id=2',[json_encode($disabled)]);
expect(!$allows($users[1],$newOrders[2])&&$allows($users[1],$newOrders[3]),'Yeni siparişleri kapatma tercihi yalnız seçilen mağazaya uygulanmalı.');
$floor=NotificationResumePolicy::update($db,2,$disabled,$profile);
$profile['notificationResume']=$floor;
$db->run('UPDATE company_profiles SET payload=? WHERE company_id=2',[json_encode($profile)]);
expect(!$allows($users[4],$newOrders[2])&&!isset($floor[3]['newOrder']),'Tercih açıldığında önceki siparişler cihaza tekrar gönderilmemeli ve müşteriye sıra açılmamalı.');
expect($allows($users[4],$notice(2,'order')),'Tercih yeniden açıldıktan sonraki sipariş yöneticiye iletilmeli.');
echo "İki yöneticide ortak zil, iki mağaza, yeni mağazada kapalı menü, rol/mağaza izolasyonu, okundu/silindi/geri alma ve sıralama geçti.\n";
