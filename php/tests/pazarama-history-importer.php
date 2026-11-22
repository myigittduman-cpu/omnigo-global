<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,DeliverySchema,HistoricalSalesArchive,ManagementSchema,OrderDetails,OrderList,
    PazaramaHistoryImporter,PazaramaSalesMapper,RecentOrderSchema,ShipmentSchema,SyncFailure};

function verify(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function uuid(int $n): string { return sprintf('00000000-0000-4000-8000-%012d',$n); }
function price(string $value): array { return ['currency'=>'TL','value'=>$value]; }

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db); ShipmentSchema::install($db); RecentOrderSchema::install($db); ManagementSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));
$raw=[];$archived=[];
for ($i=1;$i<=34;$i++) {
    $month=$i<=9?'06':($i<=22?'07':($i<=33?'08':'09'));
    $line=['orderItemId'=>uuid(100+$i),'orderItemStatus'=>11,'quantity'=>1,
        'product'=>['name'=>'Örnek ürün','stockCode'=>'SKU'],'salePrice'=>price('10249'),
        'discountAmount'=>price('200'),'totalPrice'=>price('10049'),'discountDescription'=>'Kupon (Pazarama İndirimi - 614.94 TL)'];
    $order=['orderId'=>uuid($i),'orderDate'=>'2026-'.$month.'-10 11:30','currency'=>'TL','orderAmount'=>'10049',
        'orderNumber'=>'M-'.$i,'customerName'=>'Test müşteri','items'=>[$line]];
    $raw[]=$order;
    $source=PazaramaSalesMapper::order($order);
    unset($source['products'][0]['lineId']);
    $archived[]=$source;
}
$path=tempnam(sys_get_temp_dir(),'paza-archive-');
try {
    file_put_contents($path,json_encode(['version'=>1,'companyId'=>1,'companyName'=>'Örnek','providers'=>['pazarama'=>[
        'from'=>'2026-06-01T00:00:00+03:00','untilExclusive'=>'2026-10-01T00:00:00+03:00','orders'=>$archived]]],JSON_THROW_ON_ERROR));
    $service=new PazaramaHistoryImporter($db,$cipher,new HistoricalSalesArchive($db,$path));
    $expected=$service->expected(1,'2026-06-01','2026-09-01');
    verify(count($expected)===33&&$service->bounds(1)===['from'=>'2026-06-01','untilExclusive'=>'2026-10-01'],'Arşiv kapsamı ve sınır günü.');
    verify($service->expected(2,'2026-06-01','2026-09-01')===[],'Başka mağazanın arşivine erişilemez.');
    try {$service->page(2,$raw,$expected);throw new RuntimeException('Kapsam reddedilmeliydi.');} catch (SyncFailure $e) {verify($e->httpStatus===409,'Firma kapsamı.');}
    $bad=$raw[0];$bad['items'][0]['discountDescription']='Yetersiz kampanya bilgisi';
    verify($service->page(1,[$bad,$raw[33]],$expected)===0,'Kapsam dışı veya kanıtsız sipariş eklenmedi.');
    $prior=PazaramaSalesMapper::order($raw[0]);
    $prior['retainedField']='korunmalı';
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_pazarama',?,'eski')",[json_encode($prior,JSON_THROW_ON_ERROR)]);
    $before=$db->all("SELECT * FROM records ORDER BY id");
    verify($service->page(1,array_slice($raw,0,15),$expected)===15,'Birinci sayfa işlendi.');
    verify($service->coverage(1,$expected)===['total'=>33,'present'=>15,'missing'=>18],'İlerleme gerçek belgeli siparişlerden ölçüldü.');
    verify($service->page(1,array_slice($raw,8),$expected)===18,'Çakışan sayfada yalnız eksikler eklendi.');
    verify($service->coverage(1,$expected)===['total'=>33,'present'=>33,'missing'=>0],'Sipariş ve şifreli ayrıntıları eksiksiz.');
    $rows=$db->all('SELECT * FROM delivery_orders ORDER BY id');
    $index=$db->all('SELECT * FROM recent_orders ORDER BY id');
    verify(count($index)===32&&count($rows)===33,'Canlı kaydın ikincisi oluşmadı.');
    verify($service->page(1,$raw,$expected)===0&&$rows===$db->all('SELECT * FROM delivery_orders ORDER BY id')
        &&$index===$db->all('SELECT * FROM recent_orders ORDER BY id'),'Tekrar deneme mevcut kayıtları değiştirmedi.');
    verify($db->all('SELECT * FROM records ORDER BY id')===$before,'Mali kayıtlar ve kimlikler korundu.');
    verify((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0&&
        (int)$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===0,'Bildirim, stok ve sevkiyat işleyişinden bağımsız.');
    $list=(new OrderList($db,new OrderDetails($db,$cipher)))->listing(['id'=>1,'role'=>'admin'],1,['provider'=>'pazarama'],true);
    verify($list['total']===33&&$list['items'][0]['invoiceCents']===943406,'Gerçek sipariş liste girişinde adet ve fatura tutarı.');
    $september=$service->expected(1,'2026-09-01','2026-10-01');
    $blocked=$raw[33];
    $key=hash('sha256','Pazarama:'.hash('sha256',json_encode([$blocked['orderId'],$blocked['items'][0]['orderItemId']],JSON_THROW_ON_ERROR)));
    $db->run("INSERT INTO shipment_entries(company_id,provider,city,month,ordered_at,payload,source_key,removed_at,created,updated)
        VALUES(1,'Pazarama','Ankara','2026-09','','',?,'kaldırıldı','','')",[$key]);
    verify($service->page(1,[$blocked],$september)===0&&$db->one('SELECT removed_at FROM shipment_entries')['removed_at']==='kaldırıldı','Elle kaldırılmış kaydın tekrar aktarımı engellendi.');
    echo "33 arşiv siparişi, yineleme, canlı kimlikler, fatura, mağaza kapsamı ve bildirim/silme korumaları doğrulandı.\n";
} finally { unlink($path); }
