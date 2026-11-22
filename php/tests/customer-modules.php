<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerModules,CustomerOnboarding,DeliverySchema,ShipmentSchema,DeliveryCipher,ShipmentOrderIngestor,CustomerManagement,ReconciliationDownstream};
function check(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));\Omnigo\InventorySchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO users VALUES(1,'test@example.test','Test','x','admin',NULL)");$admin=['id'=>1,'role'=>'admin'];$cipher=DeliveryCipher::withKey(str_repeat('m',32));
foreach([true,false] as $shipments)foreach([true,false] as $deliveries){
 $options=['shipments'=>$shipments,'deliveries'=>$deliveries];$id=CustomerOnboarding::create($db,$admin,'Yeni müşteri',null,$options);
 check(CustomerModules::read($db,$id)===$options,'Bağımsız bölüm seçimleri');
 (new CustomerManagement($db))->saveProfile($admin,$id,['name'=>'Güncel müşteri','version'=>1,'modules'=>CustomerModules::DEFAULTS]);
 check(CustomerModules::read($db,$id)===$options,'Profil kaydı bölümleri değiştirmez');
 check($db->one('SELECT COUNT(*) AS n FROM delivery_orders WHERE company_id=?',[$id])['n']===0,'Yeni müşteride teslimat kaydı yok');
 check($db->one('SELECT COUNT(*) AS n FROM jobs WHERE company_id=?',[$id])['n']===0,'Başka müşterinin bağlantısı kopyalanmaz');
 $item=['key'=>'test','payload'=>['province'=>'Ankara','orderedAt'=>gmdate('c'),'customerName'=>'Örnek','productName'=>'Ürün','quantity'=>1]];
 $added=$db->transaction(fn()=>(new ShipmentOrderIngestor($db,$cipher))->ingest($id,'Hepsiburada',[$item]));
 check($added===1,'Bölüm gizlense de arka plan aktarımı sürer');
 if(!$shipments){$issues=[];(new ReconciliationDownstream($db,$cipher))->compare($id,'hepsiburada',['capturedAt'=>gmdate('c'),'shipments'=>[hash('sha256','Hepsiburada:test')=>['orderedAt'=>gmdate('c'),'city'=>'Ankara','cancelled'=>false,'reference'=>'test','quantity'=>1,'invoiceCents'=>0]]],function($code)use(&$issues){$issues[]=$code;});check(!$issues,'Gizli bölümün arka planda aktarılan kaydı eksik aktarım alarmı üretmez');}
}
$db->run("INSERT INTO companies(id,name) VALUES(100,'Eski müşteri')");check(CustomerModules::read($db,100)===CustomerModules::DEFAULTS,'Mevcut müşterilerin bölümleri korunur');
echo "Yeni müşteri: dört bölüm seçeneği, boş başlangıç, bağlantı izolasyonu, profil koruması ve seçili sevkiyat aktarımı geçti.\n";
