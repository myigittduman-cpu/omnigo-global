<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,ShipmentRepository,ShipmentBulkStatus,ShipmentOrderIngestor,ShipmentRevision};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('d',32));$repo=new DeliveryRepository($db,$cipher);$shipments=new ShipmentRepository($db,$cipher);$admin=['id'=>1,'role'=>'admin'];
$payload=['orderedAt'=>'2026-06-12T13:20:00+03:00','customerName'=>'Sipariş veren','recipientName'=>'Teslim alacak kişi','phone'=>'05000000000','deliveryAddress'=>'Örnek adres','province'=>'Ankara','district'=>'Çankaya','productName'=>'Örnek ürün','quantity'=>2,'invoiceName'=>'Fatura kişisi','invoiceCents'=>12300];
$insert=function(string $provider='n11',string $status='delivered',int $company=1,?string $removed=null)use($db,$cipher,$payload):int{
 $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,ordered_at,row_status,removed_at,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,'Ankara',$provider,'2026-06','','2026-06-12 10:20:00',$status,$removed,gmdate('c'),gmdate('c')]);$id=$db->insertId();
 $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);return $id;
};
$list=fn(string $provider='all',int $page=0)=>$repo->listing(1,'all',$provider,'delivered',$page,250,true);
foreach(['Hepsiburada','n11','idefix','Pazarama','Koçtaş'] as $provider){
 $id=$insert($provider,'shipped');$scope=['all',$provider,'2026-06'];$revision=ShipmentRevision::read($db,1);
 check($list($provider)['queues']['delivered']===0,'Teslim edilmeden listeye girmez');
 $saved=$shipments->toggle($admin,1,$id,$scope,['field'=>'rowStatus','value'=>'delivered','version'=>1]);
 $result=$list($provider);$item=$result['items'][0];
 check(count($result['items'])===1&&$result['queues']['delivered']===1&&$item['shipmentId']===$id,'Beş pazaryerinde elle teslim işareti anında görünür');
 check(ShipmentRevision::read($db,1)!==$revision,'İki paneli yenileten sürüm değişir');
 check($item['buyerName']===$payload['customerName']&&$item['recipientName']===$payload['recipientName']&&$item['address']===$payload['deliveryAddress']&&$item['products'][0]['quantity']===2,'Kişi, adres ve miktar kaynakla birebir');
 check(!$item['active']&&$item['orderNumber']===''&&$item['id']==='shipment:'.$id,'Evrak seçimine girmez; sipariş numarası uydurulmaz');
 $shipments->toggle($admin,1,$id,$scope,['field'=>'rowStatus','value'=>'cancelled','version'=>$saved['version']]);
 check(!$list($provider)['items']&&$list($provider)['queues']['delivered']===0,'İptale dönen kayıt ve sayaç otomatik çıkar');
 $shipments->toggle($admin,1,$id,$scope,['field'=>'rowStatus','value'=>'delivered','version'=>3]);
 check($list($provider)['queues']['delivered']===1,'Tekrar teslim işareti aynı kaydı kullanır');
}
$insert('n11','delivered',2);$insert('PTT AVM');$insert('n11','delivered',1,gmdate('c'));
foreach(['','shipped','cancelled','completed','invoiced'] as $status)$insert('n11',$status);
check($list()['queues']['delivered']===7,'Tamamlanan kayıt ve aktif PttAVM dahil; diğer firma, silinen ve diğer statüler dışarıda');
check(!$repo->listing(1,'Kayseri','all','delivered',0,250,true)['items'],'Şehir filtresi');
$a=$insert('n11','shipped');$b=$insert('n11','shipped');
(new ShipmentBulkStatus($db,$cipher))->save($admin,1,['all','n11','2026-06'],['value'=>'delivered','items'=>[['id'=>$a,'version'=>1],['id'=>$b,'version'=>1]]]);
check($list('n11')['queues']['delivered']===4,'Toplu durum işlemi aynı listeyi günceller');
// Recovery jobs have no browser dependency: a committed retry becomes visible without copying data.
ShipmentOrderIngestor::activate($db,1,time()-3600);$ingest=new ShipmentOrderIngestor($db,$cipher);
$source=['key'=>'test-api-line','payload'=>[...$payload,'orderedAt'=>gmdate('c',time()-60),'marketplaceShipping'=>'shipped']];
$save=fn($item)=>$db->transaction(fn()=>$ingest->ingest(1,'Pazarama',[$item]));$save($source);
$source['payload']['marketplaceShipping']='delivered';$save($source);$count=$list('Pazarama')['queues']['delivered'];$save($source);
check($count===2&&$list('Pazarama')['queues']['delivered']===$count,'API teslim geçişi ve tekrar denemesi mükerrer oluşturmaz');
$source['payload']['phone']='05550000000';$save($source);
check($list('Pazarama')['items'][0]['phone']==='05550000000','Sonraki bilgi değişikliği aynı kayıtta görünür');
$source['payload']['marketplaceCancellation']=['quantity'=>2,'cancelledQuantity'=>2,'partial'=>false];$save($source);
check($list('Pazarama')['queues']['delivered']===1,'Sonraki kaynak iptali teslim edilenlerden çıkar; sevkiyat tablosunda iptal olarak korunur');
for($i=0;$i<510;$i++)$insert('Koçtaş');
$ids=[];$page=0;do{$result=$list('Koçtaş',$page++);$ids=[...$ids,...array_column($result['items'],'shipmentId')];}while($result['hasMore']);
check(count($ids)===511&&count(array_unique($ids))===511&&$result['queues']['delivered']===511,'Tüm sayfalar eksiksiz ve tekrarsız; sayaç sayfa boyutuyla sınırlı değil');
$ids=[];$page=0;do{$result=$repo->listing(1,'all','Koçtaş','delivered',$page++,20,true);check(count($result['items'])<=20,'Sayfa 20 kaydı aşamaz');$ids=[...$ids,...array_column($result['items'],'shipmentId')];}while($result['hasMore']);
check(count($ids)===511&&count(array_unique($ids))===511&&$page===26&&$result['queues']['delivered']===511,'20 kayıtlık sayfalar eksiksiz; toplam sayaç değişmez');
$before=$db->all('SELECT * FROM shipment_entries ORDER BY id');$list();$list();
check($before===$db->all('SELECT * FROM shipment_entries ORDER BY id'),'Listeleme kaynak tablolara yazmaz');
echo "Teslim edilenler: beş pazaryeri, geçmiş/elle/toplu/API değişimleri, geri alma, iptal, tekrar, firma izolasyonu ve 511 satır sayfalama geçti.\n";
