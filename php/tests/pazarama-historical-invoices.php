<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, DeliveryCipher, DeliveryFulfillment, DeliverySchema, ManagementSchema, OrderDetails, OrderList, RecentOrderSchema, ShipmentDocumentQueue, ShipmentRepository, ShipmentSchema};

function expectAmounts(array $actual, array $expected, string $context): void
{
    ksort($actual);
    ksort($expected);
    if ($actual !== $expected) throw new RuntimeException($context.' '.json_encode([$actual, $expected], JSON_THROW_ON_ERROR));
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);
ShipmentSchema::install($db);
ManagementSchema::install($db);
RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$cipher = DeliveryCipher::withKey(str_repeat('h', 32));
$shipments = new ShipmentRepository($db, $cipher);
$orders = new OrderList($db, new OrderDetails($db, $cipher));
$queue = new ShipmentDocumentQueue($db, $cipher);
$admin = ['id'=>1, 'role'=>'admin'];

// Each tenant uses the same order and line IDs, with different discounts.
$cases = [
    ['financial-only','2025-08-15',100000,10000,false],
    ['old-snapshot','2026-06-15',120000,8000,true],
    ['coupon-in-october','2026-10-15',4234500,212725,true],
    ['november','2026-11-19',1004900,61494,true],
];
$expected = [];
foreach ([1,2] as $company) {
    foreach ($cases as [$external, $day, $gross, $discount, $hasPackage]) {
        $paid = $gross - $discount * $company;
        $expected[$company][$external] = $paid;
        $date = $day.'T10:00:00Z';
        $line = ['lineId'=>'L1','sku'=>'SKU','quantity'=>1,'title'=>'Test ürünü','grossCents'=>$gross,
            'pricing'=>['originalCents'=>$gross+20000,'sellerDiscountCents'=>20000,
                'platformDiscountCents'=>$discount*$company,'platformDiscountReported'=>true,
                'reportCents'=>$gross,'reportBasis'=>'seller_invoice','discountedCents'=>$paid]];
        $financial = ['externalId'=>$external,'createdAt'=>$date,'orders'=>1,'state'=>'Aktarıldı',
            'grossCents'=>$gross,'cancelCents'=>0,'returnCents'=>0,'products'=>[$line]];
        $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
            [$company,'order_pazarama',json_encode($financial, JSON_THROW_ON_ERROR),$date]);
        if (!$hasPackage) continue;
        // Historical encrypted delivery and shipment both carry the old seller invoice.
        $oldLine = ['lineId'=>'L1','sku'=>'SKU','quantity'=>1,'title'=>'Test ürünü','amountCents'=>$gross];
        $delivery = ['provider'=>'Pazarama','externalId'=>$external,'orderNumber'=>$external,'city'=>'Ankara',
            'buyerName'=>'Test','phone'=>'','address'=>'','createdAt'=>$date,'details'=>['products'=>[$oldLine]]];
        $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,1,?,?,?)',
            [$company,'Pazarama',$external,'Ankara',$cipher->seal($company,'order:Pazarama:'.$external,$delivery),'test',$date]);
        $db->run("INSERT INTO shipment_entries(company_id,provider,city,month,ordered_at,payload,source_key,created,updated) VALUES(?,'Pazarama','Ankara',?,?,'',?,?,?)",
            [$company,substr($day,0,7),$date,DeliveryFulfillment::key($delivery,$oldLine),$date,$date]);
        $id = $db->insertId();
        $payload = ['sourceDeliveryId'=>$external,'source'=>['kind'=>'marketplace','provider'=>'Pazarama'],
            'orderNumber'=>$external,'orderedAt'=>$date,'productName'=>'Test ürünü','quantity'=>1,'invoiceCents'=>$gross];
        $db->run('UPDATE shipment_entries SET payload=? WHERE id=?', [$cipher->seal($company,'shipment:'.$id,$payload),$id]);
    }
}

$before = $db->all('SELECT id,version,payload,updated FROM shipment_entries ORDER BY id');
foreach ([1,2] as $company) {
    $view = $orders->listing($admin,$company,['provider'=>'pazarama'],true);
    expectAmounts(array_column($view['items'],'invoiceCents','externalId'),$expected[$company],'Tüm tarihler ve paketsiz geçmiş sipariş');
    foreach ($cases as [$external,$day,,,$hasPackage]) {
        if (!$hasPackage) continue;
        $selection = ['all','Pazarama',substr($day,0,7)];
        $orderAmount = [$external=>$expected[$company][$external]];
        $list = $shipments->listing($company,$selection,invoice:true,includePaymentDetails:true);
        expectAmounts(array_column($list['items'],'invoiceCents','orderNumber'),$orderAmount,'Aylık sevkiyat');
        $export = $shipments->export($company,$selection);
        expectAmounts(array_column($export['items'],'invoiceCents','orderNumber'),$orderAmount,'Aylık dışa aktarım');
    }
    $pending = $queue->listing($company,'invoice','pending',0);
    expectAmounts(array_column($pending['items'],'invoiceCents','orderNumber'),
        ['november'=>$expected[$company]['november']],'Kendi izleme tarihinden itibaren fatura kuyruğu');
}
if ($db->all('SELECT id,version,payload,updated FROM shipment_entries ORDER BY id') !== $before) {
    throw new RuntimeException('Geçmişi görüntülemek asıl sevkiyat satırlarını değiştirdi.');
}
echo "İki mağaza, dört ayrı dönem, eski sipariş ve teslimatlar, fatura kuyruğu ve aylık dışa aktarım doğrulandı.\n";
