<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/fixtures/financial-visibility.php';
use Omnigo\{Database,Repository,RecentOrderSchema,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,HepsiburadaOrderMapper,HepsiburadaOrderDiscovery,HepsiburadaReconciliationSource,HepsiburadaSalesPublisher,HepsiburadaSalesOrders,MarketplaceSalesSummary,SalesAnalytics,OrderList,ReconciliationMonitor,ReconciliationReference,ReconciliationCompare,HepsiburadaReadClient,HepsiburadaCredentials,MobilePushDevices};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
RecentOrderSchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$other=$repo->createCompany('Başka firma');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL),(2,'client@example.test','Müşteri','hash','client',?)",[$company]);
$cipher=DeliveryCipher::withKey(str_repeat('x',32));$merchant='12345678-1234-1234-1234-123456789012';
$from='2026-10-01T00:00:00+03:00';$through='2026-10-17T17:00:00+03:00';$clock=new DateTimeImmutable($through);$now=time();
$mapped=function(string $number,string $date,string $status='Delivered')use($merchant){
 return HepsiburadaOrderMapper::map(['orderNumber'=>$number,'orderDate'=>$date,'items'=>[['id'=>'line-'.$number,'merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Örnek ürün','merchantSKU'=>'SKU-1','quantity'=>2,'totalPrice'=>['currency'=>'TRY','amount'=>'300.25'],'status'=>$status,'lastStatusUpdateDate'=>$date,'shippingAddress'=>['city'=>'Ankara','name'=>'Gizli alıcı','address'=>'Gizli adres']]]],$merchant,$number);
};
$a=$mapped('10001','2026-10-17T10:00:00+03:00');$b=$mapped('10002','2026-10-16T23:59:59+03:00','CancelledByCustomer');
$old=$mapped('10003','2026-09-30T20:59:59Z');$boundary=$mapped('10004','2026-09-30T21:00:00Z');
$claim=['id'=>'90001','orderNumber'=>'10001','lineId'=>'line-10001','orderedAt'=>$a['order']['createdAt'],'quantity'=>1,'status'=>'Refunded','amountCents'=>10005,'refundedAt'=>'2026-10-17T11:00:00+03:00'];
$state=['phase'=>'live','watermark'=>$through,'startDate'=>$from,'pendingOrders'=>[]];
foreach(HepsiburadaOrderDiscovery::FEEDS as $feed)HepsiburadaReconciliationSource::discover($state,$feed,0,['items'=>[],'hasMore'=>false],$merchant);
$saveOrder=function(array $order,?string $external=null,int $owner=0)use($db,$company){$db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,'hepsiburada',?,?,?)",[$owner?:$company,$external??$order['externalId'],json_encode($order),gmdate('c')]);return $db->insertId();};
$ids=[];foreach([$a,$b,$old,$boundary] as $value){HepsiburadaReconciliationSource::capture($state,$value);$ids[$value['order']['externalId']]=$saveOrder($value['order']);}
check(!str_contains(json_encode($state),'Gizli'),'Bağımsız kaynak alıcı verisi içermez');
$saveOrder($a['order'],null,$other);
$db->transaction(fn()=>(new HepsiburadaSalesPublisher($db))->publish($company,$from,$through,[$claim]));
HepsiburadaReconciliationSource::publish($state,$from,$through,[$claim]);
$state['financial']=['reportingReady'=>true,'from'=>$from,'through'=>$through];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_hepsiburada',?,?)",[$company,json_encode($state),gmdate('c')]);$stateId=$db->insertId();
foreach(['Hepsiburada','Hepsiburada Satış'] as $provider)$db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(?,?,'pending',?,0)",[$company,$provider,$now]);
$channels=[['name'=>'Hepsiburada','status'=>'active']];
$summary=(new MarketplaceSalesSummary($db))->dashboard($company,$channels,'2026-10',$clock)['channels'][0];
check($summary['available']&&$summary['refundsVerified'],'Doğrulanmış günlük görünüm');
check($summary['periods']['today']['amountCents']===20020&&$summary['periods']['today']['quantity']===1,'Gerçek iade tutarı ve adedi günlük grafikten düşer');
check($summary['periods']['month']['amountCents']===50045&&$summary['periods']['month']['orders']===3,'İptal ve Türkiye ay sınırı');
check($summary['days'][0]['amountCents']===null&&$summary['periods']['thirty']['partial']&&$summary['periods']['thirty']['previous']===null,'Eksik geçmiş sıfır veya sahte karşılaştırma olmaz');
$days=array_column($summary['days'],null,'date');check($days['2026-10-02']['amountCents']===0&&$days['2026-10-16']['quantity']===0,'Bilinen boş gün ve iptal adedi sıfır');
$report=(new SalesAnalytics($db))->report(['role'=>'client','company_id'=>$company],$company,'Hepsiburada','2026-10-01','2026-10-17',0,25,$clock);
check($report['totals']['netCents']===50045&&$report['topProducts'][0]['netQuantity']===3,'Detay raporu ve TOP 10 aynı finansal anı kullanır');
allowFinancialVisibility($db,$company);
$admin=$repo->dashboard(['role'=>'admin'],$company,'2026-10');$client=$repo->dashboard(['role'=>'client','company_id'=>$company],$company,'2026-10');
check(\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($admin['sales']),['cancellations'=>false,'returns'=>false])===$client['sales']&&\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($admin['marketplaceSales']),['cancellations'=>false,'returns'=>false])===$client['marketplaceSales'],'İki rolün satış hesabı eşit; iptal/iade yalnız Omnigo kırılımında');
check(!(new MarketplaceSalesSummary($db))->dashboard($other,$channels,'2026-10',$clock)['channels'][0]['available'],'Firma yalıtımı');
$list=(new OrderList($db))->listing(['role'=>'admin'],$company,['provider'=>'hepsiburada','from'=>'2026-09-01','to'=>'2026-10-17']);
check($list['total']===4,'Özel grafik projeksiyonu gerçek sipariş listesini çoğaltmaz');
$monitor=new ReconciliationMonitor($db,$cipher);check($monitor->check($company,'hepsiburada',false)['total']===0,'Bağımsız kaynak ve üç aylık sipariş mutabık');
$baseline=$state;$updateState=function(array $value)use($db,$stateId){$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($value),$stateId]);};
foreach(['pending','version','finance','error'] as $case){$s=$baseline;if($case==='pending')$s['reconciliationSource']['pending']['10005']=true;if($case==='version')$s['reconciliationSource']['version']++;if($case==='finance')$s['financial']['scan']=['stream'=>1];if($case==='error')$s['financial']['error']=['code'=>429];$updateState($s);check($monitor->check($company,'hepsiburada',false)===null,'Yarım/yeni/başarısız kaynak yanlış alarm üretmez: '.$case);}
$updateState($baseline);$db->run("UPDATE jobs SET state='running' WHERE provider='Hepsiburada Satış'");check($monitor->check($company,'hepsiburada',false)===null,'Çalışan finans işi bitmeden kontrol yok');$db->run("UPDATE jobs SET state='pending'");
$monitor->check($company,'hepsiburada');
$bad=[...$a['order'],'grossCents'=>30026];$db->run('UPDATE recent_orders SET payload=? WHERE id=?',[json_encode($bad),$ids['10001']]);
$result=$monitor->check($company,'hepsiburada',true,$now+46);check($result['counts']['amount']===1,'Kaynak yerel bozulmayı kopyalamaz; bir kuruş fark algılanır');
$monitor->check($company,'hepsiburada',true,$now+92);check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===1,'Aynı fark tekrarlı bildirilmez');
$event=$db->one('SELECT * FROM order_notifications');$mobile=new MobilePushDevices($db,$cipher);check($mobile->recipient(['user_id'=>1],$event)&&!$mobile->recipient(['user_id'=>2],$event),'Mutabakat bildirimi yalnız yönetici cihazına');
$db->run('UPDATE recent_orders SET payload=? WHERE id=?',[json_encode($a['order']),$ids['10001']]);
$dup=$saveOrder($a['order'],'physical-duplicate');$db->run('DELETE FROM recent_orders WHERE id=?',[$ids['10002']]);
$result=$monitor->check($company,'hepsiburada',false);check($result['counts']['duplicate']===1&&$result['counts']['missing']===1,'Mükerrer payload kimliği ve eksik gerçek sipariş');
$db->run('DELETE FROM recent_orders WHERE id=?',[$dup]);$ids['10002']=$saveOrder($b['order']);
$monitor->check($company,'hepsiburada',true,$now+138);check((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===2,'Düzelme tek bildirim');
// New operational data cannot leak into an older financial projection while refunds are pending.
$new=$mapped('10005','2026-10-17T15:00:00+03:00');$saveOrder($new['order']);
check((new MarketplaceSalesSummary($db))->dashboard($company,$channels,'2026-10',$clock)['channels'][0]['periods']['month']['amountCents']===50045,'Tamamlanmamış finans taraması günlük/aylık tutarı ayırmaz');
$db->transaction(fn()=>(new HepsiburadaSalesPublisher($db))->publish($company,$from,$through,[$claim]));
check((new MarketplaceSalesSummary($db))->dashboard($company,$channels,'2026-10',$clock)['channels'][0]['periods']['month']['amountCents']===80070,'Tamamlanmış yayın grafiği günceller');
// Extending history is idempotent and changes neither October money nor physical order count.
$expanded='2026-09-01T00:00:00+03:00';
for($repeat=0;$repeat<2;$repeat++)$db->transaction(fn()=>(new HepsiburadaSalesPublisher($db))->publish($company,$expanded,$through,[$claim]));
$extended=stateForHistory($db,$company,$expanded);$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($extended),$stateId]);
$september=(new MarketplaceSalesSummary($db))->dashboard($company,$channels,'2026-09',$clock)['channels'][0];
check(count($september['days'])===30&&$september['days'][0]['date']==='2026-09-01'&&$september['days'][29]['date']==='2026-09-30','Seçili geçmiş ayın tamamı grafik olur');
check($september['periods']['month']['amountCents']===30025&&!$september['periods']['month']['partial'],'Eylül yayını ve kapsamı');
check($september['periods']['today']['salesCents']===60050&&$september['periods']['today']['amountCents']===50045,'Ana satış iade öncesi; net ayrı');
check((new MarketplaceSalesSummary($db))->dashboard($company,$channels,'2026-10',$clock)['channels'][0]['periods']['month']['amountCents']===80070,'Geçmiş genişletme Ekim toplamını değiştirmez');
check((int)$db->one("SELECT COUNT(*) n FROM recent_orders WHERE company_id=? AND provider='hepsiburada'",[$company])['n']===5,'Finans yayını gerçek sipariş çoğaltmaz');
function stateForHistory(Database $db,int $company,string $from):array{$s=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company])['payload'],true);$s['financial']['from']=$from;return $s;}
// Independent source backfill obeys request budgets, retry-after and never writes business rows.
$source=['reconciliationSource'=>['pending'=>['10001'=>true,'10002'=>true]]];$calls=0;
$reader=new HepsiburadaReadClient(function()use(&$calls){$calls++;return ['status'=>429,'retryAfter'=>180];});
$credentials=HepsiburadaCredentials::fromArray(['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'synthetic','userAgent'=>'test_dev']);
HepsiburadaReconciliationSource::backfill($source,$reader,$credentials,time()+50,2);
check($calls===1&&count($source['reconciliationSource']['pending'])===2&&$source['reconciliationSource']['nextAttemptAt']>=time()+179,'Mutabakat API hatası ana sipariş kuyruğunu bozmadan geri çekilir');
HepsiburadaReconciliationSource::backfill($source,$reader,$credentials,time()+50,2);check($calls===1,'Retry-After süresinde tekrar istek yok');
echo "Hepsiburada raporlama: kaynak bağımsızlığı, kuruş farkı, eksik/mükerrer, sessiz tekrar, yönetici sınırı, atomik grafik, iade/adet, Türkiye ay sınırı, kısmi geçmiş, TOP 10, firma izolasyonu ve geri çekilme geçti.\n";
