<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliveryDispatchScope,DeliveryDocuments,DeliveryRepository,DeliverySchema,DeliverySelection,DeliveryShippingLabels,HepsiburadaCredentials,HepsiburadaPackageShipping,HepsiburadaReadClient,HepsiburadaShippingLabelCache,HttpError,OrderDetailMapper,ShipmentSchema};

function verify(bool $valid,string $message): void { if(!$valid) throw new RuntimeException($message); }
function label(string $id,?string $value): array { return ['packageId'=>$id,'value'=>$value,'format'=>'CODE128']; }

foreach ([null,true,0,1.2,'000000','direct delivery','javascript:12','00123'.chr(10),str_repeat('1',49)] as $invalid) {
    verify(DeliveryShippingLabels::code($invalid)===null,'Geçersiz değer barkoda dönüşmemeli.');
}
verify(DeliveryShippingLabels::code('00012-B')==='00012-B','Baştaki sıfırlar korunur.');
verify(DeliveryShippingLabels::merge([label('1','0012'),label('1','12')])===[label('1',null)],'Paket çelişkisi reddedilir.');
verify(DeliveryShippingLabels::merge([label('1','123'),label('2','123')])===[label('1',null),label('2',null)],'İki paketin çakışan kodu basılmaz.');
foreach (['idefix','Koçtaş','Teknosa'] as $provider) {
    verify(DeliveryShippingLabels::forProduct(['provider'=>$provider,'orderNumber'=>'123','details'=>['packageId'=>'987','trackingNumber'=>'123456']],['sku'=>'999','barcode'=>'888'])===label('',null),'Taşıyıcı barkodu doğrulanamayan pazaryeri.');
}
foreach (['PTT AVM','n11'] as $provider) {
    $doc=['provider'=>$provider,'details'=>['packageId'=>'111','trackingNumber'=>'000222']];
    verify(DeliveryShippingLabels::forProduct($doc,[])===label($provider==='n11'?'111':'','000222'),'Kaynak kargo barkodu korunur.');
}
$raw=['items'=>[['orderItemId'=>'1','shipmentCode'=>'000812','product'=>['barcode'=>'111222']]]];
$details=OrderDetailMapper::pazarama($raw,['orderNumber'=>'123','buyerName'=>'','address'=>'','phone'=>'','city'=>'Ankara','district'=>'','createdAt'=>'','status'=>'']);
verify(DeliveryShippingLabels::forProduct(['provider'=>'Pazarama','details'=>$details],$details['products'][0])===label('','000812'),'Pazarama ürün EAN yerine gönderi kodu.');

$merchant='12345678-1234-1234-1234-123456789012';
$cipher=DeliveryCipher::withKey(str_repeat('q',32));
$cache=new HepsiburadaShippingLabelCache(1,$merchant,$cipher);
$feed=['packageNumber'=>'100','barcode'=>'00123','items'=>[['merchantId'=>$merchant,'orderNumber'=>'5050']]];
$cache->rememberFeed([$feed]);$saved=[];$cache->save($saved);
verify(!str_contains($saved['packageLabels']['envelope'],'00123'),'Kalıcı işleyici önbelleği şifreli.');
$cache=new HepsiburadaShippingLabelCache(1,$merchant,$cipher,$saved['packageLabels']);
verify($cache->read('5050','100')==='00123' && $cache->read('6060','100')===null,'Paket ve sipariş eşleşmeli.');
try { new HepsiburadaShippingLabelCache(2,$merchant,$cipher,$saved['packageLabels']);throw new LogicException('Yanlış firma şifreyi çözdü.'); } catch(RuntimeException) {}
$cache->rememberFeed([$feed,[...$feed,'barcode'=>'123']]);
verify($cache->read('5050','100')===null,'Aynı feed içinde sayı gibi görünen iki farklı kod reddedilmeli.');
$credentials=HepsiburadaCredentials::fromArray(['merchantId'=>$merchant,'username'=>'test','serviceKey'=>'test','userAgent'=>'test_dev']);
$line=['id'=>'line','merchantId'=>$merchant,'orderNumber'=>'5050','packageNumber'=>'100','merchantSKU'=>'123456','name'=>'Örnek ürün','quantity'=>1,'totalPrice'=>['currency'=>'TRY','amount'=>'100.00'],'status'=>'Packaged'];
$order=['orderNumber'=>'5050','orderDate'=>'2026-09-02T13:16:00+03:00','items'=>[$line]];
$state=[];$codes=['00123','123'];
$reader=new HepsiburadaReadClient(function()use(&$codes){return ['status'=>200,'body'=>json_encode(array_map(fn($v)=>['packageNumber'=>'100','barcode'=>$v,'status'=>'InTransit'],$codes))];});
$read=function()use(&$state,$order,$reader,$credentials,$cache) {
    $state=[];$mapped=HepsiburadaPackageShipping::map($order,$reader,$credentials,time()+30,$state,$cache);
    verify(!str_contains(json_encode($mapped['order']),'00123'),'Kargo barkodu mali sipariş dizinine aktarılmamalı.');
    return DeliveryShippingLabels::forProduct($mapped['deliveries'][0],[]);
};
verify($read()===label('100',null),'Gönderi servisinin uyuşmayan barkodları reddedilir.');
$codes=['00123',null];verify($read()===label('100',null),'Eksik ve dolu çelişkisi reddedilir.');
$codes=['00123','00123'];verify($read()===label('100','00123'),'Eşleşen paket barkodu.');

$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'A'),(2,'B')");
$db->run("INSERT INTO users(email,name,password,role) VALUES('test@example.invalid','Test','unused','admin')");
$repo=new DeliveryRepository($db,$cipher);$selection=new DeliverySelection($db,$repo);$docs=new DeliveryDocuments($db,$cipher,$repo);
$products=[];$units=[];
foreach([['a','P1','00123','pending'],['b','P1','00123','pending'],['c','P2','00234','shipped'],['d','P3','00345','cancelled']] as [$line,$package,$value,$status]){
    $products[]=['lineId'=>$line,'title'=>'Ürün','quantity'=>1,'sku'=>'99999','shippingState'=>$status];
    $units[$line]=['package'=>$package,'trackingNumber'=>$value];
}
$record=['provider'=>'Trendyol','externalId'=>'x','orderNumber'=>'shared','buyerName'=>'Test','phone'=>'05000000000','address'=>'Sentetik adres','city'=>'Ankara','district'=>'Çankaya',
    'createdAt'=>'2026-11-01T12:00:00+03:00','active'=>true,'status'=>'Hazırlanıyor','issues'=>[],'products'=>$products,
    'trendyolSourceItems'=>$units,'details'=>['recipientName'=>'Örnek Alıcı','trackingNumber'=>'wrong999','products'=>$products]];
foreach([1,2] as $company) {
    $tenantRecord=$record;
    if($company===2)$tenantRecord['trendyolSourceItems']['a']['trackingNumber']='OTHER123';
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',
        [$company,'Trendyol','x','Ankara',1,$cipher->seal($company,'order:Trendyol:x',$tenantRecord),'test',gmdate('c')]);
}
$req=['city'=>'Ankara','deliveryDate'=>'2026-11-20','orders'=>[['id'=>1,'version'=>1]]];
$expected=[label('P1','00123'),label('P2','00234')];
$export=$selection->snapshot(1,$req)['orders'][0];
verify($export['shippingLabels']===$expected && count($export['products'])===3,'Yalnız seçilen sevk edilebilir ürünlerin paketleri basılır.');
$grouped=DeliveryDispatchScope::exportOrders([$export,[...$export,'shippingLabels'=>[label('P2','00234'),label('P4','00089')]]]);
verify(count($grouped)===1 && count($grouped[0]['shippingLabels'])===3,'Aynı alıcıdaki çoklu fiziksel paket tekilleştirilir.');
try {$selection->snapshot(2,$req);throw new LogicException('Başka mağazanın kaydı görüldü.');}catch(HttpError $e){verify($e->getCode()===404,'Mağaza sınırı.');}
$doc=$docs->create(1,1,[...$req,'requestId'=>str_repeat('a',32)]);
verify($docs->get(1,$doc['id'])['orders'][0]['shippingLabels']===$expected,'Arşiv çıktısında aynı barkodlar.');
$record['trendyolSourceItems']['a']['trackingNumber']='REPLACED8';$record['trendyolSourceItems']['b']['trackingNumber']='REPLACED8';
$db->run('UPDATE delivery_orders SET payload=? WHERE id=1',[$cipher->seal(1,'order:Trendyol:x',$record)]);
verify($docs->get(1,$doc['id'])['orders'][0]['shippingLabels']===[label('P1',null),label('P2','00234')],'Eski arşiv yenilenen barkodu eski pakete taşımaz.');
$db->run('UPDATE delivery_orders SET document_id=NULL WHERE id=1');
verify(array_column($docs->get(1,$doc['id'])['orders'][0]['shippingLabels'],'value')===[null,null],'Adres veya teslimat kapsamı değişen evrakın eski etiketleri kullanılamaz.');
echo "Paket barkodu kaynağı, tekilleştirme, şirket ve adres kapsamı, kısmi sevk, arşiv ve çelişki kontrolleri geçti.\n";
