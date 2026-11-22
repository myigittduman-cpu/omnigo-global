<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,MarketplaceVisibility,DeliverySchema,ShipmentSchema,SupportSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,DeliverySelection,DeliveryDocuments,NotificationFeed,PaymentCalendar,ConnectionHealth,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function hidden(callable $fn):void{try{$fn();}catch(HttpError $e){check($e->getCode()===404,'Gizli seçim 404 dönmeli');return;}throw new RuntimeException('Gizli seçim açıldı');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);SupportSchema::migrate($db);NotificationSchema::migrate($db);
$repo=new Repository($db);$company=$repo->createCompany('Görünürlük testi',Repository::CHANNELS);$other=$repo->createCompany('Diğer firma',Repository::CHANNELS);
$db->run("INSERT INTO users(email,name,password,role) VALUES('admin@test.invalid','Test','hash','admin')");$admin=['id'=>$db->insertId(),'role'=>'admin'];
$cipher=DeliveryCipher::withKey(str_repeat('v',32));$delivery=new DeliveryRepository($db,$cipher);
$save=function(string $kind,array $value)use($db,$company){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($value),gmdate('c')]);};
foreach(Repository::CHANNELS as $channel){
 $save('sales',['channel'=>$channel,'month'=>'2026-10','grossCents'=>100,'cancelCents'=>0,'returnCents'=>0,'orders'=>1]);
 $save('payments',['channel'=>$channel,'expectedDate'=>'2026-10-15','paidDate'=>'','status'=>'pending','expectedCents'=>200]);
}
foreach(['TEKNOSA','teknosa'] as $provider)check(!MarketplaceVisibility::visible($provider),'Takma ad gizlenmeli');
foreach(['PTT AVM','ptt_avm','pttavm','TRENDYOL'] as $provider)check(MarketplaceVisibility::visible($provider),'PttAVM takma adları görünür olmalı');
$insert=function(string $provider,int $n,int $tenant=0)use($db,$company,$cipher){
 $tenant=$tenant?:$company;$external='TEST-'.$provider.'-'.$n;
 $products=[['sku'=>'TEST','title'=>'Örnek ürün','quantity'=>1,'shippingState'=>$n%2?'pending':'shipped']];
 $value=['provider'=>$provider,'externalId'=>$external,'orderNumber'=>$external,'createdAt'=>gmdate('c',time()-6*86400),'active'=>true,'city'=>'Ankara','buyerName'=>'Örnek','phone'=>'05000000000','address'=>'Örnek adres','district'=>'Çankaya','recipientName'=>'Örnek Alıcı','issues'=>[],'products'=>$products,'details'=>['products'=>$products]];
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$provider,$external,'Ankara',1,$cipher->seal($tenant,'order:'.$provider.':'.$external,$value),'fixture',gmdate('c')]);return $db->insertId();
};
for($n=1;$n<=55;$n++){$visibleId=$insert('n11',$n);$hiddenId=$insert('Teknosa',$n);}
$insert('PTT AVM',1);$insert('n11',1,$other);
$request=fn($id)=>['city'=>'Ankara','deliveryDate'=>'2026-10-15','orders'=>[['id'=>$id,'version'=>1]]];
$docs=new DeliveryDocuments($db,$cipher,$delivery);$webDocs=new DeliveryDocuments($db,$cipher,$delivery,true);
$mixed=$docs->create($company,$admin['id'],[...$request($visibleId),'orders'=>[['id'=>$visibleId,'version'=>1],['id'=>$hiddenId,'version'=>1]],'requestId'=>str_repeat('a',32)]);
// Populate a hidden-only archive after the mixed one; it must not produce an empty page.
$hiddenArchive=$docs->create($company,$admin['id'],[...$request($hiddenId-2),'requestId'=>str_repeat('b',32)]);
$before=$db->all('SELECT * FROM records');$ordersBefore=$db->all('SELECT * FROM delivery_orders');$documentsBefore=$db->all('SELECT * FROM delivery_documents');
foreach(['admin','client'] as $role){
 $dashboard=$repo->dashboard(['role'=>$role,'company_id'=>$company],$company,'2026-10');
 check(array_column($dashboard['channels'],'name')===['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol'],'İki panelde görünür katalog');
 check($dashboard['summary']['grossCents']===700&&$dashboard['summary']['expectedCents']===1400,'Özetler gizli kanalları saymamalı');
 check(count($dashboard['sales'])===7&&count($dashboard['payments'])===7,'İki panelde kayıt listeleri');
}
check(count($repo->records($company,'channels',$admin))===8,'İç katalog ve bağlantı kayıtları korunur');
$list=$delivery->listing($company,'all','all','all',0,50,true);
check(count($list['items'])===50&&$list['hasMore'],'Teslimat filtresi sayfalamadan önce uygulanmalı');
check($list['cities'][0]['count']===56,'Şehir sayacı gizli kayıtları ve diğer firmayı saymamalı');
$second=$delivery->listing($company,'all','all','all',1,50,true);
check(count($second['items'])===6&&!$second['hasMore'],'Son sayfa görünür kayıtları tamamlamalı');
foreach(['delayed'=>29,'shipped'=>27] as $mode=>$count){$q=$delivery->listing($company,'all','all',$mode,0,50,true);check(count($q['items'])===$count,'Geciken/kargodaki listeler de süzülmeli');}
hidden(fn()=>$delivery->listing($company,'all','Teknosa','all',0,50,true));
$selection=new DeliverySelection($db,$delivery);
hidden(fn()=>$selection->snapshot($company,$request($hiddenId),true));
check(count($selection->snapshot($company,$request($visibleId),true)['orders'])===1,'Görünür PDF/Excel/WhatsApp seçimi çalışır');
hidden(fn()=>$webDocs->get($company,$hiddenArchive['id']));
check(count($webDocs->get($company,$mixed['id'])['orders'])===1,'Karma arşivin görünümü süzülür');
check(count($docs->get($company,$mixed['id'])['orders'])===2,'Asıl arşiv değişmez');
$archive=$webDocs->archive($company,0);check(count($archive['items'])===1&&$archive['items'][0]['orderCount']===1,'Arşiv sayısı görünür siparişleri sayar');
check(count((new PaymentCalendar($db))->report($admin,$company,'2026-10-01','2026-10-31')['items'])===7,'Hakediş takvimi süzülür');
check(count((new ConnectionHealth($db))->report($admin)['items'])===14,'İki firmanın yedişer görünür bağlantısı');
foreach(['n11','pttavm','trendyol','teknosa'] as $provider)for($n=0;$n<32;$n++)$db->run('INSERT INTO order_notifications(company_id,provider,order_key,created) VALUES(?,?,?,?)',[$company,$provider,hash('sha256',$provider.$n),gmdate('c')]);
$feed=new NotificationFeed($db,$cipher);$page=$feed->listing($admin['id'],$company,0);
check($page['counts']['all']===96&&count($page['items'])===30&&$page['hasMore'],'Bildirim sayısı ve sayfalama gizli olayları içermemeli');
check(count($feed->listing($admin['id'],$company,3)['items'])===6,'Görünür bildirimlerin son sayfası');
(new Omnigo\NotificationState($db))->bulk($admin['id'],$company,[...$page['through'],'order'=>999999],false);
check((int)$db->one('SELECT COUNT(*) AS n FROM notification_states')['n']===96,'Eski sekmedeki toplu işlem bile gizlenen bildirimlere dokunmamalı');
check($before===$db->all('SELECT * FROM records')&&$ordersBefore===$db->all('SELECT * FROM delivery_orders')&&$documentsBefore===$db->all('SELECT * FROM delivery_documents'),'Gizleme hiçbir kaynak kaydı değiştirmemeli');
echo "Görünürlük: iki panel, özetler, sayfalama, şehir sayıları, kuyruklar, dışa aktarma, arşiv ve bildirimler geçti; tüm kaynak kayıtlar korundu.\n";

