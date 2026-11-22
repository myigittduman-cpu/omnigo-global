<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliveryQueue,OrderShipping,ShipmentOrderMapper,ShipmentOrderIngestor,DeliveryMapper,IdefixOrderMapper,DeliveryFulfillment};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('q',32));$repo=new DeliveryRepository($db,$cipher);$queue=new DeliveryQueue($db,$repo);$now=strtotime('2026-10-12T12:00:00+03:00');
$sequence=0;
$insert=function(array $states,int $age=432001,int $company=1,string $provider='n11',bool $active=true,?string $date=null)use($db,$cipher,$now,&$sequence){
 $id=(string)++$sequence;$lines=[];foreach($states as $index=>$status)$lines[]=['lineId'=>'L'.$index,'title'=>'Test ürün','sku'=>'SKU','quantity'=>1,'status'=>$status];
 $value=['provider'=>$provider,'externalId'=>$id,'orderNumber'=>$id,'active'=>$active,'createdAt'=>$date??gmdate('c',$now-$age),'city'=>'Ankara','buyerName'=>'Test','phone'=>'05','address'=>'Test','status'=>'Sevkiyat bekliyor','products'=>$lines,'issues'=>[],'details'=>['products'=>$lines]];
$db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$provider,$id,'Ankara',$active?1:-1,$cipher->seal($company,'order:'.$provider.':'.$id,$value),'hash',gmdate('c')]);$deliveryId=(int)$db->insertId();
 foreach($lines as $line){
  $actual=$active?match($line['status']){'Shipped'=>'shipped','Delivered'=>'delivered','Cancelled'=>'cancelled',default=>''}:'cancelled';
  $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,source_key,row_status,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',[$company,'Ankara',$provider,'2026-10','',DeliveryFulfillment::key($value,$line),$actual,gmdate('c'),gmdate('c')]);
  $entryId=(int)$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$entryId,['marketplaceShipping'=>OrderShipping::state($provider,$line['status'])]),$entryId]);
 }
 return $deliveryId;
};
$late=$insert(['Picking']);$boundary=$insert(['Picking'],432000);$insert(['Picking'],431999);
$mixed=$insert(['Picking','Shipped','Cancelled','Delivered']);$insert(['Picking'],432001,2);$insert(['Shipped'],432001,1,'n11',false);$insert(['Cancelled']);$insert(['Delivered']);$insert(['Unknown']);$insert(['Picking'],432001,1,'n11',true,'2026-02-31T12:00:00');
$local=$insert(['Picking'],0,1,'n11',true,'2026-10-07 11:59:59');
// Multiple non-matching pages must not hide older matching orders.
for($i=0;$i<510;$i++)$insert(['Shipped'],60);
$result=$queue->listing(1,'all','all','delayed',0,50,$now);
check(array_column($result['items'],'id')===[$local,$mixed,$late],'120 saat, yerel saat, firma sınırı, iptal/teslim ve sayfalama öncesi filtre');
check(count($result['items'][1]['queueProducts'])===1,'Karma siparişte yalnızca geciken ürün');
$first=$queue->listing(1,'all','all','shipped',0,250,$now);$second=$queue->listing(1,'all','all','shipped',1,250,$now);$last=$queue->listing(1,'all','all','shipped',2,250,$now);
check(count($first['items'])===250&&$first['hasMore']&&count($second['items'])===250&&count($last['items'])===11&&!$last['hasMore'],'Kargo listesi doğru sayfalanır');
$counts=$queue->counts(1,'all',false,$now);check($counts['shipped']===511&&$counts['delayed']===3&&$counts['cancelled']===0,'Sayaçlar tüm sayfaları sayar; şirket ve süre sınırları liste ile aynıdır');
check($queue->counts(1,'Koçtaş',false,$now)===['delayed'=>0,'shipped'=>0,'cancelled'=>0,'review'=>0],'Boş pazaryeri sayaçları sıfır');
check($queue->listing(1,'all','Koçtaş','shipped',0,50,$now)['items']===[],'Pazaryeri filtresi');
check(OrderShipping::state('Koçtaş','SHIPPING')==='pending'&&OrderShipping::state('Koçtaş','SHIPPED')==='shipped','Hazırlık ile kargoya teslim farklıdır');
check(OrderShipping::state('Pazarama',5)==='shipped'&&OrderShipping::state('Pazarama',12)==='pending'&&OrderShipping::state('idefix','shipment_in_cargo')==='shipped','Pazarama ve idefix kargo durumu');
foreach([5,16,19] as $status){
 $raw=['items'=>[['orderItemStatus'=>$status,'quantity'=>1,'product'=>['name'=>'Test']]]];
 check(DeliveryMapper::pazarama($raw)['deliveryStatus']==='Yolda'&&OrderShipping::line('Pazarama',$raw['items'][0])==='shipped','Mevcut Pazarama yolda grubu iki menüde tutarlı');
}
// Repeated source changes and actual status transitions are covered by physical-fulfillment.php.
$reviewIds=[];
for($i=0;$i<23;$i++){
 $reviewIds[]=$insert(['Picking'],60);
 $key=DeliveryFulfillment::key(['provider'=>'n11','orderNumber'=>(string)$sequence],['lineId'=>'L0']);
 $db->run("UPDATE shipment_entries SET row_status='review' WHERE company_id=1 AND source_key=?",[$key]);
}
$pages=[];
for($page=0;$page<3;$page++)$pages[]=$repo->listing(1,'all','n11','review',$page,10);
check(array_map(fn($page)=>count($page['items']),$pages)===[0,0,0],'Kaldırılan teyit süreci kaynak siparişlerini engellemez');
foreach($pages as $page)check($page['queues']['review']===0,'Eski teyit işaretleri yeni teyit kuyruğu oluşturmaz');
check(count($repo->listing(1,'all','n11','shipped',0,20)['items'])===20,'Diğer kuyruklarda 20 kayıt korunur');
echo "Teslimat kuyrukları: fiziksel durum, 120 saat sınırı, firma/pazaryeri, karma satırlar ve tüm sayfalarda doğru sayaçlar geçti.\n";
