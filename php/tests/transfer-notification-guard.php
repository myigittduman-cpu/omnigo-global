<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliverySchema,NotificationSchema,OperationalMailEvents,OperationalNotifications};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");$cipher=DeliveryCipher::withKey(str_repeat('d',32));$events=new OperationalMailEvents($db,$cipher);
function delivery(Database $db,DeliveryCipher $cipher,int $company,string $external):int{
 $order=['buyerName'=>'Aynı Müşteri','details'=>['products'=>[['lineId'=>'one','title'=>'Ürün','quantity'=>2,'amountCents'=>45678]]]];
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,'PTT AVM',$external,'Ankara',1,$cipher->seal($company,'order:PTT AVM:'.$external,$order),'hash',gmdate('c')]);return $db->insertId();
}
$record=delivery($db,$cipher,1,'SAME-ORDER');$detail=['events'=>['delivery_added'],'provider'=>'PTT AVM','orderNumber'=>'SAME-ORDER'];
for($i=0;$i<3;$i++)$db->transaction(fn()=>$events->record(1,$record,$detail));
check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='operations.mail'")['n']===1,'Aktif/pasif tekrarları tek aktarım olayı olmalı');
check((int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===1,'Zil bildirimi tek olmalı');
$db->transaction(fn()=>$events->record(1,$record,[...$detail,'events'=>['delivery_added','cancelled'],'cancelledQuantity'=>1]));
check((int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===2,'Gerçek iptal engellenmemeli');
$latest=$db->one("SELECT id FROM order_notifications WHERE event_type='operation' ORDER BY id DESC LIMIT 1");
$shown=(new OperationalNotifications($db,$cipher))->detail(1,(int)$latest['id']);check($shown['products'][0]['title']==='Ürün'&&$shown['products'][0]['quantity']===2&&$shown['products'][0]['invoiceCents']===45678,'Ürün, adet ve tutar doğru olmalı');
foreach([[1,'OTHER-ORDER'],[2,'SAME-ORDER']] as [$company,$external]){$id=delivery($db,$cipher,$company,$external);$db->transaction(fn()=>$events->record($company,$id,[...$detail,'orderNumber'=>$external]));}
check((int)$db->one("SELECT COUNT(*) n FROM order_notifications WHERE event_type='operation'")['n']===4,'Aynı isimdeki farklı sipariş ve mağazalar ayrılmalı');
// Seed a pre-release audit: its new-order envelope also proves the destination was already entered.
$legacy=delivery($db,$cipher,1,'LEGACY');$db->run("INSERT INTO audit(company_id,action,record_id,after_value,created) VALUES(1,'operations.mail',?,'null',?)",[$legacy,gmdate('c')]);$audit=$db->insertId();$db->run('UPDATE audit SET after_value=? WHERE id=?',[$cipher->seal(1,'operation-mail:'.$audit,['events'=>['new_order','delivery_added']]),$audit]);
$db->transaction(fn()=>$events->record(1,$legacy,[...$detail,'orderNumber'=>'LEGACY']));check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE record_id=? AND action='operations.mail'",[$legacy])['n']===1,'Eski kaydın tekrar bildirimi çıkmamalı');
$rollback=delivery($db,$cipher,1,'ROLLBACK');try{$db->transaction(function()use($events,$rollback,$detail){$events->record(1,$rollback,$detail);throw new RuntimeException('rollback');});}catch(RuntimeException){}
$db->transaction(fn()=>$events->record(1,$rollback,$detail));check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE record_id=? AND action='operations.mail'",[$rollback])['n']===1,'Rollback bildirim hakkını tüketmemeli');
echo "Aktarım tekilleştirme, eski kayıt, gerçek iptal, firma/sipariş ayrımı ve rollback geçti.\n";
