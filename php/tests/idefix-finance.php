<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,FinanceSchema,FinanceStore,IntegrationVault,IdefixFinanceAdapter,IdefixFinanceMapper,PaymentPolicy,PaymentCalendar,SyncFailure,PttavmReturnProjection,ReturnRequestList};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function archived(Database $db,int $company):array{return array_map(fn($r)=>\Omnigo\PaymentPolicy::decorate([...json_decode($r['payload'],true),'id'=>(int)$r['id']]),$db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='payments'",[$company]));}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));FinanceSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$key=tempnam(sys_get_temp_dir(),'idefix-finance');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$vault->save($company,['enabled'=>true,'vendorId'=>'1','apiKey'=>'test','apiSecret'=>'test','revision'=>'a'],'idefix');
 $stamp=gmdate('c');$month=gmdate('Y-m');$from=$month.'-01T00:00:00+00:00';
 $line=['lineId'=>'1','earningCents'=>12000,'grossCents'=>15000,'cancelCents'=>0,'returnCents'=>0,'status'=>'delivered','quantity'=>1,'title'=>'Ürün'];
 $order=['externalId'=>'IDE1','createdAt'=>$from,'refundsVerified'=>true,'products'=>[$line,[...$line,'lineId'=>'2','cancelCents'=>15000],[...$line,'lineId'=>'3','returnCents'=>15000]]];
 $put=function($kind,$value)use($db,$company,$stamp){$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($value),$stamp]);};
 $put('order_idefix',$order);$put('sync_idefix',['ordersPublished'=>true,'refundsVerified'=>true,'startDate'=>$from,'watermark'=>$stamp,'lastSuccessAt'=>$stamp,'orderCount'=>1]);
 $put('channels',['name'=>'idefix','status'=>'active','storeName'=>'Örnek']);
 IdefixFinanceAdapter::schedule($db,$vault);check(!$db->one("SELECT state FROM jobs WHERE provider='idefix Hakediş'"),'Manuel hakediş düzeninde otomatik finans kuyruğu oluşturulmaz');
 $adapter=new IdefixFinanceAdapter($db,$vault);$adapter->syncReadOnly($company,time()+20);$adapter->syncReadOnly($company,time()+20);
 $payments=archived($db,$company);$payments=array_values(array_filter($payments,fn($p)=>($p['integration']??'')==='idefix'));
 check(count($payments)===1&&$payments[0]['expectedCents']===12000,'Tekrar taramada mükerrer olmaz; iptal ve iadeler düşer');
 check($payments[0]['paymentStateUnknown']&&$payments[0]['excludedFromTotals']&&$payments[0]['expectedDate']===''&&$payments[0]['actualCents']===null,'Ödeme tarihi, bekleyen bakiye ve banka teyidi uydurulmaz');
 $report=(new PaymentCalendar($db))->report(['role'=>'admin'],$company,$month.'-01',gmdate('Y-m-t'));
 check(count($report['earningsItems'])===0&&!$report['undatedItems']&&array_sum($report['summary'])===0,'Sipariş hakedişi ödeme ve takvim toplamına karışmaz');
 $order['products'][0]['earningCents']=null;$db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='order_idefix'",[json_encode($order),$company]);
 try{$adapter->syncReadOnly($company,time()+20);throw new RuntimeException('Eksik kaynak yayımlandı');}catch(SyncFailure $e){check($e->httpStatus===503&&$e->retryAfter===60,'Eksik kaynak kısa aralıkla yeniden denenir');}
 $after=archived($db,$company);check(array_values(array_filter($after,fn($p)=>($p['integration']??'')==='idefix'))===$payments,'Eksik kaynak son doğru hakedişi bozmaz');
 check(IdefixFinanceMapper::amount(['earningAmount'=>0])===0&&IdefixFinanceMapper::amount([])===null,'Sıfır ve eksik tutar ayrılır');
 $returned=[...$order,'externalId'=>'PTT1','products'=>[[...$line,'status'=>'iade','returnCents'=>15000],[...$line,'lineId'=>'2','status'=>'tamamlandi']]];
 $projection=PttavmReturnProjection::orders([$returned]);check(count($projection)===1&&$projection[0]['createdAt']===null&&$projection[0]['amountCents']===15000,'PTT iadesi ürün bazında, uydurma talep tarihi olmadan görünür');
 $put('order_pttavm',$returned);$put('sync_pttavm',['lastSuccessAt'=>$stamp,'startDate'=>$from,'watermark'=>$stamp]);
 $list=(new ReturnRequestList($db))->listing(['role'=>'admin'],$company,['provider'=>'pttavm']);check($list['total']===1&&$list['sources'][0]['coverage']==='order_status_returns'&&$list['items'][0]['group']!=='refunded','PTT kaydı listelenir; banka iadesi sayılmaz');
 echo "İdefix hakediş: kuyruk, tekillik, iptal/iade, eksik kaynakta koruma ve ödeme ayrımı; PTT iade kapsamı kontrolleri geçti.\n";
}finally{unlink($key);}

check(!array_filter($repo->records($company,'payments',['role'=>'admin']),fn($p)=>!empty($p['integration'])),'Finans arşivi manuel hakediş ekranına karışmaz');
