<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,DeliveryMapper,OrderDetailMapper,ShipmentOrderMapper,ShipmentOrderIngestor,N11CancelledProjection,OrderCancellation,DeliveryRoutes,Security};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function projected(array $orders):array{$p=new N11CancelledProjection();foreach($orders as $order)$p->add($order);return $p->result();}
function sample(int $id,string $number,array $lines,string $provider='n11'):array{
 $products=[];$detail=[];
 foreach($lines as $lineId=>$line){$detail[]=['lineId'=>(string)$lineId,'title'=>'Aynı ürün adı','sku'=>'AYNI-SKU',...$line];if($line['cancelledQuantity'])$products[]=['lineId'=>(string)$lineId,'title'=>'Aynı ürün adı','sku'=>'AYNI-SKU','quantity'=>$line['cancelledQuantity']];}
 return ['id'=>$id,'provider'=>$provider,'orderNumber'=>$number,'cancellation'=>[...OrderCancellation::combine($lines),'lines'=>$lines],'cancelledProducts'=>$products,'details'=>['products'=>$detail]];
}
$one=['quantity'=>1,'cancelledQuantity'=>1];
$a=sample(1,'TEST',[11=>$one,12=>$one]);$b=sample(2,'TEST',[12=>$one,13=>$one]);$c=sample(3,'TEST',[13=>$one,14=>$one]);
foreach([[$a,$b,$c],[$c,$a,$b],[$b,$c,$a]] as $permutation){
 $p=projected($permutation);check(!$p[1]&&!$p[2]&&count($p[3]['products'])===4,'Zincirleme örtüşme ve geliş sırası: dört farklı satır korunmalı');
 check($p[3]['cancellation']['cancelledQuantity']===4&&!$p[3]['cancellation']['partial'],'Tekrarlı miktarlar toplanmamalı');
}
check(projected([sample(1,'SAME',[1=>$one]),sample(2,'SAME',[2=>$one])])===[],'Aynı ad/SKU taşıyan farklı ürün satırları birleşmemeli');
check(projected([sample(1,'FIRST',[1=>$one]),sample(2,'SECOND',[1=>$one])])===[],'Aynı satır kimliği farklı siparişlerde birleşmemeli');
check(projected([sample(1,'SAME',[1=>$one],'Pazarama'),sample(2,'SAME',[1=>$one],'Pazarama')])===[],'Diğer pazaryerlerine dokunulmamalı');
$unknown=$a;unset($unknown['cancelledProducts'][0]['lineId']);check(projected([$unknown,$b])===[],'Kimliği bilinmeyen ürünlerde tahmini tekilleştirme yapılmamalı');
$old=$a;$old['details']['superseded']=true;check(projected([$old,$b])===[],'Geçersiz eski paket yeniden gösterilmemeli');
$partial=projected([sample(1,'PART',[1=>['quantity'=>5,'cancelledQuantity'=>1],2=>['quantity'=>1,'cancelledQuantity'=>0]]),sample(2,'PART',[1=>['quantity'=>5,'cancelledQuantity'=>3]])]);
check($partial[2]['products'][0]['quantity']===3&&$partial[2]['cancellation']['quantity']===6&&$partial[2]['cancellation']['partial'],'Kısmi iptal son doğrulanan miktarı korumalı; kalan aktif miktar kaybolmamalı');
$inside=$a;$inside['cancelledProducts'][]=$inside['cancelledProducts'][0];check(count(projected([$inside])[1]['products'])===2,'Tek pakette aynı ürün satırı da tekrarlanmamalı');

// Actual null-ID API packages through the same mappers, encryption and importer as production.
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$fixtureNow=(int)($argv[2]??time());
$cipher=DeliveryCipher::withKey(str_repeat('z',32));$repo=new DeliveryRepository($db,$cipher);ShipmentOrderIngestor::activate($db,1,$fixtureNow-86400);
$line=fn(int $id,string $status)=>['orderLineId'=>$id,'productName'=>'Örnek ürün '.$id,'quantity'=>1,'sellerInvoiceAmount'=>'100.00','orderItemLineItemStatusName'=>$status];
$base=['id'=>null,'orderNumber'=>'TEST-OVERLAP','sellerId'=>123,'customerfullName'=>'Örnek kişi','shippingAddress'=>['fullName'=>'Örnek kişi','city'=>'Ankara','address'=>'Sentetik adres','gsm'=>'05000000000'],'shipmentPackageStatus'=>'Cancelled','packageHistories'=>[['status'=>'Created','createdDate'=>($fixtureNow-3600)*1000]]];
$packages=[[...$base,'lines'=>[$line(11,'Cancelled'),$line(12,'Picking')]],[...$base,'lines'=>[$line(12,'Cancelled')]]];
$ingest=function(array $raw,int $company=1)use($db,$repo){$v=DeliveryMapper::n11($raw);$v['details']=OrderDetailMapper::n11($raw,$v);$v['shipmentItems']=ShipmentOrderMapper::n11($raw,$v);$db->transaction(fn()=>$repo->ingest($company,$v,false));};
foreach($packages as $raw)$ingest($raw);
$initial=$repo->listing(1,'all','n11','cancelled',0,20,true);
check(count($initial['items'])===1&&$initial['queues']['cancelled']===1,'Mevcut örtüşen iki paket bir iptal kartına dönüşmeli');
check(array_sum(array_column($initial['items'][0]['queueProducts'],'quantity'))===2,'İki gerçek ürün birer kez görünmeli');
check(!$repo->listing(1,'all','n11','all',0,20,true)['items'],'Tam iptal aktif evrak listesine dönmemeli');
for($pass=0;$pass<3;$pass++)foreach($pass%2?array_reverse($packages):$packages as $raw)$ingest($raw);
check($repo->listing(1,'all','n11','cancelled',0,20,true)===$initial,'Tekrar ve ters sıra aktarımı görünümü değiştirmemeli');
check((int)$db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===2,'Ham kaynak paketleri korunmalı; tekrar yeni DB kaydı üretmemeli');
check((int)$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'Şehir dışı sevkiyatta iki farklı satır korunmalı');

// Same exact source identities belong to another company and cannot affect this list.
foreach($packages as $raw)$ingest($raw,2);
check($repo->listing(1,'all','n11','cancelled',0,20,true)===$initial,'Firma verileri birbirine karışmamalı');
check(count($repo->listing(2,'all','n11','cancelled',0,20,true)['items'])===1,'Diğer firmanın listesi bağımsız olmalı');
check(!$repo->listing(1,'Kayseri','n11','cancelled',0,20,true)['items'],'Şehir filtresi korunmalı');

if(in_array($argv[1]??'',['admin','client'],true)){
 $_GET=['company'=>'1','city'=>'all','provider'=>'n11','mode'=>'cancelled','limit'=>'20'];
 $role=$argv[1];$user=$role==='admin'?['id'=>1,'role'=>'admin']:['id'=>2,'role'=>'client','company_id'=>1];
 $routes=new DeliveryRoutes($db,new Security($db,[]),$cipher);
 if($role==='client'){
  try{$routes->handle('/api/delivery/orders','GET',$user);throw new RuntimeException('Müşteri erişimi engellenmedi');}
  catch(\Omnigo\HttpError $e){check($e->getCode()===403,'Müşteri iptal listesine erişememeli');echo json_encode(['status'=>403]);exit;}
 }
 $routes->handle('/api/admin/delivery/orders','GET',$user);
}

// Read-only list/count work, including encrypted payloads and shipment state.
$before=$db->all('SELECT * FROM delivery_orders ORDER BY id');$shipments=$db->all('SELECT * FROM shipment_entries ORDER BY id');
$repo->listing(1,'all','all','cancelled',0,20,true);$repo->listing(1,'Ankara','n11','all',0,20,true);
check($before===$db->all('SELECT * FROM delivery_orders ORDER BY id')&&$shipments===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Liste, ham paketleri ve sevkiyatları değiştirmemeli');

// Duplicates separated by more than one keyset batch cannot straddle output pages.
for($i=0;$i<251;$i++)$ingest([...$base,'orderNumber'=>'BULK-'.$i,'lines'=>[$line(1000+$i,'Cancelled')]]);
$ingest([...$packages[0],'id'=>'NEW-PACKAGE-ID']);
$items=[];$page=0;
do{$list=$repo->listing(1,'all','n11','cancelled',$page++,20,true);check($list['queues']['cancelled']===252,'Tüm sayfalarda gerçek kart sayacı aynı olmalı');$items=[...$items,...$list['items']];}while($list['hasMore']);
check(count($items)===252&&count(array_unique(array_column($items,'id')))===252,'20li sayfalarda eksik veya mükerrer olmamalı');
$overlap=array_values(array_filter($items,fn($o)=>$o['orderNumber']==='TEST-OVERLAP'));
check(count($overlap)===1&&count($overlap[0]['queueProducts'])===2,'250lik okuma sınırının iki yanında tekrarlanan paket tek kart kalmalı');
echo "n11 iptal tekilleştirme: kaynak paketler, tekrar/ters aktarım, farklı ürünler, kısmi iptal, firma/şehir, sayaç, 20li sayfalama ve salt okunur kontrol geçti.\n";
