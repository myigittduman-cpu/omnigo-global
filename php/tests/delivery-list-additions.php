<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, DeliveryCipher, DeliveryListAdditions, DeliveryPlan, DeliveryRepository, DeliverySchema,
    DeliverySelection, HttpError, ShipmentOrderIngestor, ShipmentRepository, ShipmentRevision, ShipmentSchema};

function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function rejected(callable $action, int $status): void
{
    try { $action(); }
    catch (HttpError $error) { check($error->getCode() === $status, $error->getMessage()); return; }
    throw new RuntimeException('İşlem engellenmeliydi.');
}
function fixture(string $stamp, string $number, string $line='a', string $status='pending', int $quantity=1, string $city='Ankara'): array
{
    $product=['lineId'=>$line,'sku'=>'SKU-'.$line,'title'=>'Deneme '.$line,'quantity'=>$quantity,'shippingState'=>$status];
    $active=in_array($status,['pending','shipped'],true);
    $contact=['recipientName'=>'Örnek Alıcı','customerName'=>'Örnek Müşteri','phone'=>'05000000000',
        'province'=>$city,'deliveryAddress'=>'Test adresi','orderedAt'=>$stamp];
    return ['provider'=>'Hepsiburada','externalId'=>$number.':'.$line,'orderNumber'=>$number,'city'=>$city,'createdAt'=>$stamp,
        'buyerName'=>$contact['customerName'],'address'=>$contact['deliveryAddress'],'phone'=>$contact['phone'],
        'status'=>Omnigo\ShipmentFulfillment::label($status),'active'=>$active,'products'=>$active?[$product]:[],
        'details'=>[...$contact,'products'=>[$product]],
        'shipmentItems'=>[['key'=>hash('sha256',json_encode([$number,$line],JSON_THROW_ON_ERROR)),
            'payload'=>[...$contact,'productName'=>$product['title'],'marketplaceShipping'=>$status,'quantity'=>$quantity,'invoiceCents'=>12500]]]];
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);
ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek mağaza'),(2,'Diğer mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','hash','admin',NULL),(2,'client@example.invalid','Müşteri','hash','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('v',32));
$repo=new DeliveryRepository($db,$cipher);
$add=new DeliveryListAdditions($db,$repo);
$exports=new DeliverySelection($db,$repo);
$stamp=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM);
$month=substr($stamp,0,7);
foreach ([1,2] as $company) ShipmentOrderIngestor::activate($db,$company,time()-86400);
$ingest=fn(array $order,int $company=1)=>$db->transaction(fn()=>$repo->ingest($company,$order,false));
$city=fn(int $company=1)=>$repo->listing($company,'Ankara','all','all',0,0,true);
$menu=fn(int $company=1,int $page=0)=>$repo->listing($company,'Ankara','all',DeliveryPlan::ADDITIONS,$page,10,true);
$find=fn(array $list,string $number)=>array_values(array_filter($list['items'],fn($order)=>$order['orderNumber']===$number))[0]??null;
$shipment=fn(string $line)=> (new ShipmentRepository($db,$cipher))->listing(1,['all','Hepsiburada',$month],0,'Deneme '.$line)['items'][0];

for($index=1;$index<=46;$index++)$ingest(fixture($stamp,'PENDING-'.$index,'a-'.$index,'pending',$index<=5?2:1));
for($index=1;$index<=12;$index++)$ingest(fixture($stamp,'SHIPPED-'.$index,'s-'.$index,'shipped'));
$ingest(fixture($stamp,'SHIPPED-12','second','shipped',2));
$ingest(fixture($stamp,'SHIPPED-12','pending','pending'));
$ingest(fixture($stamp,'DONE','delivered','delivered'));
$ingest(fixture($stamp,'CANCELLED','cancelled','cancelled'));
$ingest(fixture($stamp,'RETURNED','returned','returned'));
$ingest(fixture($stamp,'OUTSIDE','outside','shipped',1,'Eskişehir'));
$ingest(fixture($stamp,'SHIPPED-12','s-12','shipped'),2);

$main=$city();
$ankara=array_values(array_filter($main['cities'],fn($item)=>$item['name']==='Ankara'))[0];
check(count($main['items'])===58&&!$main['hasMore']&&$ankara['count']===58&&$ankara['productCount']===66,
    'Şehir bekleyen ve sevk edilen siparişleri tek sayfada döndürür.');
$all=$repo->listing(1,'all','all','all',0,0,true);
check(count($all['items'])===59&&!$all['hasMore']&&$find($all,'OUTSIDE')!==null,'Tüm şehirler tek sayfada aynı aktif kapsamı kullanır.');
check(!$find($main,'DONE')&&!$find($main,'CANCELLED')&&!$find($main,'RETURNED')&&!$find($main,'OUTSIDE'),
    'Teslim, iptal, iade ve farklı şehir kayıtları kapsam dışında kalır.');
check($menu()['items']===[]&&$menu()['total']===0,'Eski ekleme menüsü artık ayrı kayıt döndürmez.');
$mixed=$find($main,'SHIPPED-12');
$input=['city'=>'Ankara','deliveryDate'=>substr($stamp,0,10),'orders'=>$mixed['selectionRecords']];
check(count($input['orders'])===3&&$mixed['fulfillmentStatus']==='mixed'&&$shipment('s-12')['displayRowStatus']==='shipped',
    'Bekleyen ve sevk edilen paketler aynı siparişte gerçek durumlarıyla birleşir.');
check($find($main,'SHIPPED-1')['deliveryStatus']==='Sevk edildi','Sevk edilen sipariş durumu korunur.');
rejected(fn()=>$add->add(2,$input),404);
rejected(fn()=>$add->add(1,[...$input,'city'=>'Kayseri']),409);
$revision=ShipmentRevision::read($db,1);
$shipped=[...$input,'orders'=>$find($main,'SHIPPED-1')['selectionRecords']];
$result=$add->add(1,$shipped);
check($result['revision']===$revision&&$add->add(1,$shipped)===$result,'Eski istemcinin ekleme çağrısı kayıt veya revizyon çoğaltmaz.');
$document=$exports->snapshot(1,$input,true);
check(count($document['orders'])===1&&array_sum(array_column($document['orders'][0]['products'],'quantity'))===4,
    'PDF ve Excel bütün seçilen paketlerde aynı miktarı kullanır.');

$ingest(fixture($stamp,'SHIPPED-12','s-12','shipped'));
check($find($city(),'SHIPPED-12')['selectionRecords']===$mixed['selectionRecords'],'Tekrarlanan aktarım seçim sürümünü değiştirmez.');
$ingest(fixture($stamp,'SHIPPED-12','s-12','delivered'));
check(array_sum(array_column($find($city(),'SHIPPED-12')['products'],'quantity'))===3,'Kısmen teslim edilen siparişin kalan parçaları listede kalır.');
$ingest(fixture($stamp,'SHIPPED-12','second','delivered',2));
$ingest(fixture($stamp,'SHIPPED-12','pending','delivered'));
$ingest(fixture($stamp,'PENDING-1','a-1','cancelled',2));
$ingest(fixture($stamp,'SHIPPED-1','s-1','returned'));
$remaining=$city();
check(!$find($remaining,'SHIPPED-12')&&!$find($remaining,'PENDING-1')&&!$find($remaining,'SHIPPED-1')
    &&$shipment('s-12')['displayRowStatus']==='completed'&&$remaining['revision']!==$revision,
    'Teslim, iptal ve iade aktarımı ilgili siparişleri listeden çıkarır.');
rejected(fn()=>$exports->snapshot(1,$input,true),409);
check($find($city(2),'SHIPPED-12')!==null,'Diğer mağazanın kaydı bağımsızdır.');
echo "Teslimat planı, il, paket, canlı aktarım, evrak ve müşteri kapsamı doğrulandı.\n";
