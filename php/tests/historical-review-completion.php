<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,HistoricalReviewCompletion,MarketplaceDeliveryMigration,ShipmentFulfillment};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('h',32));
$cases=[
 ['old-review',1,'n11','review','pending','2026-09-30T20:59:59Z'],
 ['old-mismatch',1,'Hepsiburada','','shipped','2026-08-10T10:00:00Z'],
 ['old-confirmed',1,'n11','','shipped','2026-08-10T10:00:00Z'],
 ['old-pending',1,'n11','','pending','2026-08-10T10:00:00Z'],
 ['october',1,'n11','review','shipped','2026-09-30T21:00:00Z'],
 ['cancelled',1,'n11','cancelled','cancelled','2026-08-10T10:00:00Z'],
 ['source-cancelled',1,'n11','shipped','cancelled','2026-08-10T10:00:00Z'],
 ['removed',1,'n11','review','pending','2026-08-10T10:00:00Z'],
 ['other-company',2,'n11','review','pending','2026-08-10T10:00:00Z'],
 ['disabled-provider',1,'Teknosa','review','pending','2026-08-10T10:00:00Z'],
 ['legacy-delivered',1,'n11','delivered','pending','2026-08-10T10:00:00Z'],
];
foreach($cases as [$name,$company,$provider,$status,$source,$date]){
 $payload=['customerName'=>'Örnek kişi','productName'=>'Örnek ürün','quantity'=>2,'marketplaceShipping'=>$source,'orderedAt'=>$date];
 if($name==='old-confirmed')$payload['fulfillment']=['confirmedSource'=>'shipped'];
 $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,row_status,payload,ordered_at,invoiced,removed_at,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$company,'Ankara',$provider,substr($date,0,7),$status,'',$date,1,$name==='removed'?$date:null,$date,$date]);
 $id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
}
$before=$db->all('SELECT * FROM shipment_entries ORDER BY id');$migration=new HistoricalReviewCompletion($db,$cipher);
$preview=$migration->run(1,'2026-10');check($preview['changed']===0,'Kaynak durumu artık doğrudan uygulanır; yapay teyit ihtiyacı oluşturulmaz');
check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Ön izleme salt okunur');
check($migration->run(1,'2026-10',false)['changed']===0,'Eski toplu tamamlama yeni kaynak durumlarını değiştirmez');
check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Tarihsel kaynak, iptal ve başka firma verisi korunur');
$terminal=new MarketplaceDeliveryMigration($db,$cipher);check($terminal->run(1,true)['changed']===1,'Yalnız eski teslim etiketi standart tamamlanma durumuna alınır');
check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Eski etiket ön izlemesi yazmaz');
check($terminal->run(1)['changed']===1&&$terminal->run(1)['changed']===0,'Gösterim geçişi tek ve tekrarsızdır');
echo "Tarihsel durumlar: kaynak otoritesi, salt okunur ön izleme, firma ve iptal koruması, tekrarsız geçiş geçti.\n";
