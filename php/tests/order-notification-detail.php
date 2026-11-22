<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,RecentOrderSchema,DeliverySchema,OrderNotifications,OrderNotificationOrder,MobileNotificationPresentation,MobilePushMessage,DeliveryCipher,HttpError};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);RecentOrderSchema::install($db);DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Vizyona Beyaz Eşya'),(2,'Diğer Firma')");
$cipher=DeliveryCipher::withKey(str_repeat('x',32));$resolver=new OrderNotificationOrder($db,$cipher);$alerts=new OrderNotifications($db);$admin=['role'=>'admin'];
foreach(['hepsiburada'=>'1234','n11'=>'N1234','pazarama'=>'uuid-order-id','koctas'=>'K1234','idefix'=>'I1234','pttavm'=>'P1234'] as $provider=>$external){
 $name=Omnigo\OrderList::PROVIDERS[$provider];$number=$provider==='pazarama'?'445566':$external;
 $order=['provider'=>$name,'orderNumber'=>$number,'externalId'=>$external.(in_array($provider,['hepsiburada','n11','idefix'],true)?':package':''),'createdAt'=>gmdate('c')];
 $alerts->observe(1,$order);$id=$db->insertId();
 $value=['externalId'=>$external,'createdAt'=>gmdate('c'),'grossCents'=>1234567,'cancelCents'=>0,'returnCents'=>0,'products'=>[]];
 $db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[1,$provider,$external,json_encode($value),gmdate('c')]);
 $payload=['provider'=>$name,'orderNumber'=>$number,'externalId'=>$order['externalId'],'buyerName'=>'PRIVATE_BUYER','phone'=>'PRIVATE_PHONE','address'=>'PRIVATE_ADDRESS','city'=>'Ankara','district'=>'Çankaya','details'=>['recipientName'=>'PRIVATE_RECIPIENT']];
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[1,$name,$order['externalId'],'Ankara',1,$cipher->seal(1,'order:'.$name.':'.$order['externalId'],$payload),'hash',gmdate('c')]);
 $event=[...$db->one('SELECT * FROM order_notifications WHERE id=?',[$id]),'company'=>'Vizyona Beyaz Eşya'];
 $enriched=$resolver->enrich($event);check($enriched['amountCents']===1234567,'Tutar şirket ve pazaryeriyle eşleşmeli: '.$provider);
 $message=MobilePushMessage::create([],$enriched,'tag');
 check(str_contains($message['body'],'12.345,67₺ tutarında yeni bir siparişiniz var.'),'Sipariş tutarı Türkçe biçimde görünmeli.');
 check(str_starts_with($message['body'],'Vizyona; '),'Yalnız bildirimde kısa firma adı kullanılmalı.');
 check($message['url']==='/?view=orders&orderCompany=1&noticeType=order&noticeId='.$id,'Bildirim ilgili siparişe yönlenmeli.');
 check(!str_contains(json_encode($message),'PRIVATE_'),'Alıcı bilgileri kilit ekranına çıkmamalı.');
 $detail=$resolver->detail($admin,1,$id);$package=$detail['packages'][0];
 check($package['recipientName']==='PRIVATE_RECIPIENT','Tıklama teslim alacak kişiyi göstermeli.');
 if($provider==='pttavm')check($package['customerName']===''&&$package['customerNameUnavailable']===true,'PTT alıcısını doğrulanmış müşteri adı gibi sunmamalı.');
 else check($package['customerName']==='PRIVATE_BUYER','Tıklama doğrulanan müşteri adını açmalı.');
 foreach([[['role'=>'client'],1,403],[$admin,2,404]] as [$user,$company,$status]){
  try{$resolver->detail($user,$company,$id);throw new RuntimeException('Yetki hatası bekleniyor.');}catch(HttpError $e){check($e->getCode()===$status,'Firma/rol kapsamı korunmalı.');}
 }
}
$items=$alerts->arrivals(0)['items'];check(count($items)===6,'Tüm pazaryerleri aynı sunumu kullanmalı.');foreach($items as $item)check($item['amountCents']===1234567&&$item['noticeId']===$item['id']&&!str_contains(json_encode($item),'PRIVATE_'),'Masaüstü akışı tutar ve güvenli yönlendirmeyi taşımalı.');
$cancel=['id'=>90,'company_id'=>1,'company'=>'Vizyona Beyaz Eşya','event_type'=>'operation','payload'=>'{"events":["cancelled"]}'];
check(MobileNotificationPresentation::create($cancel,'admin')['body']==='Vizyona; Sipariş iptal edildi.','İptal metni kısa olmalı.');
check(MobilePushMessage::create([],$cancel,'tag')['url']==='/?view=orders&orderCompany=1&noticeType=operation&noticeId=90','İptal kendi işlem detayına gitmeli.');
$request=[...$cancel,'payload'=>'{"events":["cancellation_requested"]}'];check(str_contains(MobileNotificationPresentation::create($request,'admin')['body'],'iptal talebi'),'İptal talebi kesin iptal olarak sunulmamalı.');
$legacy=$db->one("SELECT * FROM order_notifications WHERE provider='pazarama' AND company_id=1");$db->run('UPDATE order_notifications SET payload=NULL WHERE id=?',[$legacy['id']]);$legacy['payload']=null;
check($resolver->enrich($legacy)['amountCents']===1234567,'Eski Pazarama sipariş numarası UUID ile eşleşmeli.');
check($resolver->detail($admin,1,(int)$legacy['id'])['packages'][0]['customerName']==='PRIVATE_BUYER','Eski Pazarama bildirimi doğru alıcı ayrıntısını açmalı.');
echo "Altı pazaryeri: tutar, alıcı ayrıntısı, firma/rol yalıtımı, iptal ve talep ayrımı doğrulandı.\n";
