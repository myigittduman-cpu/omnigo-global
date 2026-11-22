<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,DeliverySchema,DeliveryCipher,DeliveryMapper,DeliveryRepository,OrderDetailMapper,OrderDetails,OrderList,HttpError};
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$cipher=DeliveryCipher::withKey(str_repeat('t',32));$repo=new DeliveryRepository($db,$cipher);$details=new OrderDetails($db,$cipher);$admin=['id'=>1,'role'=>'admin'];
$raw=['orderNumber'=>'100_2','id'=>10,'customerfullName'=>'Sipariş Sahibi','customerEmail'=>'siparis@test.invalid','shipmentPackageStatus'=>'Delivered','shippingAddress'=>['fullName'=>'Alıcı Adı','gsm'=>'05000000000','city'=>'Ankara','district'=>'Çankaya','address'=>'Teslimat sokağı'],'billingAddress'=>['fullName'=>'Fatura Firması','address'=>'Fatura caddesi','invoiceType'=>2,'taxId'=>'0012345678','taxHouse'=>'Test'],'packageHistories'=>[['status'=>'Created','createdDate'=>strtotime('2026-10-10T12:00:00+03:00')*1000]],'lines'=>[['orderLineId'=>12,'productName'=>'Ürün','stockCode'=>'SKU-1','quantity'=>2,'sellerInvoiceAmount'=>'123.45','orderItemLineItemStatusName'=>'Delivered']]];
foreach([1,2] as $company){$delivery=DeliveryMapper::n11($raw);$delivery['details']=OrderDetailMapper::n11($raw,$delivery);$repo->ingest($company,$delivery);}
$raw['id']=11;$raw['shippingAddress']['address']='İkinci paket adresi';$delivery=DeliveryMapper::n11($raw);$delivery['details']=OrderDetailMapper::n11($raw,$delivery);$repo->ingest(1,$delivery);
$raw['orderNumber']='100X2';$delivery=DeliveryMapper::n11($raw);$delivery['details']=OrderDetailMapper::n11($raw,$delivery);$repo->ingest(1,$delivery);
$value=['externalId'=>'100_2','createdAt'=>'2026-10-10T12:00:00+03:00','state'=>'Aktarıldı','orders'=>1,'grossCents'=>12345,'cancelCents'=>0,'returnCents'=>0,'products'=>[]];$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_n11',json_encode($value),gmdate('c')]);$id=$db->insertId();
$result=$details->detail($admin,1,$id);check(count($result['packages'])===2,'Paketler şirket ve kesin sipariş kimliğiyle ayrılır');$item=$result['packages'][0];check($item['customerName']==='Sipariş Sahibi'&&$item['recipientName']==='Alıcı Adı'&&$item['invoiceName']==='Fatura Firması','Kimlik alanları karışmaz');check($item['products'][0]['amountCents']===12345,'Adet tutarı yeniden çarpılmaz');
$list=(new OrderList($db,$details))->listing($admin,1,['from'=>'2026-10-01','to'=>'2026-10-31']);check(count($list['items'][0]['deliveryAddresses'])===2,'Tüm paket adresleri listelenir');
check($list['items'][0]['cities']===['Ankara'],'Aynı şehir listede tek kez gösterilir');
check($details->summary(1,['provider'=>'n11','externalId'=>'100_2'])['cities']===['Ankara'],'Tek sipariş özeti şehir bilgisini korur');
check($result['statusLabel']==='Teslim edildi'&&$list['items'][0]['statusLabel']==='Teslim edildi'&&$list['items'][0]['state']==='Aktarıldı','Liste ve ayrıntı güncel paket durumunu gösterir; finansal kaynak değişmez');
check(!str_contains(json_encode($db->all('SELECT payload FROM delivery_orders')),'05000000000'),'Kişisel bilgiler şifreli');check($repo->listing(1,'all','all','all',0)['items']===[],'Tamamlanmış sipariş aktif teslimat listesine girmez');
foreach([[['role'=>'client'],1,403],[$admin,2,404]] as [$user,$company,$code]){try{$details->detail($user,$company,$id);throw new RuntimeException('Erişim engellenmedi');}catch(HttpError $error){check($error->getCode()===$code,'Yetki ve firma ayrımı');}}
echo "Sipariş ayrıntıları: şifreleme, firma/yetki, çoklu paket, kimlik ayrımı ve tamamlanmış sipariş kontrolleri geçti.\n";

foreach(['Alıcı Adı','0500 000 00 00','ikinci paket','100_2'] as $query){$found=(new OrderList($db,$details))->listing($admin,1,['from'=>'2026-10-01','to'=>'2026-10-31','query'=>$query]);check($found['total']===1,'Şifreli kaynak alanlarında arama: '.$query);}

$raw['orderNumber']='SKU-CHECK';
$raw['lines']=[
    [...$raw['lines'][0],'orderLineId'=>13,'productId'=>70,'barcode'=>'8690123456781','stockCode'=>'00120','productName'=>'Model A'],
    [...$raw['lines'][0],'orderLineId'=>14,'productId'=>70,'barcode'=>'8690123456781','stockCode'=>'00120','productName'=>'Model A','quantity'=>1],
    [...$raw['lines'][0],'orderLineId'=>15,'productId'=>71,'barcode'=>'8690123456798','stockCode'=>'00120','productName'=>'Model B'],
];
$delivery=DeliveryMapper::n11($raw);
$delivery['details']=OrderDetailMapper::n11($raw,$delivery);
$repo->ingest(1,$delivery);
$value['externalId']='SKU-CHECK';
$value['products']=[
    ['lineId'=>'13','sku'=>'YANLIŞ-KOD','title'=>'Model A','quantity'=>2],
    ['sku'=>'00120','title'=>'Model A','quantity'=>1],
    ['lineId'=>'15','sku'=>'00120','title'=>'Model B','quantity'=>2],
    ['lineId'=>'16','sku'=>'BARKOD','title'=>'Eksik kaynak','quantity'=>1],
];
$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_n11',json_encode($value),gmdate('c')]);
$listed=(new OrderList($db,$details))->listing($admin,1,['from'=>'2026-10-01','to'=>'2026-10-31','query'=>'SKU-CHECK'])['items'][0]['products'];
check(array_column($listed,'productCode')===['00120','00120','00120'],'SKU yalnız doğrulanmış kaynak satırından gelir ve sıfırları korunur');
check($listed[0]['invoiceProductIdentity']===$listed[1]['invoiceProductIdentity']&&$listed[0]['invoiceProductIdentity']!==$listed[2]['invoiceProductIdentity'],'Aynı SKU farklı barkod/modeli birleştirmez');
check(!isset($listed[3]['productCode'])&&array_column($listed,'quantity')===[2,1,2,1],'Belirsiz SKU tahmin edilmez; kaynak adetler değişmez');
echo "Sipariş SKU kimlik ve adet denetimi geçti.\n";
