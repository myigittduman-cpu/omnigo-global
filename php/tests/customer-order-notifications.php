<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{CustomerInitialPolicy,CustomerNotificationPolicy,CustomerOrderNotifications,CustomerPermissions,CustomerServiceNotifications,Database,DeliveryCipher,DeliveryMapper,DeliveryRepository,DeliverySchema,DesktopClientNotifications,InventorySchema,MobileNotificationPolicy,MobilePushMessage,NotificationAudience,NotificationFeed,NotificationSchema,NotificationState,OperationalNotifications,OrderDetailMapper,ShipmentOrderIngestor,ShipmentOrderMapper,ShipmentSchema,SupportSchema};

function check(bool $result,string $message):void{if(!$result)throw new RuntimeException($message);}

$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek mağaza'),(2,'Diğer mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@test.invalid','Yönetici','x','admin',NULL),(2,'a@test.invalid','Müşteri','x','client',1),(3,'b@test.invalid','Kısıtlı','x','client',1),(4,'c@test.invalid','Diğer müşteri','x','client',2)");
$profile=CustomerInitialPolicy::template($db);
$restricted=$profile['defaults'];$restricted['deliveries']=array_fill_keys(array_keys($restricted['deliveries']),false);
$profile['users']=(object)[3=>$restricted];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode(['deliveryCities'=>['Ankara'],'activeDeliveryCities'=>['Ankara'],'workspacePolicy'=>$profile],JSON_THROW_ON_ERROR)]);
foreach(range(1,4) as $user)$db->run("INSERT INTO notification_devices(user_id,kind,device_key,credential_tag,session_token,expires,last_seen) VALUES(?,'web',?,?,?, ?,?)",[$user,str_repeat((string)$user,64),str_repeat('a',64),str_repeat('b',64),time()+3600,time()]);
$actors=[];foreach($db->all('SELECT id,role,company_id FROM users') as $actor)$actors[(int)$actor['id']]=$actor;
$cipher=DeliveryCipher::withKey(str_repeat('c',32));$orders=new DeliveryRepository($db,$cipher);
ShipmentOrderIngestor::activate($db,1,time()-86400);

function mappedOrder(string $number,string $status,bool $withoutPackageId=false,string $city='Ankara'):array
{
    $raw=['id'=>$withoutPackageId?null:$number,'orderNumber'=>$number,'customerfullName'=>'Örnek Alıcı','shippingAddress'=>['fullName'=>'Örnek Alıcı','gsm'=>'05001234567','city'=>$city,'district'=>'Örnek İlçe','address'=>'Örnek adres'],
        'packageHistories'=>[['status'=>'Created','createdDate'=>(time()-180)*1000]],'shipmentPackageStatus'=>$status,
        'lines'=>[['orderLineId'=>$number.'-1','orderItemLineItemStatusName'=>$status,'productName'=>'Örnek ürün','quantity'=>1,'sellerInvoiceAmount'=>'100.00']]];
    $order=DeliveryMapper::n11($raw);
    return [...$order,'details'=>OrderDetailMapper::n11($raw,$order),'shipmentItems'=>ShipmentOrderMapper::n11($raw,$order)];
}

$import=fn(string $id,string $status='Created',bool $notify=true,bool $withoutPackageId=false,string $city='Ankara')=>
    $db->transaction(fn()=>$orders->ingest(1,mappedOrder($id,$status,$withoutPackageId,$city),$notify));
$rows=fn()=>array_values(array_filter($db->all("SELECT * FROM order_notifications ORDER BY id"),fn($row)=>CustomerOrderNotifications::event(json_decode($row['payload'],true))!==null));
$events=fn()=>array_map(fn($row)=>CustomerOrderNotifications::event(json_decode($row['payload'],true)),$rows());
$configure=function(array $settings)use($db,$actors):array{
    $policy=new CustomerPermissions($db,1);$config=$policy->configuration();
    return $policy->save($actors[1],[...$config,'notifications'=>array_replace($config['notifications'],$settings),'userChanges'=>(object)[]]);
};

$import('100');
check(!$events(),'Kapalı seçeneklerden bildirim üretilmemeli.');
$configure(['deliveryAdded'=>true,'shipmentCancelled'=>true]);
$import('101');$import('101');$import('101','Cancelled',true,true);$import('101','Cancelled',true,true);
check($events()===[CustomerOrderNotifications::DELIVERY_ADDED],'Yeni sipariş bildirimi tekil olmalı; müşteri iptal bildirimi üretilmemeli.');
check(!array_filter($orders->listing(1,'all','all','all',0)['items'],fn($row)=>$row['orderNumber']==='101'),'İptal, aktif evrak listesinden çıkmalı.');
$configure(['deliveryAdded'=>false,'shipmentCancelled'=>false,'deliveryCancelled'=>true,'shipmentAdded'=>true]);
$import('102');$import('102','Cancelled');$import('102','Cancelled');
check($events()===[CustomerOrderNotifications::DELIVERY_ADDED,CustomerOrderNotifications::SHIPMENT_ADDED],'Sevkiyat için yalnız yeni sipariş bildirimi üretilmeli.');
$import('103','Cancelled');$import('104','Created',false);$import('105','Created',true,false,'İstanbul');
check(count($rows())===2,'İlk kez iptal olarak gelen, geçmişe dönük ve şehir kapsamı dışı sipariş bildirim üretmemeli.');

$configure(['deliveryAdded'=>true]);
check(!in_array((int)$rows()[0]['id'],array_column((new NotificationFeed($db,$cipher))->listing(2,1,0)['items'],'id'),true),'Yeniden açınca eski bildirim geri gelmemeli.');
$db->transaction(fn()=>(new CustomerOrderNotifications($db,1))->publish('n11','fresh-delivery',[CustomerOrderNotifications::DELIVERY_ADDED]));
$deliveryEvent=$rows()[2];$shipmentEvent=$rows()[1];
$legacyPayload=json_encode(['audience'=>'client','events'=>[CustomerOrderNotifications::DELIVERY_CANCELLED]],JSON_THROW_ON_ERROR);
$db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(1,'n11',?,'operation',?,?)",[hash('sha256','legacy-cancelled'),$legacyPayload,gmdate('c')]);
$legacyEvent=$rows()[3];
check(!CustomerNotificationPolicy::recipient($db,$actors[2],$legacyEvent),'Önceden üretilmiş iptal bildirimi müşteriye teslim edilmemeli.');

$read=$deliveryEvent;$orderId=(int)$read['id'];
check(NotificationAudience::permits($actors[2],$read)&&!NotificationAudience::permits($actors[1],$read)&&!NotificationAudience::permits($actors[4],$read),'Cihaz erişimi doğru müşteri firmasıyla sınırlı olmalı.');
check(!CustomerNotificationPolicy::recipient($db,$actors[3],$read),'Sayfayı görmeyen kullanıcı bildirimi açamamalı.');
check((new OperationalNotifications($db,$cipher))->detail(1,$orderId)['view']==='deliveries','Yetkili ayrıntısı gerçek bölümü açmalı.');
check(MobileNotificationPolicy::allows(['kind'=>'web'],$read)&&MobilePushMessage::create([],$read,'x','client')['url']==='/?view=deliveries&orderCompany=1&noticeType=operation&noticeId='.$orderId,'Cihaz bildirimi güvenli sayfa ve ayrıntı hedefini taşımalı.');
check(count($db->all('SELECT p.device_id FROM push_deliveries p JOIN notification_devices d ON d.id=p.device_id WHERE p.event_id=? AND d.user_id IN (1,4)',[$orderId]))===0,'Bildirim başka firmaların ve yöneticilerin cihaz kuyruğuna yazılmamalı.');
$clientFeed=(new NotificationFeed($db,$cipher))->listing(2,1,0);
check(array_column($clientFeed['items'],'id')===[$orderId,(int)$shipmentEvent['id']],'Zil yalnız açık seçenekleri göstermeli.');
check(array_column((new NotificationFeed($db,$cipher))->listing(3,1,0)['items'],'id')===[(int)$shipmentEvent['id']],'Evrak erişimi olmayan hesap yalnız sevkiyat uyarısı almalı.');
check(!(new NotificationFeed($db,$cipher))->listing(4,2,0)['items'],'Başka mağazanın zili boş kalmalı.');
check(!array_intersect(array_column((new NotificationFeed($db,$cipher))->listing(1,1,0)['items'],'id'),array_column($rows(),'id')),'Müşteri olayları yönetici ziline karışmamalı.');
$arrivals=(new DesktopClientNotifications($db))->arrivals($actors[2],0)['items'];
check(array_column($arrivals,'view')===['shipments','deliveries'],'Masaüstü akışı yalnız açık seçeneklerin hedeflerini döndürmeli.');
(new NotificationState($db))->read(2,1,'operation',$orderId);
check((new NotificationFeed($db,$cipher))->listing(2,1,0,'read')['items'][0]['id']===$orderId,'Okundu işlemi yeni bildirim türünde çalışmalı.');
$db->transaction(fn()=>CustomerServiceNotifications::payment($db,$actors[1],1,50,50,false));
$feed=new NotificationFeed($db,$cipher);
$state=new NotificationState($db);
$ordersTab=$feed->listing(2,1,0,'all','customer-orders');
$paymentsTab=$feed->listing(2,1,0,'all','payments');
$legacyTab=$feed->listing(2,1,0,'all','marketplaces');
check($ordersTab['counts']['all']===2&&array_unique(array_column($ordersTab['items'],'category'))===['customer-orders'],'Sipariş sekmesinde yalnız iki sipariş olayı olmalı.');
check($paymentsTab['counts']['all']===1&&$paymentsTab['items'][0]['category']==='payments','Hakediş ayrı sekmede kalmalı.');
check($legacyTab['counts']['all']===3&&$legacyTab['categoryCounts']['marketplaces']['all']===3&&$legacyTab['totalUnread']===2,'Eski panel iki türü göstermeli; zil sayacı çift saymamalı.');
check($feed->listing(3,1,0,'all','customer-orders')['counts']['all']===1,'Sipariş sekmesinde bölüm yetkisi korunmalı.');
check($feed->listing(1,1,0,'all','customer-orders')['counts']['all']===0&&$feed->listing(4,2,0,'all','customer-orders')['counts']['all']===0,'Sipariş sekmesi rol ve mağaza sınırlarını korumalı.');
$state->bulk(2,1,$legacyTab['through'],false,'customer-orders');
check($feed->listing(2,1,0,'unread','customer-orders')['counts']['unread']===0&&$feed->listing(2,1,0,'unread','payments')['counts']['unread']===1,'Siparişleri okuma hakediş bildirimini etkilememeli.');
$batch=$state->bulk(2,1,$legacyTab['through'],true,'customer-orders');
check($feed->listing(2,1,0,'all','customer-orders')['counts']['all']===0&&$feed->listing(2,1,0,'all','payments')['counts']['all']===1,'Siparişleri temizleme hakediş bildirimini korumalı.');
$state->undo(2,1,$batch,'payments');
check($feed->listing(2,1,0,'all','customer-orders')['counts']['all']===0,'Hakediş sekmesi sipariş silme işlemini geri almamalı.');
$state->undo(2,1,$batch,'customer-orders');
check($feed->listing(2,1,0,'all','customer-orders')['counts']['all']===2,'Sipariş bildirimleri kendi sekmesinden geri alınabilmeli.');
echo "Müşteri sipariş bildirimleri, iptal gizliliği, mağaza ve rol kapsamı, zil ve cihaz hedefi doğrulandı.\n";
