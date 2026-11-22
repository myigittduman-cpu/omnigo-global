<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/fixtures/financial-visibility.php';
use Omnigo\{Database,Repository,DeliverySchema,RecentOrderSchema,DeliveryCipher,UndeliveredSales,UndeliveredSalesReader,MarketplaceSalesSummary,SalesAnalytics,SalesCalendar};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$line=['lineId'=>'L1','sku'=>'A','title'=>'Ürün','quantity'=>2,'netQuantity'=>2,'grossCents'=>2029900,'cancelCents'=>0,'returnCents'=>0,'netCents'=>2029900];
$order=['externalId'=>'ONE','createdAt'=>'2026-10-21T16:45:00+03:00','month'=>'2026-10','orders'=>1,'state'=>'Aktarıldı','grossCents'=>2029900,'cancelCents'=>0,'returnCents'=>0,'products'=>[$line]];
$failed=[...$line,'shippingState'=>'undelivered'];$packages=[['details'=>['products'=>[$failed]]]];
foreach(array_keys(MarketplaceSalesSummary::PROVIDERS) as $provider){
 $result=UndeliveredSales::apply($provider,$order,$packages);
 check($result['cancelCents']===2029900&&$result['netCents']===0&&$result['products'][0]['netQuantity']===0,$provider.' teslim edilemeyen fatura tutarı netten düşer');
 check($result['grossCents']===2029900&&$order['cancelCents']===0,$provider.' fatura ve kaynak tutarı korunur');
 $again=UndeliveredSales::apply($provider,$result,$packages);check($again===$result,$provider.' çift düşüm yok');
 $refunded=[...$order,'returnCents'=>2029900,'products'=>[[...$line,'returnCents'=>2029900,'netCents'=>0,'netQuantity'=>0]]];
 check(UndeliveredSales::apply($provider,$refunded,$packages)===$refunded,$provider.' tam iade tekrar düşülmez');
 $cancelled=[...$order,'cancelCents'=>2029900,'products'=>[[...$line,'cancelCents'=>2029900,'netCents'=>0,'netQuantity'=>0]]];
 check(UndeliveredSales::apply($provider,$cancelled,$packages)===$cancelled,$provider.' mevcut iptal tekrar düşülmez');
 $partial=[...$order,'returnCents'=>1014950,'products'=>[[...$line,'returnCents'=>1014950,'netCents'=>1014950,'netQuantity'=>1]]];
 $result=UndeliveredSales::apply($provider,$partial,$packages);check($result['cancelCents']===1014950&&$result['returnCents']===1014950&&$result['netCents']===0,$provider.' önceden düşülen kısım korunur');
 $pending=[...$line,'lineId'=>'L2','sku'=>'B','grossCents'=>50000,'netCents'=>50000];
 $mixed=[...$order,'grossCents'=>2079900,'products'=>[$line,$pending]];
 $result=UndeliveredSales::apply($provider,$mixed,$packages);check($result['netCents']===50000&&$result['products'][1]===$pending,$provider.' diğer ürün etkilenmez');
 check(UndeliveredSales::apply($provider,$order,[])===$order,$provider.' eksik durum tahmin edilmez');
 check(UndeliveredSales::apply($provider,$order,[$packages[0],$packages[0]])['cancelCents']===2029900,$provider.' mükerrer paket iki kez düşülmez');
}
$raw=$failed;unset($raw['shippingState']);$raw['status']=14;
check(UndeliveredSales::apply('Pazarama',$order,[['details'=>['products'=>[$raw]]]])['cancelCents']===2029900,'Pazarama gerçek durum kodu 14');
$unknown=[...$failed,'shippingState'=>'unknown'];check(UndeliveredSales::apply('Pazarama',$order,[['details'=>['products'=>[$unknown]]]])===$order,'Bilinmeyen durum iptal değildir');
check(UndeliveredSales::apply('Pazarama',$order,[['details'=>['products'=>[$failed],'superseded'=>true]]])===$order,'Eski bölünmüş paket sayılmaz');
$oneUnit=[...$failed,'quantity'=>1];check(UndeliveredSales::apply('Pazarama',$order,[['details'=>['products'=>[$oneUnit]]]])['netCents']===1014950,'Aynı satırdaki yalnız etkilenen adet düşer');
$withoutId=$line;unset($withoutId['lineId']);$bySku=[...$order,'products'=>[$withoutId]];
check(UndeliveredSales::apply('n11',$bySku,$packages)['cancelCents']===2029900,'Tekil SKU ile eski n11/Koçtaş satırı eşleşir');
$ambiguous=[...$order,'grossCents'=>4059800,'products'=>[$withoutId,$withoutId]];check(UndeliveredSales::apply('n11',$ambiguous,$packages)===$ambiguous,'Tekrarlanan SKU tahmin edilmez');

$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);RecentOrderSchema::install($db);
$repo=new Repository($db);$one=$repo->createCompany('Vizyona',array_keys(MarketplaceSalesSummary::PROVIDERS));$two=$repo->createCompany('Dokunulmayacak müşteri',array_keys(MarketplaceSalesSummary::PROVIDERS));$cipher=DeliveryCipher::withKey(str_repeat('u',32));
$reader=new UndeliveredSalesReader($db,$cipher,[$one]);$repo=new Repository($db,$reader);
$save=function(int $company,string $kind,array $value)use($db){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($value),gmdate('c')]);};
$clock=new DateTimeImmutable('2026-10-24T06:00:00+03:00');
foreach([$one,$two] as $company)foreach(MarketplaceSalesSummary::PROVIDERS as $label=>$provider){
 $sync=['startDate'=>'2026-10-01T00:00:00+03:00','watermark'=>$clock->format(DATE_ATOM),'ordersPublished'=>true,'refundsVerified'=>true,'financial'=>['reportingReady'=>true,'from'=>'2026-10-01T00:00:00+03:00','through'=>$clock->format(DATE_ATOM)]];
 $save($company,'sync_'.$provider,$sync);
 if(in_array($provider,['pttavm','trendyol','hepsiburada'],true))$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,$provider==='hepsiburada'?'hepsiburada_sales':$provider,'ONE',json_encode($order),gmdate('c')]);
 else $save($company,'order_'.$provider,$order);
 $save($company,'sales',['channel'=>$label,'month'=>'2026-10','integration'=>$provider,...SalesCalendar::ZERO,'grossCents'=>2029900,'orders'=>1]);
 $value=['provider'=>$label,'externalId'=>'ONE','details'=>['products'=>[$failed]]];
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$label,'ONE','İzmir',0,$cipher->seal($company,'order:'.$label.':ONE',$value),'hash',gmdate('c')]);
}
$before=[$db->all('SELECT * FROM records'),$db->all('SELECT * FROM recent_orders'),$db->all('SELECT * FROM delivery_orders')];
$channels=$repo->records($one,'channels',['role'=>'admin']);$summary=(new MarketplaceSalesSummary($db,$reader))->dashboard($one,$channels,'2026-10',$clock);
foreach($summary['channels'] as $item)if($item['available']){
 check($item['periods']['month']['amountCents']===0&&$item['periods']['month']['cancelCents']===2029900,$item['channel'].' dönem özeti');
 $report=(new SalesAnalytics($db,$reader))->report(['role'=>'admin'],$one,$item['channel'],'2026-10-01','2026-10-24');
 check($report['totals']['netCents']===0&&$report['products'][0]['netCents']===0&&$report['topProducts']===[],$item['channel'].' rapor, ürün, grafik ve sıralama');
 $foreign=(new SalesAnalytics($db,$reader))->report(['role'=>'admin'],$two,$item['channel'],'2026-10-01','2026-10-24');
 check($foreign['totals']['netCents']===2029900,$item['channel'].' diğer müşterinin net satışı aynı kalır');
}
allowFinancialVisibility($db,$one);
$admin=$repo->dashboard(['role'=>'admin'],$one,'2026-10');$client=$repo->dashboard(['role'=>'client','company_id'=>$one],$one,'2026-10');
check($admin['summary']['netCents']===0&&\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($admin['summary']),['cancellations'=>false,'returns'=>false])===$client['summary'],'Yönetici ve müşteri genel bakış tutarları eşit; müşteri iptal/iade kırılımını almaz');
check($repo->dashboard(['role'=>'admin'],$two,'2026-10')['summary']['netCents']===7*2029900,'Diğer müşteri genel bakışı değişmez');
$daySummary=(new MarketplaceSalesSummary($db,$reader))->dashboard($one,$channels,'2026-10',new DateTimeImmutable('2026-10-21T18:00:00+03:00'));
$pazarama=array_column($daySummary['channels'],null,'channel')['Pazarama'];
check($pazarama['periods']['today']['amountCents']===0&&$pazarama['periods']['today']['operationalCancelCents']===2029900,'Sipariş günündeki net ve iptal tutarları da düzelir');
$daily=UndeliveredSales::dashboard(['marketplaceSales'=>['channels'=>[$pazarama]],'sales'=>[['channel'=>'Pazarama','month'=>'2026-10','integration'=>'pazarama','cancelCents'=>0]],'salesToday'=>['channels'=>[['channel'=>'Pazarama','netCents'=>2029900]]]],'2026-10');
check($daily['sales'][0]['cancelCents']===2029900&&$daily['salesToday']['channels'][0]['netCents']===0,'Aylık ve günlük eski özetlere yalnız ek iptal düşümü uygulanır');
check($before===[$db->all('SELECT * FROM records'),$db->all('SELECT * FROM recent_orders'),$db->all('SELECT * FROM delivery_orders')],'Hiçbir kaynak, sipariş, teslimat veya diğer müşteri kaydı yazılmadı');
echo "Yedi pazaryeri: teslim edilememe net düşümü, çift düşüm koruması, ürün/adet sınırı, raporlar, iki panel ve müşteri izolasyonu geçti.\n";
