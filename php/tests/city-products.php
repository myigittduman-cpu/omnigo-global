<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CityProductOrders, CityProducts, Database, DeliveryCipher, DeliveryMapper, DeliveryRepository, DeliverySchema,
    HttpError, NotificationSchema, Security, ShipmentOrderIngestor, ShipmentRoutes, ShipmentSchema};

function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function fails(callable $read, int $code): void
{
    try { $read(); }
    catch (HttpError $error) { check($error->getCode() === $code, $error->getMessage()); return; }
    throw new RuntimeException('Geçersiz işlem kabul edildi.');
}

function product(string $sku, string $state = 'pending', int $quantity = 1, array $extra = []): array
{
    return [...['lineId'=>$sku,'sku'=>$sku,'sellerStockCode'=>$sku,'title'=>'Ürün '.$sku,'shippingState'=>$state,'quantity'=>$quantity], ...$extra];
}

function package(string $number, string $city, array $products, array $fields = []): array
{
    $date = (new DateTimeImmutable('today', new DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM);
    $value = [...[
        'provider'=>'Hepsiburada','externalId'=>$number,'orderNumber'=>$number,'city'=>$city,'district'=>'Merkez',
        'buyerName'=>'Fatura alıcısı','recipientName'=>'Alıcı '.$number,'phone'=>'05000000001','address'=>'Adres '.$number,
        'createdAt'=>$date,'status'=>'Hazırlanıyor','active'=>true,'products'=>$products,
    ], ...$fields];
    $value['details'] = [
        'products'=>$products,'recipientName'=>$value['recipientName'],'phone'=>$value['phone'],
        'deliveryAddress'=>$value['address'],'province'=>$city,'district'=>$value['district'],'orderedAt'=>$value['createdAt'],
        'invoiceName'=>'Fatura alıcısı','invoiceAddress'=>'Sentetik fatura adresi',
    ];
    return DeliveryMapper::finish($value);
}

function referenceLines(DeliveryRepository $delivery, int $company, string $city): array
{
    $list = $delivery->listing($company, $city, 'all', 'all', 0, 0, true);
    $lines = [];
    foreach ($list['items'] as $order) {
        foreach ($order['products'] as $item) $lines[] = [$order['selectionKey'], $item['sku'], $item['title'], $item['quantity']];
    }
    sort($lines);
    return $lines;
}

function productPages(CityProducts $products, int $company, string $city): array
{
    $rows = [];
    for ($page = 0; ; $page++) {
        $next = $products->listing($company, ['city'=>$city,'page'=>$page]);
        check($next['page'] === $page && $next['pageSize'] === 10, 'Ürün sayfası ve boyutu');
        check(count($next['items']) <= 10 && (!$next['hasMore'] || count($next['items']) === 10), 'Sayfa erken kesildi.');
        array_push($rows, ...$next['items']);
        if (!$next['hasMore']) {
            check(count($rows) === $next['total'], 'Ürün kalemi sayacı');
            break;
        }
        check(count($rows) < $next['total'], 'Son sayfa işareti');
    }
    check(count(array_unique(array_column($rows, 'id'))) === count($rows), 'Sayfalarda ürün kimliği tekrarlanmamalı.');
    return $rows;
}

function compareCity(DeliveryRepository $delivery, CityProducts $products, int $company, string $city): array
{
    $rows = productPages($products, $company, $city);
    $actual = array_map(static fn(array $row): array => [$row['orderKey'], $row['sku'], $row['productName'], $row['quantity']], $rows);
    sort($actual);
    check($actual === referenceLines($delivery, $company, $city), $city.' ürünleri ve adetleri teslimat listesinden ayrıldı.');
    return $rows;
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);
DeliverySchema::install($db);
NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Mağaza A'),(2,'Mağaza B')");
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','','admin',NULL),(2,'store@example.invalid','Mağaza','','client',1)");
$cities = ['Kayseri','Kırşehir','Nevşehir','Eskişehir','Kırıkkale'];
$profile = ['deliveryCities'=>$cities];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?),(2,?)', [
    json_encode($profile), json_encode(['deliveryCities'=>['Kayseri']]),
]);
$cipher = DeliveryCipher::withKey(str_repeat('c',32));
$delivery = new DeliveryRepository($db, $cipher);
$products = new CityProducts($db, $cipher);
foreach ([1,2] as $company) ShipmentOrderIngestor::activate($db, $company, time()-86400);
$save = fn(array $order, int $company = 1) => $db->transaction(fn() => $delivery->ingest($company, $order, false));

$mixed = package('MIX-1', 'Kayseri', [
    product('00014', 'pending', 5, ['cancelledQuantity'=>2,'refundedQuantity'=>1]),
    product('00015', 'shipped'), product('00015', 'shipped', 1, ['lineId'=>'another-line']),
    product('TESLIM', 'delivered'), product('IPTAL', 'cancelled'), product('IADE', 'returned'),
]);
$save($mixed);
$save($mixed);
$plain=$products->listing(1,['group'=>'order','city'=>'Kayseri']);
$marks=new CityProducts($db,$cipher,true);
$marked=$marks->listing(1,['group'=>'order','city'=>'Kayseri']);
$printed=$marks->listing(1,['group'=>'order','city'=>'Kayseri','export'=>'1']);
check($marked['items'][0]['cancellationMark']==='partial'&&!isset($plain['items'][0]['cancellationMark']),'Şehir dışı ürünlerde işaret seçilen görünümde çıkmalıdır.');
unset($marked['items'][0]['cancellationMark']);
check($marked['items']===$plain['items']&&$plain['items']===$printed['items'],'Renk işareti sipariş kimliği, ürün, adet ve dışa aktarımı değiştirmemeli.');
$deliveryView=$delivery->listing(1,'Kayseri','all','all',0,10,true,['showCancellationMark'=>true]);
check($deliveryView['items'][0]['cancellationMark']==='partial','Teslimatta kalan ürünlü sipariş kısmi iptal olarak işaretlenmeli.');
check(!array_intersect(['IPTAL','IADE'],array_column($deliveryView['items'][0]['products'],'sku')),'Kapanan satırlar geri dönmemeli.');
$removed=package('REMOVED','Kayseri',[product('SILINMIS')]);
$removed['shipmentItems']=[[
    'key'=>hash('sha256',json_encode(['REMOVED','SILINMIS'],JSON_THROW_ON_ERROR)),
    'payload'=>['orderedAt'=>$removed['createdAt'],'province'=>'Kayseri','customerName'=>'Sipariş alıcısı',
        'recipientName'=>'Teslim alacak kişi','quantity'=>1,'productName'=>'Ürün SILINMIS','invoiceCents'=>10000,
        'marketplaceShipping'=>'pending'],
]];
$save($removed);
$key=hash('sha256','Hepsiburada:'.$removed['shipmentItems'][0]['key']);
check($db->one('SELECT id FROM shipment_entries WHERE company_id=1 AND source_key=?',[$key])!==null, 'Silme testi için aktarılan bağlantılı kalem');
$db->run('UPDATE shipment_entries SET removed_at=? WHERE company_id=1 AND source_key=?',[gmdate('c'),$key]);
$save($removed);
$save(package('MIX-1', 'Kayseri', [product('EK-PAKET')], [
    'externalId'=>'MIX-1-B','recipientName'=>'İkinci alıcı','address'=>'İkinci adres','district'=>'Talas','phone'=>'05000000002',
]));
$save(package('MIX-1', 'Kayseri', [product('MAGAZA-B')]), 2);
$save(package('CHANNEL', 'Kayseri', [product('DIGER-MAGAZA-KANALI')], ['provider'=>'idefix']), 2);
$save(package('INACTIVE', 'Kayseri', [product('PASIF')], ['active'=>false]));
$save(package('REVIEW', 'Kayseri', [product('EKSIK')], ['phone'=>'']));
$save(package('OTHER', 'Ankara', [product('ANKARA')]));
$save(package('HIDDEN', 'Kayseri', [product('GIZLI')], ['provider'=>'Teknosa']));
$save(package('CHANNEL', 'Kayseri', [product('MAGAZADA-GIZLI')], ['provider'=>'idefix']));
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[
    json_encode(['name'=>'idefix','hidden'=>true]),gmdate('c'),
]);

// A local shipment and an imported orphan cannot create an order in the delivery planning screen.
$db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated)
    VALUES(1,'Kayseri','n11',?,'',?,?)", [gmdate('Y-m'),gmdate('c'),gmdate('c')]);
$manual = $db->insertId();
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?', [
    $cipher->seal(1,'shipment:'.$manual,['quantity'=>1,'productName'=>'Elle eklenen sevkiyat','marketplaceShipping'=>'pending']),
    $manual,
]);
$orphan = ['orderedAt'=>gmdate('c'),'province'=>'Kayseri','customerName'=>'Örnek','recipientName'=>'Örnek Alıcı',
    'productName'=>'İlişkisiz kalem','quantity'=>1,'invoiceCents'=>10000,'marketplaceShipping'=>'pending'];
$db->transaction(fn() => (new ShipmentOrderIngestor($db,$cipher))->ingest(1,'n11',[['key'=>'unlinked','payload'=>$orphan]]));

foreach (array_slice($cities,1) as $index => $city) $save(package('CITY-'.$index, $city, [product('CITY-'.$index)]));
for ($i=0; $i<251; $i++) $save(package('PAGE-'.$i, 'Nevşehir', [product('P-'.$i)]));
$largeOrder=[];
for ($i=0; $i<12; $i++) $largeOrder[]=product('B-'.$i);
$save(package('MULTI-PAGE','Nevşehir',$largeOrder));
$delayed = package('OLD','Kırşehir',[product('GECIKEN')],['createdAt'=>(new DateTimeImmutable('-8 days'))->format(DATE_ATOM)]);
$save($delayed);
check(in_array('OLD',array_column($delivery->listing(1,'Kırşehir','all','delayed',0,10,true)['items'],'orderNumber'),true), 'Geciken sipariş örneği');
$beforeRead = $db->all('SELECT id,version,payload FROM delivery_orders ORDER BY id');
foreach ($cities as $city) compareCity($delivery,$products,1,$city);
$groupQuery=['city'=>'Kayseri','group'=>'order'];
$grouped=$products->listing(1,$groupQuery);
check($grouped['total']===1 && count($grouped['items'])===1,'Aynı siparişin kalemleri ve paketleri bir satır olmalı.');
$group=$grouped['items'][0];
check($group['quantity']===5 && count($group['deliveries'])===2,'Tüm aktif adetler ve iki teslimat noktası');
$byRecipient=array_column($group['deliveries'],null,'recipientName');
$firstRecipient=array_column($byRecipient['Alıcı MIX-1']['products'],null,'sku');
check($firstRecipient['00014']['quantity']===2 && $firstRecipient['00015']['quantity']===2
    && count($firstRecipient)===2,'Aynı ürün adetleri toplanmalı, kısmi iptal/iade düşülmeli.');
check($byRecipient['İkinci alıcı']['address']==='İkinci adres'
    && array_column($byRecipient['İkinci alıcı']['products'],'sku')===['EK-PAKET'],'Diğer paketin ürünleri kendi adresine bağlı kalmalı.');
$reference=$delivery->listing(1,'Kayseri','all','all',0,0,true);
check($group['orderKey']===$reference['items'][0]['selectionKey'],'Teslimat ve ürün sayfası aynı siparişi göstermeli.');
$groupedLast=$products->listing(1,['group'=>'order','city'=>'Nevşehir','page'=>999]);
check($groupedLast['page']===25 && count($groupedLast['items'])===3 && $groupedLast['total']===253,'Sayfalar on sipariş üzerinden işlemeli.');
$groupedFirst=$products->listing(1,['group'=>'order','city'=>'Nevşehir','page'=>0]);
$exportNevsehir=$products->listing(1,['group'=>'order','city'=>'Nevşehir','export'=>'1']);
$largeOrders=array_filter($exportNevsehir['items'],static fn($item)=>count($item['deliveries'][0]['products'])===12 && $item['quantity']===12);
check(count($groupedFirst['items'])===10 && count($largeOrders)===1 && $exportNevsehir['total']===253
    && $groupedFirst['items']===array_slice($exportNevsehir['items'],0,10)
    && $groupedLast['items']===array_slice($exportNevsehir['items'],-3),'On iki ürünlü sipariş bölünmemeli, tüm çıktı sırası tabloyla eşleşmeli.');
$all=$products->listing(1,['group'=>'order','export'=>'1']);
check($all['city']==='all' && !in_array('Ankara',array_merge(...array_map(static fn($item)=>array_column($item['deliveries'],'city'),$all['items'])),true)
    && $all['total']===258,'İlk açılış tüm yetkili illeri kapsamalı, gizli il ve kontrol kayıtları eklenmemeli.');
check($products->listing(1,$groupQuery+['ifRevision'=>$grouped['revision']])===['unchanged'=>true,'revision'=>$grouped['revision']],
    'Gruplanmış koşullu okuma');
check(isset($products->listing(1,['city'=>'Kayseri','ifRevision'=>$grouped['revision']])['items']),'Ürün ve sipariş revizyonları ayrılmalı.');
$rows = productPages($products,1,'Kayseri');
$allowedFields=['id','orderKey','orderedAt','recipientName','phone','address','city','district','sku','productName','quantity'];
$actualFields=array_keys($rows[0]);
sort($actualFields);
sort($allowedFields);
check(count($rows)===4 && $actualFields===$allowedFields,
    'İki paket, aynı SKU içeren ayrı kalemler ve yalnızca tablo alanları');
$bySku = array_column($rows,null,'sku');
check($bySku['00014']['quantity']===2 && $bySku['00014']['recipientName']==='Alıcı MIX-1', 'Kısmi iptal, iade ve teslim alacak kişi');
check($bySku['EK-PAKET']['recipientName']==='İkinci alıcı' && $bySku['EK-PAKET']['address']==='İkinci adres'
    && $bySku['EK-PAKET']['district']==='Talas' && $bySku['EK-PAKET']['phone']==='05000000002', 'Aynı siparişte her paketin kendi adresi ve alıcısı');
$storeCodes=array_column(compareCity($delivery,$products,2,'Kayseri'),'sku');
sort($storeCodes);
check($storeCodes===['DIGER-MAGAZA-KANALI','MAGAZA-B'], 'Sipariş ve gizlenen kanal ayarı mağazalar arasında ayrılmalı.');
check($products->listing(2,$groupQuery)['total']===2, 'Gruplama diğer mağazanın teslimatlarını almamalı.');
check($db->all('SELECT id,version,payload FROM delivery_orders ORDER BY id')===$beforeRead, 'Liste okuması siparişleri değiştirdi.');
$last=$products->listing(1,['city'=>'Nevşehir','page'=>50]);
check($last['page']===26 && count($last['items'])===4 && !$last['hasMore'], 'Son ürün sayfasına sınırlama');
$first=$products->listing(1,[]);
check($first['cities']===$cities && $first['city']==='Kayseri','Beş il ve varsayılan il');
check($products->listing(1,['ifRevision'=>$first['revision']])===['unchanged'=>true,'revision'=>$first['revision']], 'Koşullu okuma');
fails(fn()=>$products->listing(2,['city'=>'Kırıkkale','ifRevision'=>$first['revision']]),403);
fails(fn()=>$products->listing(1,['city'=>'Ankara']),403);
fails(fn()=>$products->listing(1,['city'=>'Diğer / Kontrol']),400);
fails(fn()=>$products->listing(1,['page'=>-1]),400);
$fixed = package('REVIEW','Kayseri',[product('EKSIK')],['phone'=>'','manualCorrections'=>['phone'=>'05000000003','city'=>'Kırıkkale']]);
$save($fixed);
check(in_array('EKSIK',array_column(compareCity($delivery,$products,1,'Kırıkkale'),'sku'),true), 'Düzeltilen kayıt ilgili şehre geçer.');
$save(package('MIX-1','Kayseri',[product('00014','delivered'),product('00015','delivered')]));
$changed = $products->listing(1,['ifRevision'=>$first['revision']]);
check($changed['revision']!==$first['revision'] && array_column($changed['items'],'sku')===['EK-PAKET'],
    'Canlı durum değişince teslim edilen kalemler çıkar ve aynı siparişin diğer paketi kalır.');
check($changed['items'][0]['id']===$bySku['EK-PAKET']['id'], 'Diğer paketin canlı güncellemesi kalan ürünün kimliğini değiştirmemeli.');
compareCity($delivery,$products,1,'Kayseri');
$updatedGroup=$products->listing(1,$groupQuery+['ifRevision'=>$grouped['revision']]);
check($updatedGroup['items'][0]['id']===$group['id'] && $updatedGroup['items'][0]['quantity']===1
    && $updatedGroup['items'][0]['deliveries'][0]['id']===$byRecipient['İkinci alıcı']['id'],
    'Teslimat güncellenince kalan sipariş ve adres kimliği korunmalı.');

// Late packages may belong to earlier pages. Identical visible SKUs may also have distinct variants.
$packages=[];
foreach(['A','B','C','A','C','C'] as $index=>$key){
    $contact=['orderKey'=>$key,'orderedAt'=>'2026-11-10','recipientName'=>'Alıcı','address'=>'Adres','city'=>'Kayseri','phone'=>'','district'=>''];
    $packages[]=[$contact,[['rowKey'=>(string)$index,'sellerStockCode'=>'SKU','sku'=>'catalog','title'=>'Ürün','quantity'=>1,
        'sourceProduct'=>['variantId'=>$index===5?'iki':'bir']]],$index+1];
}
$read=static fn(?array $ids=null)=>$ids===null?$packages:array_filter($packages,static fn($p)=>in_array($p[2],$ids,true));
[$count,$orderPage]=CityProductOrders::page($read,0,1);
check($count===3 && $orderPage[0]['id']==='A' && $orderPage[0]['deliveries'][0]['products'][0]['quantity']===2,
    'Arada başka sayfalar olsa da ilk sayfanın paketi eksilmemeli.');
[, $orderPage]=CityProductOrders::page($read,99,1);
$variants=$orderPage[0]['deliveries'][0]['products'];
check($orderPage[0]['id']==='C' && array_column($variants,'quantity')===[2,1] && $variants[0]['id']!==$variants[1]['id'],
    'Son geçerli sayfa ve aynı SKU farklı varyant kimlikleri');
$dated=[];
foreach([['a','2026-11-19T21:30:00Z','Kayseri'],['b','2026-11-20T09:00:00+03:00','Eskişehir'],
    ['c','2026-11-19T20:59:00Z','Ankara'],['d','2026-11-20T02:34:00+03:00','Kayseri'],
    ['e','2026-11-20T12:00:00+03:00','Kırşehir'],['f','2026-11-20T03:45:00+03:00','Kırıkkale']] as $tuple){
    [$key,$date,$city]=$tuple;
    $dated[]=[['orderKey'=>$key,'orderedAt'=>$date,'city'=>$city],$packages[0][1],count($dated)+1];
}
[, $dateSorted]=CityProductOrders::page(static fn()=>$dated,0,10,true);
check(array_column($dateSorted,'id')===['b','d','a','f','e','c'],'İstanbul gününe, Türkçe il adına ve şehir içinde saate göre sıralanmalı.');
$sortedFixture=$dated;
foreach(['ÖZ','IŞIK','ÇINAR','İPEK','Cem','Şule'] as $i=>$name)$sortedFixture[$i][0]['recipientName']=$name;
$sortedFixture[]=[['orderKey'=>'a','orderedAt'=>'2026-11-20T01:00:00+03:00','city'=>'Adana','recipientName'=>'Çınar'],$packages[0][1],7];
$sortedFixture[]=[['orderKey'=>'g','orderedAt'=>'','city'=>'Osmaniye','recipientName'=>''],$packages[0][1],8];
$sortedFixture[]=[['orderKey'=>'bb','orderedAt'=>'2026-11-20T06:00:00Z','city'=>'Eskişehir','recipientName'=>'ışık'],$packages[0][1],9];
$readSorted=static fn(?array $ids=null)=>$ids===null?array_reverse($sortedFixture):array_filter($sortedFixture,static fn($p)=>in_array($p[2],$ids,true));
foreach([
    'newest'=>['e','b','bb','f','d','a','c','g'],
    'oldest'=>['c','a','d','f','b','bb','e','g'],
    'city'=>['a','c','b','bb','d','f','e','g'],
    'recipient'=>['e','a','c','b','bb','d','f','g'],
] as $sort=>$expected){
    [, $complete]=CityProductOrders::page($readSorted,0,3,true,$sort);
    check(array_column($complete,'id')===$expected,'Tam liste sıralaması: '.$sort);
    foreach(array_chunk($expected,3) as $page=>$slice){
        [, $window]=CityProductOrders::page($readSorted,$page,3,false,$sort);
        check(array_column($window,'id')===$slice,'Sayfa sınırında kararlı sıra: '.$sort);
    }
}
$default=$products->listing(1,['group'=>'order']);
foreach(['newest','oldest','city','recipient'] as $sort){
    $query=['group'=>'order','sort'=>$sort];
    $ordered=$products->listing(1,$query+['page'=>1,'ifRevision'=>$default['revision']]);
    $exported=$products->listing(1,$query+['export'=>'1']);
    check($ordered['sort']===$sort&&$exported['sort']===$sort&&$ordered['items']===array_slice($exported['items'],10,10),
        'İkinci sayfa çıktıdaki aynı on siparişi içermeli: '.$sort);
    check($products->listing(1,$query+['page'=>1,'ifRevision'=>$ordered['revision']])['unchanged']===true,
        'Aynı sıralamanın revizyonu kabul edilmeli.');
    check(isset($products->listing(1,$query+['ifRevision'=>$default['revision']])['items']),'Farklı sıralama eski revizyonu kullanmamalı.');
}
fails(fn()=>$products->listing(1,['group'=>'order','sort'=>['city']]),400);
fails(fn()=>$products->listing(1,['group'=>'order','sort'=>'xyz','export'=>'1']),400);
fails(fn()=>$products->listing(1,['sort'=>'oldest']),400);

$setProfile = fn(array $newProfile) => $db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($newProfile)]);
$setProfile(['deliveryCities'=>['Ankara',...$cities]]);
check($products->listing(1,[])['cities']===[...$cities,'Ankara'], 'Beş öncelikli il ve mağazanın ek teslimat ili');
check(array_column(compareCity($delivery,$products,1,'Ankara'),'sku')===['ANKARA'], 'Mağaza kapsamına eklenen ilde eşleşme');
check($products->listing(1,['group'=>'order','city'=>'Ankara','export'=>'1'])['total']===1,'Kapsama eklenen Ankara filtresi canlı kaydı göstermeli.');
$save(package('MULTI-CITY','Ankara',[product('ANK-ONLY')],['externalId'=>'package-city-a','createdAt'=>'2026-11-18T03:45:00+03:00']));
$save(package('MULTI-CITY','Kayseri',[product('KAY-ONLY')],['externalId'=>'package-city-b','createdAt'=>'2026-11-18T10:00:00+03:00']));
$save(package('MULTI-CITY','Ankara',[product('DIFFERENT-MARKET')],['provider'=>'n11','externalId'=>'n11-other']));
$crossKey=hash('sha256',json_encode(['Hepsiburada','MULTI-CITY'],JSON_THROW_ON_ERROR));
$cross=$products->listing(1,['group'=>'order','export'=>'1']);
$crossRow=array_column($cross['items'],null,'id')[$crossKey];
check($crossRow['quantity']===2 && $crossRow['orderedAt']==='2026-11-18T03:45:00+03:00' && count($crossRow['deliveries'])===2,
    'İki şehre teslim edilen bir sipariş Tüm İllerde tek satır, iki adres olmalı; aynı numaralı farklı pazaryeri birleşmemeli.');
foreach(['Ankara'=>'ANK-ONLY','Kayseri'=>'KAY-ONLY'] as $city=>$sku){
    $key=hash('sha256',json_encode(['Hepsiburada','MULTI-CITY',$city],JSON_THROW_ON_ERROR));
    $byCity=$products->listing(1,['group'=>'order','city'=>$city,'export'=>'1']);
    $cityRow=array_column($byCity['items'],null,'id')[$key];
    check($cityRow['quantity']===1 && $cityRow['deliveries'][0]['products'][0]['sku']===$sku,
        'İl görünümünde yalnız o ildeki paket kalmalı.');
}
$setProfile(['deliveryCities'=>[]]);
fails(fn()=>$products->listing(1,['city'=>'Kayseri','ifRevision'=>$first['revision']]),403);
$empty=$products->listing(1,[]);
check($empty['city']===null && $empty['cities']===[] && $empty['items']===[], 'Boş teslimat kapsamı');
$emptyAll=$products->listing(1,['group'=>'order','city'=>'all','export'=>'1']);
check($emptyAll['total']===0 && !$emptyAll['items'],'Boş kapsamda tüm iller hiçbir satır döndürmemeli.');
$routes=new ShipmentRoutes($db,new Security($db,[]),$cipher);
$admin=['id'=>1,'role'=>'admin'];
$client=['id'=>2,'role'=>'client','company_id'=>1];
$_GET=['company'=>2];
fails(fn()=>$routes->handle('/api/city-products','GET',$client),403);
fails(fn()=>$routes->handle('/api/city-products','POST',$client),405);
$setProfile(['workspacePolicy'=>['pages'=>['cityProducts'=>'removed']]]);
$_GET=['company'=>1,'group'=>'order'];
foreach ([$client,$admin] as $actor) fails(fn()=>$routes->handle('/api/city-products','GET',$actor),403);
$setProfile(['deliveryCities'=>['Kayseri'],'cityProductsConfigured'=>true,'setupOptions'=>['live'=>['cityProducts'],'manual'=>[]],
    'workspacePolicy'=>['pages'=>['shipments'=>'removed','deliveries'=>'removed','cityProducts'=>'shown'],'users'=>['2'=>['cityProducts'=>['view'=>true]]]]]);
$_GET=['company'=>1,'group'=>'order','export'=>'1'];
fails(fn()=>$routes->handle('/api/city-products','GET',$client),403);
unset($_GET['export']);
ob_start();
register_shutdown_function(static function(): void {
    $response=json_decode(ob_get_clean(),true,32,JSON_THROW_ON_ERROR);
    $sku=[];
    foreach($response['items']??[] as $row)foreach($row['deliveries'] as $delivery)array_push($sku,...array_column($delivery['products'],'sku'));
    sort($sku);
    check($sku===['EK-PAKET','KAY-ONLY'],
        'Ürün menüsü yetkisi bağımsızken aynı teslimat seçimi ve gruplanmış HTTP yanıtı');
    echo "Beş şehirde teslimat ve ürün kalemleri, canlı güncelleme, paket adresleri, sayfalama, yetki ve mağaza yalıtımı doğrulandı.\n";
});
$routes->handle('/api/city-products','GET',($argv[1]??'')==='--admin' ? $admin : $client);
