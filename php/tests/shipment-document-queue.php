<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, DeliveryCipher, HttpError, ShipmentDocumentActions, ShipmentDocumentQueue, ShipmentOrderIngestor, ShipmentOrdering, ShipmentSchema};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$cipher = DeliveryCipher::withKey(str_repeat('q', 32));
$date = '2026-11-11T12:00:00+00:00';

$add = static function (array $fields = [], ?string $status = '', int $cancelled = 0, int $company = 1) use ($db, $cipher, $date): int {
    $payload = [...[
        'orderNumber' => 'Örnek', 'orderedAt' => $date, 'productName' => 'Örnek ürün', 'quantity' => 3,
        'marketplaceShipping' => 'pending', 'invoiceCents' => 15000,
    ], ...$fields];
    $db->run('INSERT INTO shipment_entries(company_id, city, provider, month, payload, ordered_at, row_status, cancelled, created, updated) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$company, 'Ankara', 'Hepsiburada', '2026-11', '', ShipmentOrdering::timestamp($payload), $status, $cancelled, $date, $date]);
    $id = $db->insertId();
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?', [$cipher->seal($company, 'shipment:'.$id, $payload), $id]);
    return $id;
};

$partial = $add([
    'orderNumber'=>'123456-78901','customerName'=>'Işıl Öztürk','productName'=>'Çamaşır Makinesi',
    'phone'=>'05001234567','marketplaceCancellation'=>['quantity'=>3,'cancelledQuantity'=>1,'partial'=>true],
]);
$active = [$partial];
for ($i = 0; $i < 11; $i++) $active[] = $add();
$other = $add(['customerName'=>'Sadece Diğer Mağaza'], company: 2);
$legacy = $add(status: null, cancelled: 1);
$manual = $add(status: 'cancelled');
$notDelivered = $add(['marketplaceShipping' => 'undelivered']);
$confirmed = $add(['marketplaceCancellation' => ['quantity' => 3, 'cancelledQuantity' => 3, 'partial' => false]]);
$invoiceOnCancelled = $add(['productName'=>'Gizli Belge Ürünü','invoiceCents'=>88119913,'marketplaceShipping' => 'cancelled', 'documents' => ['invoice' => ['mime' => 'application/pdf']]]);
$deliveryOnCancelled = $add(['productName'=>'Gizli Belge Ürünü','documents' => ['delivery' => ['mime' => 'application/pdf']]], status: 'cancelled');
$invoiceOnActive = $add(['customerName'=>'Belge Müşterisi','documents' => ['invoice' => ['mime' => 'application/pdf']]]);
$deliveryOnActive = $add(['customerName'=>'Belge Müşterisi','documents' => ['delivery' => ['mime' => 'application/pdf']]]);
$withMarks=new ShipmentDocumentQueue($db,$cipher,false,true,true);
$completedMarks=$withMarks->page(1,'invoice','completed',0,50);
$safeMarks=\Omnigo\OperationalCancellationMark::shipments($completedMarks,false);
$red=array_column($safeMarks['items'],null,'id')[$invoiceOnCancelled];
check($red['cancellationMark']==='cancelled'&&$red['customerReadOnly']&&$red['documentRestricted'],'Tam iptalde yalnızca operasyon işareti gösterilir.');
foreach(['invoiceCents','quantity','documents','marketplaceCancellation','marketplaceAmounts'] as $key)
    check(!array_key_exists($key,$red),'İptal durum izniyle sınırlı satır hassas alan içeriyor: '.$key);
$fullMarks=\Omnigo\OperationalCancellationMark::shipments($completedMarks,true);
check(array_key_exists('quantity',array_column($fullMarks['items'],null,'id')[$invoiceOnCancelled]),'Yetkili kullanıcı eski miktar alanını korumalı.');
$shipmentRead=new \Omnigo\ShipmentRepository($db,$cipher,false,true,true);
$selection=['Ankara','Hepsiburada','2026-11'];
$raw=$shipmentRead->listing(1,$selection,0,'Gizli Belge Ürünü',['cancelled'=>'yes']);
check($raw['filters']['cancelled']===''&&$raw['items'],'Durum işareti, özel iptal filtresini açmamalı.');
$safe=\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\OperationalCancellationMark::shipments($raw,false),\Omnigo\CustomerTableVisibility::NONE);
foreach($safe['items'] as $row)check($row['cancellationMark']==='cancelled'&&!array_key_exists('quantity',$row)&&!array_key_exists('invoiceCents',$row)&&$row['documentRestricted'],'Sevkiyatta iptal işareti görünebilir; sayısal değerler ve belge kapalı.');
$amountSearch=$shipmentRead->listing(1,$selection,0,'881199,13');
check(!$amountSearch['items'],'Gizlenen fatura tutarı arama yoluyla da bulunamamalı.');
$export=(new \Omnigo\ShipmentRepository($db,$cipher,true,true))->export(1,$selection,'Gizli Belge Ürünü');
check(!$export['items'],'Durum işaretiyle görünür olan iptal edilmiş kayıt dışa aktarılamaz.');
$add(['orderedAt' => '2026-11-09T12:00:00+00:00']);
// Force the cursor to cross a batch containing only cancelled rows.
for ($i = 0; $i < 121; $i++) $add(['marketplaceShipping' => 'cancelled']);

$expected = [
    'invoice' => [$deliveryOnActive, ...array_reverse($active)],
    'delivery' => [$invoiceOnActive, ...array_reverse($active)],
];
$completed = [
    'invoice' => [$invoiceOnActive, $invoiceOnCancelled],
    'delivery' => [$deliveryOnActive, $deliveryOnCancelled],
];
foreach ([false, true] as $customer) {
    $queue = new ShipmentDocumentQueue($db, $cipher, $customer);
    check($queue->pendingCounts(1) === ['invoice' => 13, 'delivery' => 13], 'İptal edilmiş satırlar iki belge sayacından da düşmeli.');
    check(array_column($queue->page(2, 'invoice', 'pending', 0, 10)['items'], 'id') === [$other], 'Bekleyen belge listeleri mağazaya özel olmalı.');
    foreach ($expected as $kind => $ids) {
        $first = $queue->page(1, $kind, 'pending', 0, 10);
        $last = $queue->page(1, $kind, 'pending', 3, 10);
        check($first['total'] === 13 && $first['pendingTotal'] === 13 && $last['page'] === 1,
            'İptal edilenler toplamı veya son sayfayı etkilememeli.');
        check([...array_column($first['items'], 'id'), ...array_column($last['items'], 'id')] === $ids,
            'Etkin siparişler ve kısmi iptalden kalan satır sayfalarda korunmalı.');
        $first = $queue->listing(1, $kind, 'pending', 0);
        $last = $queue->listing(1, $kind, 'pending', $first['next']);
        check([...array_column($first['items'], 'id'), ...array_column($last['items'], 'id')] === $ids && $last['next'] === null,
            'İmleçli liste de iptalleri atlayıp aynı satırları göstermeli.');
        $done = $queue->page(1, $kind, 'completed', 0, 50);
        $visibleCompleted = $customer ? array_slice($completed[$kind], 0, 1) : $completed[$kind];
        check(array_column($done['items'], 'id') === $visibleCompleted && $done['pendingTotal'] === 13,
            'Yönetici yüklenmiş belgeleri görmeli, müşteri iptal edilmiş satırı görmemeli.');
        check(array_column($queue->listing(1, $kind, 'completed', 0)['items'], 'id') === $visibleCompleted,
            'İmleçli tamamlanan listesi mevcut belgeleri korumalı.');
        foreach (['123456-78901','camasir makinesi','IŞIL OZTURK','Ozturk Makinesi'] as $query) {
            $search=$queue->page(1,$kind,'pending',2,10,$query);
            check(array_column($search['items'],'id')===[$partial] && $search['page']===0
                && $search['total']===1 && $search['pendingTotal']===13,
                'Arama tüm sayfaları, özgün sipariş numarasını ve Türkçe eşleşmeyi kapsamalı.');
        }
        foreach (['Sadece Diğer Mağaza','05001234567'] as $query) {
            check($queue->page(1,$kind,'pending',0,10,$query)['total']===0,'Arama firma ve alan kapsamını aşmamalı.');
        }
        $done=$queue->page(1,$kind,'completed',0,10,'belge musterisi');
        check(array_column($done['items'],'id')===[$completed[$kind][0]],'Tamamlanan belge araması kendi sekmesiyle sınırlı.');
        check($queue->page(1,$kind,'completed',0,10,'gizli belge urunu')['total']===($customer?0:1),
            'Arama mevcut müşteri görünürlüğünü korumalı.');
    }
}

$db->run("UPDATE shipment_entries SET row_status='cancelled', cancelled=1 WHERE id=?", [$partial]);
$queue = new ShipmentDocumentQueue($db, $cipher);
try{
    $queue->page(1,'invoice','pending',0,10,str_repeat('a',121));
    throw new RuntimeException('Arama uzunluk sınırı uygulanmadı.');
}catch(HttpError $error){
    check($error->getCode()===400,'Geçersiz arama uygun hatayı dönmeli.');
}
check($queue->pendingCounts(1) === ['invoice' => 12, 'delivery' => 12], 'Sonradan iptal edilen satır bir sonraki okumada iki sayaçtan da çıkmalı.');
foreach (array_keys($expected) as $kind) {
    check(!in_array($partial, array_column($queue->page(1, $kind, 'pending', 0, 50)['items'], 'id'), true),
        'Sonradan iptal edilen satır bekleyen listesinde kalmamalı.');
}
echo "Bekleyen belge listeleri, sayaçlar, sayfalama ve mevcut belge erişimi doğrulandı.\n";

$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$actions=new ShipmentDocumentActions($db,$cipher);
$admin=['id'=>1,'role'=>'admin'];
$client=['id'=>2,'role'=>'client','company_id'=>1];
$selected=static fn(int $id,int $version=1):array=>['id'=>$id,'version'=>$version];
$action=static fn(string $kind,string $bucket,string $type,array $items):array=>['kind'=>$kind,'bucket'=>$bucket,'action'=>$type,'items'=>$items];
$fails=static function(callable $operation,int $status):void{
    try{$operation();throw new RuntimeException('İşlem reddedilmedi.');}
    catch(HttpError $error){check($error->getCode()===$status,'Belge işlemi beklenen hata kodunu vermeli.');}
};
$order=$active[1];
$key='fixture-live-order';
$db->run('UPDATE shipment_entries SET source_key=? WHERE id=?',[hash('sha256','Hepsiburada:'.$key),$order]);
$fails(fn()=>$actions->apply($client,1,$action('delivery','pending','deliver',[$selected($order)])),403);
$fails(fn()=>$actions->apply($admin,1,$action('invoice','pending','deliver',[$selected($order),$selected($other)])),409);
check((int)$db->one('SELECT version FROM shipment_entries WHERE id=?',[$order])['version']===1,'Başka mağaza satırıyla toplu işlem bütünüyle durmalı.');
$fails(fn()=>$actions->apply($admin,1,$action('delivery','pending','deliver',[$selected($order)])),400);
check($actions->apply($admin,1,$action('invoice','pending','deliver',[$selected($order)]))['updated']===1,'Yönetici satırı teslim edildi işaretleyebilir.');
$orderFrom=static fn(ShipmentDocumentQueue $queue,string $kind,int $id)=>array_column($queue->page(1,$kind,'pending',0,50)['items'],null,'id')[$id]??null;
$customerQueue=new ShipmentDocumentQueue($db,$cipher,true);
foreach([$queue,$customerQueue] as $reader)foreach(['invoice','delivery'] as $kind){
    $item=$orderFrom($reader,$kind,$order);
    check($item['delivered']&&$item['deliveredManually']&&!$item['documents'][$kind],'Manuel işaret iki listede görünmeli ve belge yüklemesini tamamlamamalı.');
}
check($db->one('SELECT row_status,removed_at FROM shipment_entries WHERE id=?',[$order])===['row_status'=>'','removed_at'=>null],
    'Belge işareti sevkiyatın kaynak durumuna yazmamalı.');
$fails(fn()=>$actions->apply($admin,1,$action('invoice','pending','remove',[$selected($active[2]),$selected($order)])),409);
check($orderFrom($queue,'invoice',$active[2])!==null,'Eski sürüm içeren toplu kaldırma hiçbir satırı gizlememeli.');
check($actions->apply($admin,1,$action('invoice','pending','remove',[$selected($order,2)]))['updated']===1,'Belge listesinden kaldırma uygulanır.');
check($orderFrom($queue,'invoice',$order)===null&&$orderFrom($customerQueue,'delivery',$order)['delivered'],
    'Kaldırma yalnız seçili belge türünü iki portalda etkiler.');
check($queue->pendingCounts(1)===['invoice'=>11,'delivery'=>12],'Belge sayaçları seçili listeyi izlemeli.');
$fails(fn()=>$actions->apply($admin,1,$action('delivery','completed','remove',[$selected($deliveryOnActive)])),400);
$record=$db->one('SELECT payload,removed_at FROM shipment_entries WHERE id=?',[$deliveryOnActive]);
check($record['removed_at']===null&&isset($cipher->open(1,'shipment:'.$deliveryOnActive,$record['payload'])['documents']['delivery']),
    'Listeden kaldırma siparişi veya belgeyi silmemeli.');
$ingestor=new ShipmentOrderIngestor($db,$cipher);
$incoming=['key'=>$key,'payload'=>['province'=>'Ankara','orderedAt'=>$date,'orderNumber'=>'Örnek',
    'quantity'=>3,'productName'=>'Aktarımdan yenilenen ürün','marketplaceShipping'=>'shipped']];
$db->transaction(fn()=>$ingestor->ingest(1,'Hepsiburada',[$incoming]));
$after=$orderFrom($customerQueue,'delivery',$order);
check($after['productName']==='Aktarımdan yenilenen ürün'&&$after['deliveredManually']&&$orderFrom($queue,'invoice',$order)===null,
    'Yeniden canlı aktarım manuel işareti ve liste seçimini koruyup kaynak alanlarını günceller.');
echo "Belge işlemleri: yetki, sürüm, firma, tek listeye kaldırma, belge koruması ve sonraki aktarım doğrulandı.\n";

$pazarama = $add([
    'orderNumber' => 'PAZ-TEST', 'quantity' => 1, 'invoiceCents' => 1004900,
    'marketplaceAmounts' => ['productCents'=>1024900, 'sellerDiscountCents'=>20000,
        'platformDiscountCents'=>61494, 'netProductCents'=>943406],
]);
$db->run('UPDATE shipment_entries SET provider=?, source_key=? WHERE company_id=? AND id=?',
    ['Pazarama', hash('sha256','Pazarama:queue-line'), 1, $pazarama]);
foreach([$queue,$customerQueue] as $reader){
    $item=$orderFrom($reader,'invoice',$pazarama);
    check($item['invoiceCents']===943406,'Bekleyen Pazarama faturasında iki indirimden sonraki 9.434,06 TL görünmeli.');
}
