<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,ShipmentOrderIngestor,ShipmentSourceStatus,MarketplaceDeliveryMigration,ShipmentRevision,ShipmentFulfillment,DeliveryFulfillment};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('d',32));ShipmentOrderIngestor::activate($db,1,time()-86400*30);
$now=gmdate('c',time()-86400);$ingest=new ShipmentOrderIngestor($db,$cipher);$providers=['n11','Hepsiburada','Pazarama','Koçtaş','idefix','PTT AVM'];
foreach($providers as $provider){
 $payload=['orderedAt'=>$now,'province'=>'Ankara','productName'=>'Test','quantity'=>1,'marketplaceShipping'=>'delivered','sourceDeliveryId'=>'D-'.$provider];
 $save=fn($p)=>$db->transaction(fn()=>$ingest->ingest(1,$provider,[['key'=>'new','payload'=>$p]]));
 check($save($payload)===1,'Yeni teslim kaydı eklenir');
 $source=hash('sha256',$provider.':new');$row=$db->one('SELECT * FROM shipment_entries WHERE company_id=1 AND source_key=?',[$source]);
 check($row['row_status']==='completed','İlk aktarımda teslim otomatik tamamlanır');
 check($save($payload)===0,'Tekrar tekil kalır');
 $db->run("UPDATE shipment_entries SET row_status='review',invoiced=1 WHERE id=?",[$row['id']]);
}
// Unrelated terminal/manual/cancelled/removed/other-company rows are immutable to this migration.
foreach(['completed','cancelled','removed','manual','source-cancelled','pending','shipped','other-company'] as $case){
 $company=$case==='other-company'?2:1;$status=in_array($case,['completed','cancelled','shipped'],true)?$case:'review';
 $payload=['marketplaceShipping'=>in_array($case,['pending','shipped','cancelled'],true)?$case:'delivered'];
 if($case==='source-cancelled')$payload['marketplaceCancellation']=['quantity'=>1,'cancelledQuantity'=>1];
 $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,row_status,payload,source_key,removed_at,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,'Ankara','n11',substr($now,0,7),$status,'',$case==='manual'?null:hash('sha256',$case),$case==='removed'?$now:null,$now,$now]);
 $id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
}
$before=$db->all('SELECT * FROM shipment_entries ORDER BY id');$revision=ShipmentRevision::read($db,1);$migration=new MarketplaceDeliveryMigration($db,$cipher);
check($migration->run(1,true)['changed']===6,'Ön izleme altı uygun kayıt');check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Ön izleme yazmaz');
check($migration->run(1)['changed']===6,'Altı pazaryerindeki eski teyit kayıtları teslim olur');
$after=$db->all('SELECT * FROM shipment_entries ORDER BY id');
foreach($after as $index=>$row){
 if($index<6){check($row['row_status']==='completed'&&(int)$row['invoiced']===1,'Tek yeşil tamamlanma ve mevcut fatura işareti korunur');check($row['payload']===$before[$index]['payload'],'Ürün, adres ve geçmiş teyit verileri değişmez');}
 else check($row===$before[$index],'Kapsam dışı ve iptal kayıtları değişmez');
}
check(ShipmentRevision::read($db,1)!==$revision,'İki panelin yenileme sinyali ilerler');
check($migration->run(1)['changed']===0,'Tekrar geçiş yazma yapmaz');check($after===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'İkinci geçiş sürüm de değiştirmez');
foreach(['','shipped','review','invoiced','delivered'] as $status)check(ShipmentSourceStatus::next(['row_status'=>$status],[],['marketplaceShipping'=>'delivered'],true)==='completed','Kaynak teslim otomatik tamamlanır');
check(ShipmentSourceStatus::next(['row_status'=>'completed'],[],['marketplaceShipping'=>'delivered'],true)===null,'Aynı kaynak durumu tekrar yazılmaz');
check(ShipmentSourceStatus::next(['row_status'=>'cancelled'],[],['marketplaceShipping'=>'delivered'],true)==='completed','Güncel pazaryeri teslim bilgisi eski türetilmiş etiketi düzeltir');
foreach(['pending'=>null,'shipped'=>'shipped','undelivered'=>'cancelled','unknown'=>null] as $source=>$expected)check(ShipmentSourceStatus::next(['row_status'=>''],[],['marketplaceShipping'=>$source],false)===$expected,'Kaynak sevk/iptal durumu otomatik uygulanır');
check(ShipmentSourceStatus::isDelivered(['marketplaceRowStatus'=>'completed']),'Normalize kaynak tamamlandı teslim bilgisi sayılır');
check(!ShipmentSourceStatus::isDelivered(['marketplaceShipping'=>'delivered','marketplaceCancellation'=>['quantity'=>1,'cancelledQuantity'=>1]]),'İptal teslim bilgisinden önceliklidir');
$view=ShipmentFulfillment::presentation(['row_status'=>'delivered'],['marketplaceShipping'=>'delivered']);check(!$view['fulfillmentNeedsReview']&&$view['marketplaceDelivered'],'Teslim yeniden teyit istemez');
echo "6 pazaryeri: otomatik teslim, manuel yeşil tamamlama, iptal koruması, kuru çalışma, tekil geçiş, firma izolasyonu ve canlı yenileme sinyali geçti.\n";
