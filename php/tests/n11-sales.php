<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,N11Connection,N11ReadClient,N11ReturnClient,N11SyncAdapter,N11SalesMapper,SalesAnalytics,SyncQueue,SyncFailure,HttpError,ConnectionHealth};
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function fails(callable $call,int $status):void {try{$call();}catch(SyncFailure|HttpError $error){check(($error instanceof SyncFailure?$error->httpStatus:$error->getCode())===$status,'Beklenen hata: '.$status);return;}throw new RuntimeException('İşlem reddedilmeliydi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
\Omnigo\DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Satış test');$other=$repo->createCompany('Diğer firma');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['admin@test.invalid','Test','unused','admin']);$admin=['id'=>$db->insertId(),'role'=>'admin'];
$path=tempnam(sys_get_temp_dir(),'n11-sales-');file_put_contents($path,random_bytes(32));
$zone=new DateTimeZone('Europe/Istanbul');$start=new DateTimeImmutable('first day of this month 00:00:00',$zone);$month=$start->format('Y-m');
$raw=['orderNumber'=>'100','id'=>null,'sellerId'=>123,'shipmentPackageStatus'=>'Picking','customerEmail'=>'PRIVATE',
 'packageHistories'=>[['status'=>'Created','createdDate'=>$start->getTimestamp()*1000+1000]],
 'lines'=>[['productId'=>99,'orderLineId'=>101,'quantity'=>2,'productName'=>'Çamaşır makinesi','stockCode'=>'CGA242X3TR','sellerInvoiceAmount'=>'579.81','orderItemLineItemStatusName'=>'Picking']]];
$claim=['claimReturnId'=>'55','orderNumber'=>'100','productId'=>'99','skuId'=>'','quantity'=>1,'amountCents'=>1,'status'=>'APPROVED','requestDate'=>'','approvedDate'=>'','paymentDate'=>''];
$empty='<Envelope><Body><ClaimReturnListResponse><result><status>success</status></result><claimReturnList/><pagingData><currentPage>0</currentPage><pageCount>0</pageCount><totalCount>0</totalCount></pagingData></ClaimReturnListResponse></Body></Envelope>';
try {
 $vault=new IntegrationVault($db,$path);$connection=new N11Connection($db,$vault);$connection->save($company,['apiKey'=>'fake','apiSecret'=>'secret','version'=>0],$admin['id']);
 $mapped=N11SalesMapper::package($raw);check(!str_contains(json_encode($mapped),'PRIVATE'),'Kişisel veriler saklanmamalı');
 $orders=N11SalesMapper::orders([$mapped,$mapped],[$claim,$claim],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM));
 check(count($orders)===1&&$orders[0]['grossCents']===57981&&$orders[0]['returnCents']===28991&&$orders[0]['products'][0]['netQuantity']===1,'Satır ve iade mükerrer sayılmamalı; miktar fatura tutarı tekrar çarpılmamalı');
 $cancel=$raw;$cancel['lines'][0]['orderItemLineItemStatusName']='Cancelled';
 check(N11SalesMapper::orders([N11SalesMapper::package($cancel)],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM))[0]['cancelCents']===57981,'İptal düşülmeli');
 foreach([[$mapped,N11SalesMapper::package($cancel)],[N11SalesMapper::package($cancel),$mapped]] as $snapshots){
  $reconciled=N11SalesMapper::orders($snapshots,[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM));
  check(count($reconciled)===1&&$reconciled[0]['grossCents']===57981&&$reconciled[0]['cancelCents']===57981,'Hazırlanan ve iptal paketleri sıra bağımsız tek satır olmalı');
 }
 $changedAmount=$mapped;$changedAmount['products'][0]['invoiceCents']++;
 fails(fn()=>N11SalesMapper::orders([$mapped,$changedAmount],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 $delivered=$mapped;$delivered['products'][0]['status']='Delivered';
 fails(fn()=>N11SalesMapper::orders([$delivered,N11SalesMapper::package($cancel)],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 fails(fn()=>N11SalesMapper::orders([$mapped],[[...$claim,'quantity'=>3]],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 $split=$mapped;$split['products'][0]['lineId']='102';$split['products'][0]['quantity']=1;
 check(N11SalesMapper::orders([$mapped,$split],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM))[0]['orders']===1,'İki paket tek sipariş');
 fails(fn()=>N11SalesMapper::orders([$mapped,$split],[$claim],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 $unpacked=$mapped;$unpacked['status']='Unpacked';
 check(count(N11SalesMapper::orders([$unpacked,$mapped],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)))===1,'Bozulan paket tekrar sayılmaz');
 fails(fn()=>N11SalesMapper::orders([$unpacked],[],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 $second=$raw;$second['orderNumber']='200';$second['lines'][0]['orderLineId']=201;$second['lines'][0]['sellerInvoiceAmount']='100';
 $requestCount=0;$rate=false;
 $reader=new N11ReadClient(function($url)use(&$requestCount,$raw,$second,&$rate){
   $requestCount++;parse_str(parse_url($url,PHP_URL_QUERY),$query);
   check($query['endDate']-$query['startDate']<14*86400000,'Tarih penceresi sınırı');
   if($rate)return ['status'=>429,'retryAfter'=>7200];
   return ['status'=>200,'body'=>json_encode(['page'=>(int)$query['page'],'totalPages'=>2,'content'=>[$query['page']==='0'?$raw:$second]])];
 });
 $returns=new N11ReturnClient(function($url,$xml)use($empty){check($url==='https://api.n11.com/ws/returnService/'&&str_contains($xml,'ClaimReturnListRequest')&&!str_contains($xml,'Approve'),'Yalnızca iade okuma');return ['status'=>200,'body'=>$empty];});
 $delivery=new \Omnigo\DeliveryRepository($db,new \Omnigo\DeliveryCipher($path));
 $adapter=new N11SyncAdapter($db,$vault,$reader,$returns,$delivery);
 $adapter->syncReadOnly($company,time()+30);check(!$repo->records($company,'sales',$admin),'İlk sayfa yayımlanmamalı');
 check($adapter->nextDelay()===60,'Devam sayfası kısa aralık');
 $rate=true;fails(fn()=>$adapter->syncReadOnly($company,time()+30),429);$rate=false;
 check(!$repo->records($company,'sales',$admin),'Hata halinde kısmi toplam yayımlanmamalı');
 for($i=0;$i<12&&!($connection->status($company)['sync']['salesPublished']??false);$i++)$adapter->syncReadOnly($company,time()+30);
 check((int)$db->one('SELECT COUNT(*) AS n FROM delivery_orders')['n']===2,'n11 teslimat aktarımı aynı sayfalardan yapılmalı');
 $sale=$repo->records($company,'sales',$admin)[0];check($sale['grossCents']===67981&&$sale['orders']===2,'Tüm sayfalar ve iade kontrolü sonrası doğru toplam');
 check($adapter->nextDelay()===900,'Tamamlanan döngü normal zamanlama');
 $reference=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_n11'",[$company])['payload'],true)['reconciliationReference'];
 check(count($reference['orders'])===2&&(new \Omnigo\ReconciliationCompare($db,new \Omnigo\DeliveryCipher($path)))->inspect($company,'n11',$reference)['total']===0,'n11 tamamlanan kaynak mutabakatı');
 check(!$repo->records($other,'sales',$admin),'Firma izolasyonu');
 foreach(['admin','client'] as $role){
   $dashboard=$repo->dashboard(['role'=>$role,'company_id'=>$company],$company,$month);
   check($dashboard['sales'][0]['grossCents']===67981,'İki portal aynı satış kaydını kullanmalı');
   $report=(new SalesAnalytics($db))->report(['role'=>$role,'company_id'=>$company],$company,'n11',$start->format('Y-m-d'),$start->modify('+1 day')->format('Y-m-d'));
   check($report['available']&&$report['totals']['orders']===2&&$report['topProducts'][0]['netQuantity']===4,'Tarih filtresi ve TOP10 çalışmalı');
 }
 fails(fn()=>$repo->save($admin,$company,'sales',[...$sale,'version'=>$sale['version']],$sale['id']),409);
 $rate=true;$db->run("UPDATE jobs SET next_run=0 WHERE provider='n11'");
 (new SyncQueue($db,['n11'=>$adapter]))->runOne();
 check($db->one("SELECT next_run FROM jobs WHERE provider='n11'")['next_run']>=time()+7195,'Sunucu Retry-After kuyrukta korunmalı');
 check($repo->records($company,'sales',$admin)[0]['grossCents']===67981,'Son başarılı satış hata halinde korunmalı');
 $rate=false;$db->run("UPDATE jobs SET next_run=0 WHERE provider='n11'");$connection->retry($company);
 check($connection->status($company)['sync']['salesPublished'],'Yeniden deneme yayımlanmış görünümü silmemeli');
 $slow=new N11ReadClient(function()use($vault,$company,$raw){$vault->save($company,['apiKey'=>'fake','apiSecret'=>'secret','revision'=>'new','enabled'=>true],'n11');return ['status'=>200,'body'=>json_encode(['totalPages'=>1,'content'=>[$raw]])];});
 fails(fn()=>(new N11SyncAdapter($db,$vault,$slow,$returns))->syncReadOnly($company,time()+30),409);
 check($repo->records($company,'sales',$admin)[0]['grossCents']===67981,'Eski anahtarlı yanıt yayımlanmamalı');
 fails(fn()=>N11ReturnClient::parse('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///secret">]><Envelope/>',0),422);
 $refundXml=str_replace('<claimReturnList/>','<claimReturnList><claimReturn><claimReturnId>55</claimReturnId><orderNumber>100</orderNumber><productId>99</productId><quantity>1</quantity><finalPrice>19.90</finalPrice><status>APPROVED</status><buyerName>PRIVATE</buyerName></claimReturn></claimReturnList>',str_replace('<pageCount>0</pageCount><totalCount>0</totalCount>','<pageCount>1</pageCount><totalCount>1</totalCount>',$empty));
 $parsed=N11ReturnClient::parse($refundXml,0);check($parsed['claims'][0]['amountCents']===1990&&$parsed['claims'][0]['buyerName']==='PRIVATE','SOAP iade tutarı ve şifrelenmek üzere alınan alıcı adı');
 echo "n11 satış: sayfalama, iptal/iade, yinelenen kayıtlar, kuyruk, atomik yayın, iki portal ve tarih raporları geçti.\n";
} finally {unlink($path);}
