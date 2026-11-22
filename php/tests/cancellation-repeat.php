<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliveryMapper,OrderDetailMapper,N11DeliveryIdentity};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Firma'),(2,'Diğer Firma')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));$repo=new DeliveryRepository($db,$cipher);
$raw=['orderNumber'=>'REPEAT','id'=>null,'customerfullName'=>'Örnek','shippingAddress'=>['gsm'=>'05000000001','address'=>'Adres','city'=>'Ankara'],'packageHistories'=>[['status'=>'Created','createdDate'=>(time()-86400)*1000]],'shipmentPackageStatus'=>'Picking','lines'=>[['orderLineId'=>101,'orderItemLineItemStatusName'=>'Picking','productName'=>'Ürün A','quantity'=>1],['orderLineId'=>102,'orderItemLineItemStatusName'=>'Picking','productName'=>'Ürün B','quantity'=>1]]];
$map=static function(array $raw):array{$v=DeliveryMapper::n11($raw);return [...$v,'details'=>OrderDetailMapper::n11($raw,$v)];};
$save=fn(array $raw,int $company=1)=>$db->transaction(fn()=>$repo->ingest($company,$map($raw)));
$cancelEvents=static function()use($db,$cipher):array{$events=[];foreach($db->all("SELECT * FROM audit WHERE action='operations.mail'") as $row){$v=$cipher->open((int)$row['company_id'],'operation-mail:'.$row['id'],$row['after_value']);if(in_array('cancelled',$v['events'],true))$events[]=$v;}return $events;};
// Exact production sequence: no package ID, two lines followed by only the second line.
$legacy=$map($raw);$legacy['externalId']='REPEAT:local';$db->transaction(fn()=>$repo->ingest(1,$legacy));
$original=$db->one('SELECT id FROM delivery_orders');$save($raw);
check((int)$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===1,'Eski kayıt çoğalmadan taşınır');
check((int)$db->one('SELECT id FROM delivery_orders')['id']===(int)$original['id'],'Satır kimliği korunur');
$both=$raw;$both['shipmentPackageStatus']='Cancelled';$both['lines'][0]['orderItemLineItemStatusName']='Cancelled';
$single=$both;$single['lines']=[$both['lines'][1]];$single['lines'][0]['orderItemLineItemStatusName']='Cancelled';
$save($both);$save($single);
check(count($cancelEvents())===1&&$cancelEvents()[0]['cancelledQuantity']===2,'Paketler arasında aynı ürün iptali tekrar bildirilmez');
for($i=0;$i<50;$i++){$save($both);$save($single);}
check(count($cancelEvents())===1,'50 tam tarama tek iptal bildirimi üretir');
check((int)$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===2,'İki paket ayrı tutulur');
check($repo->listing(1,'Ankara','n11','all',0)['items']===[],'İptaller teslimat listesine dönmez');
$reversed=$both;$reversed['lines']=array_reverse($reversed['lines']);
check(N11DeliveryIdentity::externalId($both)===N11DeliveryIdentity::externalId($reversed),'Satır sırası paket kimliğini değiştirmez');
// A late incomplete snapshot cannot make a previously observed line a new cancellation.
try{$save($raw);throw new RuntimeException('Eski aktif durum reddedilmeli');}catch(\Omnigo\OrderSnapshotConflict){}$save($both);check(count($cancelEvents())===1,'Eski aktif görüntüden sonra iptal tekrarlanmaz');
$save($raw,2);$save($both,2);check(count($cancelEvents())===2,'Firmaların iptalleri birbirini engellemez');
// A package can lose its ID when cancelled: still notify if that order already existed.
$next=$raw;$next['orderNumber']='NEW-CANCEL';$next['id']=700;$save($next);
$next['id']=null;$next['shipmentPackageStatus']='Cancelled';$save($next);
check(count($cancelEvents())===3,'Kimliği boşalan gerçek yeni iptal kaybolmaz');
check($repo->listing(1,'Ankara','n11','all',0)['items']===[],'Paket kimliği boşalınca eski aktif teslimat satırı kalmaz');
// Increasing cancelled quantities remain distinct events; old snapshots never lower history.
$value=$map($raw);$value['provider']='Koçtaş';$value['externalId']='PARTIAL';$value['orderNumber']='PARTIAL';
$value['cancellation']['lines']=['L'=>['quantity'=>3,'cancelledQuantity'=>0,'partial'=>false]];
$store=fn($v)=>$db->transaction(fn()=>$repo->ingest(1,$v));$store($value);
foreach([1,0,1,2,2,1,3] as $quantity){$value['cancellation']['lines']['L']['cancelledQuantity']=$quantity;try{$store($value);}catch(\Omnigo\OrderSnapshotConflict){} }
$quantities=array_column(array_slice($cancelEvents(),3),'cancelledQuantity');
check($quantities===[1,1,1],'Yalnızca yeni iptal edilen adetler bildirilir');
echo "İptal tekrarı: 50 tarama, boş paket kimliği, eski kayıt taşıma, firma ayrımı ve yeni iptal artışları geçti.\n";

