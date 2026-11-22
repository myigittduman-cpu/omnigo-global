<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliverySelection,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $action,int $code):void{try{$action();}catch(HttpError $e){check($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('İşlem reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('v',32));$repo=new DeliveryRepository($db,$cipher);
$add=function(array $states,string $provider='Hepsiburada',int $company=1,string $city='Ankara',?string $orderNumber=null)use($db,$cipher):int{
 $number='sample-'.($db->one('SELECT COUNT(*) AS n FROM delivery_orders')['n']+1);
 $products=[];foreach($states as $i=>$state)$products[]=['lineId'=>$number.'-L'.$i,'title'=>'Örnek '.$state,'sku'=>'SKU'.$i,'quantity'=>2,'shippingState'=>$state];
 $value=['provider'=>$provider,'externalId'=>$number,'orderNumber'=>$orderNumber??$number,'buyerName'=>'Örnek','recipientName'=>'Örnek Alıcı','phone'=>'05000000000','address'=>'Sentetik adres','city'=>$city,'createdAt'=>'2026-10-26T10:00:00+03:00','active'=>true,'status'=>'Hazırlanıyor','products'=>$products,'details'=>['recipientName'=>'Örnek Alıcı','products'=>$products],'issues'=>[]];
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$provider,$number,$city,1,$cipher->seal($company,'order:'.$provider.':'.$number,$value),'hash',gmdate('c')]);return $db->insertId();
};
$selection=new DeliverySelection($db,$repo);
$request=fn(array $records)=>['city'=>'Ankara','deliveryDate'=>'2026-10-27','orders'=>$records];
foreach(['Hepsiburada','n11','Pazarama','Koçtaş','idefix','PTT AVM','Trendyol'] as $provider){
 $pending=$add(['pending'],$provider);
 $shipped=$add(['shipped'],$provider);
 $mixed=$add(['pending','shipped','delivered','cancelled','undelivered','returned'],$provider,1,'Ankara','MULTI');
 $secondPackage=$add(['shipped'],$provider,1,'Ankara','MULTI');
 $closed=$add(['delivered','cancelled','undelivered','returned'],$provider);
 $list=$repo->listing(1,'Ankara',$provider,'all',0,20);
 check(array_column($list['items'],'id')===[$secondPackage,$shipped,$pending],$provider.' iki paket tek sipariş olarak listelenmeli.');
 check(array_column($list['items'],'fulfillmentStatus')===['mixed','shipped','pending'],$provider.' sevkiyat durumları korunmalı.');
 check($list['cities'][0]['count']===3&&$list['cities'][0]['productCount']===10,$provider.' kapanan satırlar şehir sayaçlarına eklenmemeli.');
 check(count(array_unique(array_column($list['items'],'selectionKey')))===3,'Seçim anahtarları çakışmamalı.');
 $records=$list['items'][0]['selectionRecords'];
 check($records===[['id'=>$secondPackage,'version'=>1],['id'=>$mixed,'version'=>1]],'Seçim iki kaynak paketinin kimliğini ve sürümünü taşımalı.');
 $snapshot=$selection->snapshot(1,$request($records));
 check(count($snapshot['orders'])===1&&$snapshot['orders'][0]['provider']===$provider&&$snapshot['orders'][0]['orderNumber']==='MULTI','Seçilen paketler aynı pazaryerinin tek evrağına yazılmalı.');
 $titles=array_column($snapshot['orders'][0]['products'],'title');sort($titles);
 check($titles===['Örnek pending','Örnek shipped','Örnek shipped']
    &&array_sum(array_column($snapshot['orders'][0]['products'],'quantity'))===6,'Evrakta sadece iki paketin sevk edilebilir satırları bulunmalı.');
 denied(fn()=>$selection->snapshot(1,$request([['id'=>$closed,'version'=>1]])),409);
 denied(fn()=>$selection->snapshot(1,$request([['id'=>$mixed,'version'=>2]])),409);
}
$other=$add(['pending'],'n11',2);denied(fn()=>$selection->snapshot(1,$request([['id'=>$other,'version'=>1]])),404);
foreach([[],[['id'=>1]],[['id'=>1,'version'=>0]],[['id'=>'1','version'=>1]],[['id'=>1,'version'=>1],['id'=>1,'version'=>1]]] as $invalid){
 denied(fn()=>$selection->snapshot(1,$request($invalid)),400);
}
for($i=0;$i<24;$i++)$add([$i%2?'pending':'shipped'],'n11',1,'Kayseri');
$before=$db->all('SELECT * FROM delivery_orders ORDER BY id');$filters=['cities'=>['Kayseri']];
$first=$repo->listing(1,'all','n11','all',0,10,false,$filters);$second=$repo->listing(1,'all','n11','all',1,10,false,$filters);
$last=$repo->listing(1,'all','n11','all',2,10,false,$filters);
$items=[...$first['items'],...$second['items'],...$last['items']];
check(count($first['items'])===10&&count($second['items'])===10&&count($last['items'])===4&&$first['hasMore']&&$second['hasMore']&&!$last['hasMore'],'Şehir filtresi sayfalama öncesinde uygulanmalı.');
check(count(array_unique(array_column($items,'selectionKey')))===24&&array_unique(array_column($items,'city'))===['Kayseri'],'Sayfalarda tekrar veya başka şehir bulunmamalı.');
$selection->snapshot(1,[...$request($first['items'][0]['selectionRecords']),'city'=>'Kayseri']);
check($before===$db->all('SELECT * FROM delivery_orders ORDER BY id'),'Liste ve evrak okuması kaynakları değiştirmez');
echo "Teslimat listesi: yedi pazaryeri, bekleyen/sevk/kısmi sevk, kaynak paket seçimi, iptal/iade/teslim dışlama, sayfalama, şehir ve firma ayrımı geçti.\n";
