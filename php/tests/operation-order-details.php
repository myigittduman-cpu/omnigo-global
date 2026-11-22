<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliverySchema,OperationOrderDetails};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");$cipher=DeliveryCipher::withKey(str_repeat('e',32));
$products=[['lineId'=>'line-a','title'=>'İade edilen ürün','sku'=>'A','quantity'=>2,'amountCents'=>20000],['lineId'=>'line-b','title'=>'Diğer ürün','sku'=>'B','quantity'=>1,'amountCents'=>50000]];
foreach([1,2] as $company){$value=['externalId'=>'SAME:package','orderNumber'=>'SAME','buyerName'=>$company===1?'Doğru alıcı':'Başka mağaza','phone'=>'Özel','address'=>'Özel','city'=>'Ankara','district'=>'Merkez','details'=>['products'=>$products]];$db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,'n11','SAME:package','Ankara',1,$cipher->seal($company,'order:n11:SAME:package',$value),'hash',gmdate('c')]);}
$claim=['id'=>'CLAIM','orderNumber'=>'SAME','lineId'=>'product-a','quantity'=>1,'amountCents'=>9000];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_n11',?,?)",[json_encode(['returnRequests'=>['items'=>[$claim]]]),gmdate('c')]);
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_n11',?,?)",[json_encode(['externalId'=>'SAME','products'=>[['productId'=>'product-a','lineId'=>'line-a']]]),gmdate('c')]);
$resolver=new OperationOrderDetails($db,$cipher);$result=$resolver->enrich(1,0,['events'=>['return_updated'],'provider'=>'n11','claimId'=>'CLAIM','orderNumber'=>'SAME']);
check($result['buyerName']==='Doğru alıcı'&&count($result['products'])===1,'İade doğru mağaza ve satıra bağlanmalı');
check($result['products'][0]['title']==='İade edilen ürün'&&$result['products'][0]['quantity']===1&&$result['claimAmountCents']===9000&&$result['products'][0]['invoiceCents']===20000,'Kısmi talep adedi ve talep tutarı fatura satırından ayrı kalmalı');
$unknown=$resolver->enrich(1,0,['events'=>['return_updated'],'provider'=>'n11','orderNumber'=>'SAME','claimId'=>'UNKNOWN']);check($unknown['products']===[]&&$unknown['claimAmountCents']===null,'Çok satırlı siparişte eşleşmeyen iade ürünü tahmin edilmemeli');
$cancel=$resolver->enrich(1,1,['events'=>['cancelled'],'provider'=>'n11','orderNumber'=>'SAME']);check(count($cancel['products'])===2&&!str_contains(json_encode($cancel),'Özel'),'İptalde ürünler gösterilir; adres/telefon eklenmez');
echo "İade satırı, kısmi adet, tutar ayrımı, firma sınırı ve bilinmeyen ürün doğrulandı.\n";

$pazarama=['externalId'=>'pazarama-order','orderNumber'=>'PAZ-TEST','details'=>['products'=>[
    ['lineId'=>'pazarama-line','quantity'=>1,'amountCents'=>1004900],
]]];
$db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',
    [1,'Pazarama','pazarama-order','Ankara',1,$cipher->seal(1,'order:Pazarama:pazarama-order',$pazarama),'test',gmdate('c')]);
$deliveryId=$db->insertId();
$pricing=['originalCents'=>1024900,'sellerDiscountCents'=>20000,'platformDiscountCents'=>61494,'discountedCents'=>943406,
    'reportCents'=>1004900,'reportBasis'=>'seller_invoice','platformDiscountReported'=>true];
$pazaramaLine=['lineId'=>'pazarama-line','quantity'=>1,'grossCents'=>1004900,'pricing'=>$pricing];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_pazarama',?,?)",
    [json_encode(['externalId'=>'pazarama-order','products'=>[$pazaramaLine]],JSON_THROW_ON_ERROR),gmdate('c')]);
$event=$resolver->enrich(1,$deliveryId,['events'=>['shipment_added'],'provider'=>'Pazarama','orderNumber'=>'PAZ-TEST']);
check($event['products'][0]['invoiceCents']===943406,'Eski Pazarama sipariş bildiriminde aynı kalemin indirim sonrası fatura tutarı görünmeli.');
