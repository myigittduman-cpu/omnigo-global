<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,IntegrationVault,DeliveryCipher,DeliveryRepository,ShipmentOrderIngestor,TrendyolOrderMapper,TrendyolReadClient,TrendyolSyncAdapter,TrendyolOrderStore,SyncFailure,MarketplaceSalesSummary,Repository,ReturnRequestList,OrderDetails};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function failure(callable $fn,int $code):void{try{$fn();throw new RuntimeException('Hata bekleniyordu.');}catch(SyncFailure $e){check($e->httpStatus===$code,'Kaynak hata kodu');}}
function fixture(string $status='Created',string $package='package1',int $offset=0):array{
 $address=['fullName'=>'Örnek Alıcı','phone'=>'05000000000','fullAddress'=>'Örnek Sokak No 1','city'=>'Ankara','district'=>'Çankaya'];
 return ['supplierId'=>123,'shipmentPackageId'=>$package,'orderNumber'=>'order1','orderDate'=>(time()-900)*1000,'lastModifiedDate'=>(time()-600+$offset)*1000,'currencyCode'=>'TRY','originPackageIds'=>null,
 'shipmentAddress'=>$address,'invoiceAddress'=>$address,'customerFirstName'=>'Örnek','customerLastName'=>'Alıcı','customerEmail'=>'example@example.invalid','commercial'=>false,'packageTotalPrice'=>180.25,
 'lines'=>[['lineId'=>100,'quantity'=>2,'productName'=>'Örnek Ürün','stockCode'=>'SKU1','orderLineItemStatusName'=>$status,'cancelledBy'=>'Seller','discountDetails'=>[
 ['lineItemId'=>'unit1','lineItemPrice'=>90,'lineItemSellerDiscount'=>5,'lineItemTyDiscount'=>5],['lineItemId'=>'unit2','lineItemPrice'=>90.25,'lineItemSellerDiscount'=>4.75,'lineItemTyDiscount'=>5]]]]];
}
$a=fixture();$mapped=TrendyolOrderMapper::merge($a);
check($mapped['order']['grossCents']===18025&&$mapped['order']['orders']===1,'Fatura tutarı kuruşu kuruşuna korunur');
check(TrendyolOrderMapper::merge($a,$mapped['delivery'])===$mapped,'Tekrarlanan paket çoğalmaz');
foreach(['Cancelled','UnSupplied','UnDelivered','Returned'] as $status){$changed=TrendyolOrderMapper::merge(fixture($status,'package1',10),$mapped['delivery']);check(!$changed['delivery']['active']&&count($changed['delivery']['products'])===0,'İptal ve iade teslimattan çıkar');check($changed['order'][$status==='Returned'?'returnCents':'cancelCents']===18025,'Muhasebe tutarları korunur');}
$split=fixture('Cancelled','package2',20);$split['originPackageIds']=['package1'];$split['lines'][0]['quantity']=1;$split['lines'][0]['discountDetails']=[$split['lines'][0]['discountDetails'][0]];$split['packageTotalPrice']=90;
$partial=TrendyolOrderMapper::merge($split,$mapped['delivery']);check($partial['order']['cancelCents']===9000&&count($partial['delivery']['products'])===1,'Yalnız ilgili adet iptal olur');
$late=fixture('Created','package1',30);check(TrendyolOrderMapper::merge($late,$partial['delivery'])['order']['cancelCents']===9000,'Geç gelen eski paket iptali geri alamaz');
$invalid=$a;$invalid['packageTotalPrice']=999;failure(fn()=>TrendyolOrderMapper::merge($invalid),422);
$invalid=$a;$invalid['lines'][0]['orderLineItemStatusName']='FutureStatus';failure(fn()=>TrendyolOrderMapper::merge($invalid),422);
$waiting=TrendyolOrderMapper::merge(fixture('Awaiting'));check(!$waiting['delivery']['active']&&!$waiting['delivery']['shipmentItems'],'Onaysız sipariş sevkiyata eklenmez');
$addressed=fixture('Created','package2',30);$addressed['originPackageIds']=['package1'];$addressed['lines'][0]['quantity']=1;$addressed['lines'][0]['discountDetails']=[$addressed['lines'][0]['discountDetails'][0]];$addressed['packageTotalPrice']=90;$addressed['shipmentAddress']['fullAddress']='Başka adres';
check((bool)TrendyolOrderMapper::merge($addressed,$mapped['delivery'])['delivery']['issues'],'Farklı adresler aynı evrakta karışmaz');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$repo=new Repository($db);$one=$repo->createCompany('Birinci',['Trendyol']);$two=$repo->createCompany('İkinci',['Trendyol']);$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','x','admin',NULL)");
$key=tempnam(sys_get_temp_dir(),'trendyol-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$config=['supplierId'=>'123','apiKey'=>'test-key','apiSecret'=>'test-secret','enabled'=>true,'revision'=>'v1','connectedAt'=>time()+1,'startDate'=>gmdate(DATE_ATOM,time()-1800)];$vault->save($one,$config,'trendyol');$vault->save($two,[...$config,'supplierId'=>'456'],'trendyol');
 $cipher=new DeliveryCipher($key);$delivery=new DeliveryRepository($db,$cipher);ShipmentOrderIngestor::activate($db,$one,time()-3600);$other=$db->all('SELECT * FROM records WHERE company_id=? ORDER BY id',[$two]);
 $packages=[$a];$more=true;$code=200;$requests=[];
 $reader=new TrendyolReadClient(function($url,$headers)use(&$packages,&$more,&$code,&$requests){check(str_starts_with($url,'https://apigw.trendyol.com/integration/order/sellers/123/orders/stream?'),'Sabit resmi salt okunur adres');check(!str_contains($url,'test-key'),'Anahtar URL içine konulmaz');parse_str(parse_url($url,PHP_URL_QUERY),$query);$requests[]=$query;return ['status'=>$code,'retryAfter'=>120,'body'=>json_encode(['content'=>$packages,'hasMore'=>$more,'nextCursor'=>$more?'opaque cursor +/=':null])];});
 $adapter=new TrendyolSyncAdapter($db,$vault,$delivery,$reader);$store=new TrendyolOrderStore($db,$vault,$delivery);$adapter->syncReadOnly($one,time()+30);
 $clear=function()use($db,$store,$one){$row=$store->state($one);$s=json_decode($row['payload'],true);$s['nextRequestAt']=0;$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($s),$row['id']]);};
 check(count($db->all('SELECT * FROM recent_orders WHERE company_id=?',[$one]))===1,'Tek sipariş kaydı');check(count($db->all('SELECT * FROM shipment_entries WHERE company_id=?',[$one]))===2,'Adet kimlikleri ayrı eşleşir');
 $clear();$packages=[$split];$more=false;$adapter->syncReadOnly($one,time()+30);check($requests[1]['nextCursor']==='opaque cursor +/='&&$requests[0]['lastModifiedStartDate']===$requests[1]['lastModifiedStartDate'],'İmleç ve tarama aralığı korunur');
 $audit=json_decode($store->state($one)['payload'],true);
 check(\Omnigo\TrendyolReconciliation::current($audit,time()),'Tam sayfalanan kaynak doğrulanır');
 $compare=new \Omnigo\ReconciliationCompare($db,$cipher);
 check($compare->inspect($one,'trendyol',$audit['reconciliationReference'])['total']===0,'Bölünen ve kısmen iptal edilen sipariş doğru karşılaştırılır');
 check($compare->inspect($two,'trendyol',$audit['reconciliationReference'])['counts']['missing']===1,'Diğer mağazanın kaydı eşleştirilmez');
 $altered=$audit['reconciliationReference'];$auditKey=array_key_first($altered['orders']);$altered['orders'][$auditKey]['money'][0]++;
 $altered['orders'][$auditKey]['state']='Teslim edildi';
 $diff=$compare->inspect($one,'trendyol',$altered);check($diff['counts']['amount']===1&&$diff['counts']['status']===1,'Hem tutar hem durum farkı saptanır');
 check(!\Omnigo\TrendyolReconciliation::current([...$audit,'recentScan'=>['cursor'=>'x']],time()),'Kısmi kaynak taraması mutabakat sayılmaz');
 $rows=$db->all('SELECT row_status FROM shipment_entries WHERE company_id=?',[$one]);check(count(array_filter($rows,fn($r)=>$r['row_status']==='cancelled'))===1,'İptal edilen adet kırmızı, diğer adet etkin');
 $saved=$db->one("SELECT payload FROM recent_orders WHERE company_id=?",[$one])['payload'];$clear();$code=429;failure(fn()=>$adapter->syncReadOnly($one,time()+30),429);check($saved===$db->one('SELECT payload FROM recent_orders WHERE company_id=?',[$one])['payload'],'Hız sınırı mevcut veriyi bozmaz');check(json_decode($store->state($one)['payload'],true)['nextRequestAt']>=time()+119,'Retry-After korunur');
 $clear();$code=200;$packages=[fixture('Returned','package3',60)];$packages[0]['originPackageIds']=['package1'];$packages[0]['lines'][0]['quantity']=1;$packages[0]['lines'][0]['discountDetails']=[$packages[0]['lines'][0]['discountDetails'][1]];$packages[0]['packageTotalPrice']=90.25;$adapter->syncReadOnly($one,time()+30);
 $returns=(new ReturnRequestList($db,new OrderDetails($db,$cipher)))->listing(['role'=>'admin'],$one,[]);check(count($returns['items'])===1&&$returns['items'][0]['customerName']==='Örnek Alıcı','İade adedi ve alıcı adı');
 check($other===$db->all('SELECT * FROM records WHERE company_id=? ORDER BY id',[$two]),'Diğer müşterinin verisi ve bağlantısı değişmedi');
 $market=new \Omnigo\CustomerMarketplaces($db);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>0,'role'=>'client','company_id'=>$one];$month=date('Y-m');$day=date('Y-m-d');
 $summaries=[];$reports=[];foreach(['admin'=>$admin,'client'=>$client] as $role=>$user){$summaries[$role]=$repo->dashboard($user,$one,$month)['summary'];$reports[$role]=(new \Omnigo\ReportingService($db))->report($user,$one,$day,$day)['totals'];check($reports[$role]['grossCents']===18025,'Rapor fatura toplamı');}
 foreach(['hide','delete'] as $action){$item=array_values(array_filter($market->listing($one),fn($r)=>$r['provider']==='trendyol'))[0];$market->change($admin,$one,'trendyol',['action'=>$action,'version'=>$item['version']]);foreach(['admin'=>$admin,'client'=>$client] as $role=>$user){check($repo->dashboard($user,$one,$month)['summary']===$summaries[$role],'Gizleme/silme genel toplamı değiştirmez');check((new \Omnigo\ReportingService($db))->report($user,$one,$day,$day)['totals']===$reports[$role],'Gizleme/silme raporu değiştirmez');}}
 $foreign=new TrendyolReadClient(fn()=>['status'=>200,'body'=>json_encode(['content'=>[[...$a,'supplierId'=>456]],'hasMore'=>false])]);failure(fn()=>$foreign->orders($config,100,200,null,time()+30),403);
 echo "Trendyol: fatura, tekrar, paket bölünmesi, iptal, iade, adres koruması, imleç, 429 ve müşteri ayrımı geçti.\n";
}finally{unlink($key);}
