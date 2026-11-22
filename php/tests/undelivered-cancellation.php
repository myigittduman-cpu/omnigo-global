<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliveryQueue,DeliveryFulfillment,ShipmentFulfillment};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('u',32));$repo=new DeliveryRepository($db,$cipher);
foreach(['PTT AVM','Pazarama','Hepsiburada','n11','Koçtaş','idefix'] as $provider){
 $products=[['lineId'=>'L1','title'=>'Teslim edilemeyen ürün','sku'=>'A','quantity'=>2,'cancelledQuantity'=>0,'shippingState'=>'undelivered'],['lineId'=>'L2','title'=>'Bekleyen ürün','sku'=>'B','quantity'=>1,'shippingState'=>'pending']];
 $raw=['provider'=>$provider,'externalId'=>'ONE','orderNumber'=>'ONE','createdAt'=>gmdate('c'),'city'=>'Ankara','district'=>'Çankaya','buyerName'=>'Test','phone'=>'05000000000','address'=>'Test adresi','status'=>'Teslim edilemedi','active'=>true,'products'=>$products,'details'=>['recipientName'=>'Alıcı','products'=>$products],'cancellation'=>['quantity'=>3,'cancelledQuantity'=>0,'partial'=>false],'grossCents'=>30000,'cancelCents'=>0,'issues'=>[]];
 $sealed=$cipher->seal(1,'order:'.$provider.':ONE',$raw);
 $summary=Omnigo\OperationalCancellation::summary([$raw]);
 check($summary['cancelledQuantity']===2&&$summary['quantity']===3&&$summary['partial']&&$raw['cancelCents']===0,$provider.' sipariş tablosu da ayrı operasyonel iptal bilgisi alır');
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[1,$provider,'ONE','Ankara',1,$sealed,'hash',gmdate('c')]);
 $payload=['marketplaceShipping'=>'undelivered','invoiceCents'=>20000,'manualStatusOverride'=>['enabled'=>true]];
 $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,source_key,row_status,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[1,'Ankara',$provider,date('Y-m'),'',DeliveryFulfillment::key($raw,$products[0]),'shipped',gmdate('c'),gmdate('c')]);
 $id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$payload),$id]);
 $view=ShipmentFulfillment::presentation(['source_key'=>'source','row_status'=>'shipped'],$payload);
 check($view['displayRowStatus']==='cancelled'&&$view['marketplaceStatus']==='undelivered'&&!$view['manualStatusOverride'],$provider.' kaynak iptali elle sevke dönmez; kırmızı ve özgün durum korunur');
 $active=$repo->listing(1,'all',$provider,'all',0)['items'];
 check(count($active)===1&&count($active[0]['products'])===1&&$active[0]['products'][0]['sku']==='B',$provider.' yalnız teslim edilemeyen kalem evraktan çıkar');
 check($active[0]['marketplaceStatusText']==='Sevk bekliyor'&&$active[0]['fulfillmentStatus']==='pending',$provider.' bekleyen kalem teslim edilemedi olarak etiketlenmez');
 $queue=(new DeliveryQueue($db,$repo))->listing(1,'all',$provider,'cancelled',0,50);
 check(count($queue['items'])===1&&$queue['items'][0]['queueProducts'][0]['quantity']===2,$provider.' iptal listesi doğru ürün/adedi gösterir');
 check($queue['items'][0]['operationalCancellation']['partial']&&$queue['items'][0]['fulfillmentStatus']==='cancelled',$provider.' başarısız kalem yöneticiye kısmi iptal olarak gösterilir');
 check((new DeliveryQueue($db,$repo))->counts(1,$provider)['cancelled']===1,$provider.' liste ve sayaç eşleşir');
 $sourceRow=$db->one('SELECT * FROM delivery_orders WHERE provider=?',[$provider]);$stored=$repo->decode($sourceRow);
 check($sourceRow['payload']===$sealed&&$stored['cancellation']['cancelledQuantity']===0&&$stored['grossCents']===30000,$provider.' kaynak ve mali tutarlar değişmez');
 check(!array_intersect(['cancellation','operationalCancellation','grossCents'],array_keys($active[0])),$provider.' teslimat satırı iptal ve mali alanları döndürmez');
}
check($repo->listing(2,'all','all','all',0)['items']===[],'Müşteri izolasyonu korunur');
echo "Altı pazaryeri: teslim edilemedi kırmızı/iptal, ürün bazlı evrak dışlama, sayaç, kaynak/mali kayıt ve müşteri izolasyonu geçti.\n";
