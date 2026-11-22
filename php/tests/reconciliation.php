<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliverySchema,ShipmentSchema,NotificationSchema,ReconciliationReference,ReconciliationCompare,ReconciliationMonitor,OperationalMailEvents,OperationalNotifications,MailQueue,MobilePushDevices,MobilePushMessage,ConnectionHealth,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Firma'),(2,'Başka Firma')");$db->run("INSERT INTO users VALUES(1,'admin@omnigoglobal.com','Yönetici','hash','admin',NULL),(2,'client@example.test','Müşteri','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));$now=time();$date=gmdate('c',$now-120);$month=(new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m');
$save=function(string $kind,array $value,int $company=1)use($db){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($value),gmdate('c')]);return $db->insertId();};
$order=fn($id,$amount)=>['externalId'=>$id,'month'=>$month,'createdAt'=>$date,'grossCents'=>$amount,'cancelCents'=>0,'returnCents'=>0,'orders'=>1];
$orders=[$order('TEST-A',10000),$order('TEST-B',20000)];$state=[];
ReconciliationReference::page($state,[],true);ReconciliationReference::complete($state,$orders,gmdate('c',$now-3600),gmdate('c',$now));
$reference=$state['reconciliationReference'];$state['lastSuccessAt']=gmdate('c');
$stateId=$save('sync_n11',$state);$a=$save('order_n11',$orders[0]);$b=$save('order_n11',$orders[1]);
$sale=$save('sales',['channel'=>'n11','month'=>$month,'grossCents'=>30000,'cancelCents'=>0,'returnCents'=>0,'orders'=>2]);
$save('channels',['name'=>'n11','status'=>'active']);$save('order_n11',$order('OTHER',9999),2);
$db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(1,'n11','pending',?,0)",[$now]);
$compare=new ReconciliationCompare($db,$cipher);$monitor=new ReconciliationMonitor($db,$cipher);
check($monitor->check(1,'n11',false)['total']===0,'Temiz kaynak ve firma izolasyonu');
check($db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0,'Salt okunur kontrol bildirim üretmez');
$monitor->check(1,'n11');
$db->run('DELETE FROM records WHERE id=?',[$b]);$result=$monitor->check(1,'n11',true,$now+46);
check($result['counts']['missing']===1,'Eksik sipariş algılandı');
$monitor->check(1,'n11',true,$now+92);
check($db->one('SELECT COUNT(*) n FROM order_notifications')['n']===1,'Aynı fark tekrar bildirilmedi');
$b=$save('order_n11',$orders[1]);$dup=$save('order_n11',$orders[0]);$bad=[...$orders[0],'grossCents'=>10001,'cancelCents'=>10,'returnCents'=>20];$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($bad),$a]);
$result=$monitor->check(1,'n11',true,$now+138);
check($result['counts']['duplicate']===1&&$result['counts']['amount']===1,'Mükerrer ve kuruş düzeyinde fark');
$same=$save('order_n11',$bad);$db->run('DELETE FROM records WHERE id=?',[$a]);$a=$same;
$result2=$monitor->check(1,'n11',true,$now+184);
check($result['fingerprint']===$result2['fingerprint']&&$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===2,'Değişen yerel ID aynı hatayı tekrar bildirmez');
$db->run('DELETE FROM records WHERE id=?',[$dup]);$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($orders[0]),$a]);
$db->run("UPDATE jobs SET state='running'");check($monitor->check(1,'n11',true,$now+230)===null,'Aktarım sırasında yanlış düzelme yok');$db->run("UPDATE jobs SET state='pending'");
$monitor->check(1,'n11',true,$now+230);check($db->one('SELECT COUNT(*) n FROM order_notifications')['n']===3,'Tek doğrulanmış düzelme');
$event=$db->one('SELECT * FROM order_notifications ORDER BY id LIMIT 1');$summary=json_decode($event['payload'],true);check($summary['audience']==='admin','Yönetici hedefi açık');
$mobile=new MobilePushDevices($db,$cipher);check(!$mobile->recipient(['user_id'=>2],$event)&&$mobile->recipient(['user_id'=>1],$event),'Müşteri cihazına mutabakat gönderilmez');
check(!$mobile->recipient(['user_id'=>2],[...$event,'payload'=>'{}','event_type'=>'order']),'Müşteri yalnız destek çözümü bildirimi almalı.');
$message=MobilePushMessage::create(['sound'=>true],$event,'test');check($message['title']==='Omnigo Global Yönetici Portalı'&&str_contains($message['body'],'mutabakat')&&str_contains($message['url'],'connections')&&!str_contains(json_encode($message),'TEST-A'),'Cihaz bildirimi özel verileri taşımaz');
$detail=(new OperationalNotifications($db,$cipher))->detail(1,(int)$event['id']);check($detail['reconciliation']['items'][0]['reference']==='TEST-B','Eksik kaydın referansı yöneticide görünür');
try{(new OperationalNotifications($db,$cipher))->detail(2,(int)$event['id']);throw new RuntimeException('Firma sızıntısı');}catch(HttpError $e){check($e->getCode()===404,'Detay firma sınırı');}
try{(new ConnectionHealth($db))->report(['role'=>'client']);throw new RuntimeException('Yetki sızıntısı');}catch(HttpError $e){check($e->getCode()===403,'Müşteri bağlantı/mutabakat ekranına erişemez');}
$mail=new MailQueue($db,['mail'=>['enabled'=>true,'from'=>'test@example.test','transport'=>'mail','key'=>base64_encode(str_repeat('m',32))]]);
(new OperationalMailEvents($db,$cipher))->enqueue($mail);$messages=[];while($mail->runOne(function($to,$subject,$body)use(&$messages){$messages[]=[$to,$subject,$body];return true;})){}
check($messages===[],'Mutabakat bildirimleri yalnız panelde kalır, e-posta gönderilmez');

// Shipment coverage: manual rows, removed entries, locked fields, old dates and non-service cities.
$save('shipment_auto_since',['since'=>$now-3600]);
$item=['key'=>'line-A','payload'=>['province'=>'Ankara','orderedAt'=>$date,'invoiceCents'=>10000,'quantity'=>1]];
$delivery=['externalId'=>'TEST-A:package','orderNumber'=>'TEST-A','provider'=>'n11','active'=>true,'shipmentItems'=>[$item]];
$stage=[];ReconciliationReference::page($stage,[$delivery],true);ReconciliationReference::complete($stage,$orders,gmdate('c',$now-3600),gmdate('c',$now));$ref=$stage['reconciliationReference'];
$result=$compare->inspect(1,'n11',$ref);check($result['counts']['missing']===2,'Teslimat ve sevkiyat eksikleri');
$db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[1,'n11',$delivery['externalId'],'Ankara',1,'unused','hash',gmdate('c')]);
$db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated,source_key) VALUES(?,?,?,?,?,?,?,?)',[1,'Ankara','n11',$month,'',gmdate('c'),gmdate('c'),ReconciliationReference::key('n11:line-A')]);$shipment=$db->insertId();
$payload=[...$item['payload'],'invoiceCents'=>500,'source'=>['locked'=>['invoiceCents']]];$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$shipment,$payload),$shipment]);
check($compare->inspect(1,'n11',$ref)['total']===0,'Elle değiştirilen tutar hata sayılmaz');
$payload['source']['locked']=[];$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$shipment,$payload),$shipment]);check($compare->inspect(1,'n11',$ref)['counts']['amount']===1,'Sevkiyat tutar farkı');
$db->run('UPDATE shipment_entries SET removed_at=? WHERE id=?',[gmdate('c'),$shipment]);check($compare->inspect(1,'n11',$ref)['total']===0,'Bilinçli kaldırılmış satır korunur');
foreach(['city'=>'İzmir','orderedAt'=>gmdate('c',$now-7200),'cancelled'=>true] as $field=>$value){$skip=$ref;$skip['shipments'][ReconciliationReference::key('n11:line-A')][$field]=$value;$db->run('DELETE FROM shipment_entries WHERE id=?',[$shipment]);check($compare->inspect(1,'n11',$skip)['total']===0,'Sevkiyat kapsamı korunur: '.$field);}
$ref['deliveries'][ReconciliationReference::key($delivery['externalId'])]['active']=false;$ref['shipments']=[];$db->run('DELETE FROM delivery_orders');check($compare->inspect(1,'n11',$ref)['total']===0,'İptal edilen paket evrakta aranmaz');
$stale=$state;$stale['reconciliationReference']['capturedAt']=gmdate('c',$now-3600);$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($stale),$stateId]);check($monitor->check(1,'n11')===null,'Eski kaynak sağlıklı sayılmaz');

// Incremental source reference remains independent of local records.
$incremental=[];ReconciliationReference::incremental($incremental,[$orders[0]],[],true,true,false,$date,gmdate('c'));
check(!isset($incremental['reconciliationReference']),'Yarım tarama yayımlanmaz');
ReconciliationReference::incremental($incremental,[$orders[1]],[],true,false,true,$date,gmdate('c'));
ReconciliationReference::incremental($incremental,[[...$orders[0],'returnCents'=>10000]],[],false,true,true,$date,gmdate('c'));
check(count($incremental['reconciliationReference']['orders'])===2&&$incremental['reconciliationReference']['orders'][ReconciliationReference::key('TEST-A')]['money'][2]===10000,'Artımlı kaynak iade güncellemesi');
echo "Sipariş mutabakatı: eksik/mükerrer/tutar, kuruş hassasiyeti, tekrarsız hata/düzelme, salt okunur kontrol, firma ve yönetici sınırı, e-posta, mobil filtre, sevkiyat kapsamı ve kısmi tarama testleri geçti.\n";
