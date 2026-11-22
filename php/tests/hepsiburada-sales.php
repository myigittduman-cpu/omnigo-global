<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/fixtures/financial-visibility.php';
use Omnigo\{Database,Repository,IntegrationVault,RecentOrderSchema,HepsiburadaClaims,HepsiburadaOrderMapper,HepsiburadaOrderDiscovery,HepsiburadaReadClient,HepsiburadaSalesSync,HepsiburadaSalesPublisher,SyncFailure,SyncQueue};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $status):void{try{$fn();}catch(SyncFailure $e){check($e->httpStatus===$status,'Hata kodu');return;}throw new RuntimeException('Hata bekleniyordu.');}
$merchant='12345678-1234-1234-1234-123456789012';$date=gmdate('c',time()-300);$zone=new DateTimeZone('Europe/Istanbul');$from=(new DateTimeImmutable('first day of this month',$zone))->setTime(0,0)->format(DATE_ATOM);$month=substr($from,0,7);
$number='9912345678';$line=['id'=>'test-line-1','merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Örnek ürün','merchantSKU'=>'SKU-1','quantity'=>2,'totalPrice'=>['currency'=>'TRY','amount'=>'300.25'],'status'=>'Delivered','lastStatusUpdateDate'=>$date,'shippingAddress'=>['city'=>'Ankara']];
$order=HepsiburadaOrderMapper::map(['orderNumber'=>$number,'orderDate'=>$date,'items'=>[$line]],$merchant,$number)['order'];
$raw=['number'=>'12345','orderNumber'=>$number,'lineItemId'=>'test-line-1','quantity'=>1,'orderDate'=>$date,'status'=>'Refunded','refundAmount'=>'100.05','refundCurrency'=>'TRY','refundDate'=>gmdate('c',time()-100),'finalizedWith'=>'Refund'];
$claim=HepsiburadaClaims::map($raw,'Refunded',$merchant);$applied=HepsiburadaClaims::apply($order,[$claim,$claim]);
check($applied['returnCents']===10005&&$applied['products'][0]['netCents']===20020,'Tekil, gerçek geri ödeme tutarı; miktarla tekrar çarpılmaz');
check($applied['products'][0]['netQuantity']===1,'İade adedi');
foreach(['Accepted','AwaitingAction','Rejected','Cancelled'] as $status){$pending=HepsiburadaClaims::map([...$raw,'status'=>$status,'refundAmount'=>0,'refundCurrency'=>'','refundDate'=>''],$status,$merchant);check(HepsiburadaClaims::apply($order,[$pending])['returnCents']===0,'Bekleyen/red talep düşülmez');}
fails(fn()=>HepsiburadaClaims::map([...$raw,'refundCurrency'=>'USD'],'Refunded',$merchant),422);
fails(fn()=>HepsiburadaClaims::map([...$raw,'merchantId'=>'other'],'Refunded',$merchant),422);
fails(fn()=>HepsiburadaClaims::apply($order,[[...$claim,'amountCents'=>40000]]),422);
fails(fn()=>HepsiburadaClaims::apply($order,[[...$claim,'lineId'=>'missing']]),422);
fails(fn()=>HepsiburadaClaims::apply($order,[$claim,[...$claim,'amountCents'=>99]]),409);
$cancelled=$order;$cancelled['cancelCents']=30025;$cancelled['products'][0]['cancelCents']=30025;$cancelled['products'][0]['cancelledQuantity']=2;
check(HepsiburadaClaims::apply($cancelled,[[...$claim,'amountCents'=>30025,'quantity'=>2]])['returnCents']===0,'İptal tutarı tekrar iade diye düşülmez');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));RecentOrderSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$other=$repo->createCompany('Diğer');
$db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,'hepsiburada',?,?,?)",[$company,$number,json_encode($order),gmdate('c')]);
$state=['phase'=>'live','watermark'=>gmdate('c'),'feedCompletedAt'=>array_fill_keys(HepsiburadaOrderDiscovery::FEEDS,gmdate('c')),'pendingOrders'=>[]];
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_hepsiburada',?,?)",[$company,json_encode($state),gmdate('c')]);
$key=tempnam(sys_get_temp_dir(),'hb-sales-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$config=['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'synthetic','userAgent'=>'test_dev','enabled'=>true,'ordersEnabled'=>true,'salesEnabled'=>true,'salesStartDate'=>$from,'revision'=>'one'];$vault->save($company,$config,'hepsiburada');
 $rate=false;$invalid=false;$missing=false;$calls=[];
 $reader=new HepsiburadaReadClient(function($url)use(&$rate,&$invalid,&$missing,&$calls,$raw){
  $calls[]=$url;if($rate&&str_contains($url,'/Refunded?'))return ['status'=>429,'retryAfter'=>180];
  $items=[];if(str_contains($url,'/Refunded?')&&str_contains($url,'offset=0'))$items=array_fill(0,10,[...$raw,'refundAmount'=>$invalid?400:$raw['refundAmount'],'orderNumber'=>$missing?'9912345679':$raw['orderNumber']]);
  return ['status'=>200,'body'=>json_encode($items)];
 });
 $sync=new HepsiburadaSalesSync($db,$vault,$reader);$sync->syncReadOnly($company,time()+40);
 $sale=$db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sales'",[$company]);$value=json_decode($sale['payload'],true);
 check($value['grossCents']===30025&&$value['returnCents']===10005&&$value['refundsVerified'],'Tam sayfalama sonrası doğru aylık özet');
 check(count(array_filter($calls,fn($u)=>str_contains($u,'offset=10')))===1,'Tam dolu son sayfadan sonra boş sayfa okunur');
allowFinancialVisibility($db,$company);
 $admin=$repo->dashboard(['role'=>'admin'],$company,$month);$client=$repo->dashboard(['role'=>'client','company_id'=>$company],$company,$month);
 check($admin['summary']['netCents']===20020&&\Omnigo\CustomerSensitiveVisibility::redact(\Omnigo\CustomerTurnoverDisplay::project($admin['sales']),['cancellations'=>false,'returns'=>false])===$client['sales'],'Yönetici ve müşteri aynı tutarı okur; iptal/iade kırılımı yalnız Omnigo’da');
 check($repo->dashboard(['role'=>'client','company_id'=>$other],$other,$month)['sales']===[],'Firma izolasyonu');
 $reset=function()use($db,$company){$row=$db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);$s=json_decode($row['payload'],true);$s['financial']['nextRequestAt']=0;$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($s),$row['id']]);};
 fails(fn()=>$sync->syncReadOnly($company,time()+40),429);$reset();$sync->syncReadOnly($company,time()+40);
 check((int)$db->one("SELECT COUNT(*) n FROM records WHERE company_id=? AND kind='sales'",[$company])['n']===1,'Tekrarlanan görev satış satırı çoğaltmaz');
 $row=$db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);$held=json_decode($row['payload'],true);$held['isolatedOrderCount']=1;$held['financial']['nextRequestAt']=0;
 $db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($held),$row['id']]);$beforeHeld=$db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload'];
 $sync->syncReadOnly($company,time()+40);check($db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload']===$beforeHeld,'Ayrılan sipariş tamamlanmadan finansal başarı yayımlanmaz');
 $held=json_decode($db->one('SELECT payload FROM records WHERE id=?',[$row['id']])['payload'],true);check(isset($held['financial']['scan']),'Finans taraması beklemede korunur');$held['isolatedOrderCount']=0;$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($held),$row['id']]);$reset();$sync->syncReadOnly($company,time()+40);
 $before=$db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload'];$rate=true;$reset();
 $queue=new SyncQueue($db,['Hepsiburada Satış'=>$sync]);$queue->enqueue($company,'Hepsiburada Satış');$queue->runOne(null,['Hepsiburada Satış'],time()+40);
 check($db->one("SELECT next_run FROM jobs WHERE provider='Hepsiburada Satış'")['next_run']>=time()+179,'Finans kuyruğu Retry-After uygular');
 check($db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload']===$before,'API hatası önceki finansal özeti korur');
 $rate=false;$invalid=true;$reset();fails(fn()=>$sync->syncReadOnly($company,time()+40),422);
 check($db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload']===$before,'Tutarsız tutar atomik olarak reddedilir');
 $invalid=false;$missing=true;$reset();$sync->syncReadOnly($company,time()+40);$s=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company])['payload'],true);
 check(isset($s['pendingOrders']['9912345679'])&&isset($s['financial']['scan']),'Eksik kaynak sipariş sıraya alınır, eksik özet yayımlanmaz');
 check($db->one('SELECT payload FROM records WHERE id=?',[$sale['id']])['payload']===$before,'Eksik siparişte son doğru tutar korunur');
 // A later refund belongs to the original order month, never to the refund payment month.
 $oldOrder=$order;$oldOrder['externalId']='8812345678';$oldOrder['createdAt']='2026-09-30T20:59:59Z';$oldClaim=[...$claim,'orderNumber'=>'8812345678','orderedAt'=>$oldOrder['createdAt'],'refundedAt'=>'2026-10-02T10:00:00+03:00'];
 $db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,'hepsiburada',?,?,?)",[$other,$oldOrder['externalId'],json_encode($oldOrder),gmdate('c')]);
 $db->transaction(fn()=>(new HepsiburadaSalesPublisher($db))->publish($other,'2026-09-01T00:00:00+03:00','2026-10-17T12:00:00+03:00',[$oldClaim]));
 $sales=$repo->records($other,'sales',['role'=>'admin']);$by=array_column($sales,null,'month');check($by['2026-09']['returnCents']===10005&&$by['2026-10']['returnCents']===0,'İade siparişin ayından düşülür; Türkiye ay sınırı');
 echo "Hepsiburada satışları: tutar, iptal/iade çakışması, statü, sayfalama, tekrar, firma/rol izolasyonu, kuyruk, atomiklik ve ay sınırı geçti.\n";
}finally{unlink($key);}

