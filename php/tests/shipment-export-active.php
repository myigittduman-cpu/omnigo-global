<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,ShipmentOrdering,ShipmentRepository,ShipmentSchema};

function check(bool $condition,string $reason):void{if(!$condition)throw new RuntimeException($reason);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Sevkiyat'),(2,'Diğer Örnek')");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));
$scope=['all','n11','2026-11'];
$add=function(string $name,array $extra=[],bool $cancelled=false,int $company=1)use($db,$cipher){
    $date='2026-11-10T10:00:00Z';
    $payload=['customerName'=>$name,'recipientName'=>$name,'orderNumber'=>$name,'orderedAt'=>$date,'productName'=>$name,'quantity'=>5,'invoiceCents'=>50000,...$extra];
    $db->run('INSERT INTO shipment_entries (company_id,city,provider,month,payload,ordered_at,row_status,cancelled,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?)',[$company,'Ankara','n11','2026-11','',ShipmentOrdering::timestamp($payload),$cancelled?'cancelled':'',(int)$cancelled,$date,$date]);
    $id=$db->insertId();
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
};
$add('ACTIVE',['deliveryAddress'=>'Örnek Mahallesi, Denetim Sokak No 10','province'=>'Ankara','district'=>'Çankaya',
    'marketplaceReturn'=>['partial'=>null,'returnedQuantity'=>null]]);
$add('CANCELLED',cancelled:true);
$add('SOURCE-CANCELLED',['marketplaceCancellation'=>['quantity'=>5,'cancelledQuantity'=>5,'partial'=>false]]);
$add('RETURNED',['marketplaceReturn'=>['quantity'=>5,'returnedQuantity'=>5,'partial'=>false]]);
foreach([false,0,'0','false'] as $i=>$fullFlag){
    $add('RETURNED-LEGACY-'.$i,['marketplaceReturn'=>['quantity'=>5,'returnedQuantity'=>1,'remainingQuantity'=>4,'partial'=>$fullFlag]]);
}
$add('PARTIAL',['marketplaceCancellation'=>['quantity'=>5,'cancelledQuantity'=>1,'partial'=>true],'marketplaceReturn'=>['quantity'=>5,'returnedQuantity'=>2,'remainingQuantity'=>2,'partial'=>true]]);
$add('ALL-GONE',['marketplaceCancellation'=>['quantity'=>5,'cancelledQuantity'=>2,'partial'=>true],'marketplaceReturn'=>['quantity'=>5,'returnedQuantity'=>3,'remainingQuantity'=>0,'partial'=>false]]);
$add('OTHER-STORE',company:2);
$export=null;
foreach([[false,false],[false,true],[true,true],[true,false]] as [$hideCancel,$hideReturn]){
    $repository=new ShipmentRepository($db,$cipher,$hideCancel,$hideReturn);
    $export=$repository->export(1,$scope);
    $items=array_column($export['items'],null,'orderNumber');
    check(array_keys($items)===['PARTIAL','ACTIVE'],'Sadece belgeye uygun aynı mağaza satırları gelmeli.');
    check($items['PARTIAL']['quantity']===2&&$items['PARTIAL']['invoiceCents']===null,'Kalan iki ürün ve teyitsiz fatura tutarı.');
    check($items['ACTIVE']['invoiceCents']===50000&&$export['summary']['productCount']===7&&$export['summary']['orderCount']===2,'Etkin tutar ve özet aynı belge kapsamına ait olmalı.');
    check($repository->export(2,$scope)['items'][0]['orderNumber']==='OTHER-STORE','Mağazalar bağımsız olmalı.');
}
$before=(new ShipmentRepository($db,$cipher))->listing(1,$scope);
check($before['total']===10,'Dışa aktarım canlı kayıtları ve operasyon listesini değiştirmemeli.');
$linked=['quantity'=>5,'invoiceCents'=>50000,'marketplaceReturn'=>['quantity'=>5,'returnedQuantity'=>1,'remainingQuantity'=>4,'partial'=>true]];
$projected=\Omnigo\ShipmentExportLine::project($linked,[['quantity'=>5,'refundedQuantity'=>1,'cancelledQuantity'=>1],['quantity'=>5,'refundedQuantity'=>1,'cancelledQuantity'=>1]]);
check($projected['quantity']===3,'Aynı birimler birden fazla kaynak paketinden yeniden düşülmemeli.');
echo "Belgede kalan satırlar, adet, tutar, liste ve mağaza ayrımı doğrulandı.\n";
