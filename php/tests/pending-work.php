<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CustomerStandardPermissions,Database,DeliveryCipher,DeliverySchema,HttpError,PendingWorkSummary,ShipmentSchema};

function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function forbidden(callable $read):void{
    try{$read();}catch(HttpError $error){check($error->getCode()===403,'Erişim 403 ile engellenmeli.');return;}
    throw new RuntimeException('Yetkisiz istek kabul edildi.');
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$cipher=DeliveryCipher::withKey(str_repeat('t',32));$summary=new PendingWorkSummary($db,$cipher);
$admin=['id'=>1,'role'=>'admin'];
$client=['id'=>2,'company_id'=>1,'role'=>'client'];
$invoiceOnly=[...$client,'id'=>3];$dispatchOnly=[...$client,'id'=>4];$noOverview=[...$client,'id'=>5];
$defaults=CustomerStandardPermissions::grants();
$invoice=$dispatch=$hidden=$defaults;
$invoice['deliveries']['view']=false;
$dispatch['shipmentInvoices']['upload']=false;
$hidden['overviewWork']['view']=false;
$profile=['workspacePolicy'=>['defaults'=>$defaults,'users'=>[2=>$defaults,3=>$invoice,4=>$dispatch,5=>$hidden]]];
$saveProfile=fn(array $value)=>$db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($value,JSON_THROW_ON_ERROR)]);
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode($profile,JSON_THROW_ON_ERROR)]);

$date='2026-11-12T08:00:00Z';
$addShipment=function(int $id,int $company,array $payload)use($db,$cipher,$date):void{
    $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,ordered_at,created,updated) VALUES(?,?,?,?,?,?,?,?,?)',
        [$id,$company,'Ankara','PTT AVM','2026-11',$cipher->seal($company,'shipment:'.$id,$payload),$date,$date,$date]);
};
$addShipment(1,1,['marketplaceShipping'=>'pending']);
$addShipment(2,1,['marketplaceShipping'=>'shipped','documents'=>['invoice'=>['mime'=>'application/pdf']]]);
$addShipment(3,1,['marketplaceShipping'=>'cancelled']);
$addShipment(4,2,['marketplaceShipping'=>'pending']);
$addOrder=function(int $company,string $provider,string $orderNo,string $ext,string $city,array $states)use($db,$cipher,$date):void{
    $items=[];
    foreach($states as $index=>$status)$items[]=['lineId'=>(string)($index+1),'title'=>'Örnek ürün','sku'=>'SKU','quantity'=>1,'shippingState'=>$status];
    $payload=['provider'=>$provider,'externalId'=>$ext,'orderNumber'=>$orderNo,'city'=>$city,'active'=>true,
        'buyerName'=>'Örnek','phone'=>'05000000000','address'=>'Örnek adres','createdAt'=>$date,
        'details'=>['recipientName'=>'Örnek','products'=>$items],'products'=>$items];
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',
        [$company,$provider,$ext,$city,1,$cipher->seal($company,'order:'.$provider.':'.$ext,$payload),'test',$date]);
};
$addOrder(1,'PTT AVM','X','X-1','Ankara',['pending','undelivered']);
$addOrder(1,'PTT AVM','X','X-2','Ankara',['pending']);
$addOrder(1,'n11','X','X-1','Ankara',['pending']);
$addOrder(1,'PTT AVM','Y','Y-1','Ankara',['shipped']);
$addOrder(1,'PTT AVM','Z','Z-1','Ankara',['cancelled','delivered','undelivered']);
$addOrder(1,'PTT AVM','W','W-1','İzmir',['pending']);
$addOrder(1,'Teknosa','H','H-1','Ankara',['pending']);
$addOrder(2,'PTT AVM','T','T-1','Ankara',['pending']);
$expected=['invoice'=>1,'dispatch'=>2];
check($summary->read($admin,1)===$expected&&$summary->read($client,1)===$expected,'Sayaçlar iki rolde aynı kaynakları kullanmalı; sadece sevk bekleyen tekil siparişler sayılmalı.');
check($summary->read($invoiceOnly,1)===['invoice'=>1],'Belge ve teslimat izinleri ayrı ayrı korunmalı.');
check($summary->read($dispatchOnly,1)===['dispatch'=>2],'Yalnız teslimata yetkili kullanıcı belge sayaçlarını görmemeli.');
forbidden(fn()=>$summary->read($client,2));
forbidden(fn()=>$summary->read($noOverview,1));
$saveProfile([...$profile,'modules'=>['shipments'=>false,'deliveries'=>true]]);
check($summary->read($client,1)===['dispatch'=>2],'Kapalı sevkiyat modülünden belge sayısı dönmemeli.');
$saveProfile($profile);
$db->run('UPDATE shipment_entries SET payload=? WHERE id=1',[$cipher->seal(1,'shipment:1',[
    'marketplaceShipping'=>'pending','documents'=>['invoice'=>['mime'=>'application/pdf']],
])]);
check($summary->read($client,1)===['invoice'=>0,'dispatch'=>2],'Fatura yüklenince canlı sayaç sıfıra düşmeli.');
echo "Bekleyen işler: canlı sayaçlar, ayrı izinler, iptal/teslim dışlama, sipariş tekilleştirme ve mağaza ayrımı doğrulandı.\n";
