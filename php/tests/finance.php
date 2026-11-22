<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,FinanceSchema,FinanceMapper,FinanceClient,FinanceStore,FinanceAdapter,IntegrationVault,PazaramaAuthClient,SyncFailure,Repository,PaymentCalendar,BackupArchive,NotificationSchema,SupportSchema,DeliverySchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function uuid(int $id):string{return sprintf('00000000-0000-0000-0000-%012d',$id);}
function archived(Database $db,int $company):array{return array_map(fn($r)=>\Omnigo\PaymentPolicy::decorate([...json_decode($r['payload'],true),'id'=>(int)$r['id']]),$db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='payments'",[$company]));}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($db);NotificationSchema::migrate($db);DeliverySchema::install($db);FinanceSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Firma A');$other=$repo->createCompany('Firma B');
$zone=new DateTimeZone('Europe/Istanbul');$today=new DateTimeImmutable('today',$zone);$day=$today->format('Y-m-d');$expected=$today->modify('+10 days')->format('Y-m-d');
$raw=['trxId'=>uuid(1),'transactionDate'=>$day.'T00:00:00','transferredDate'=>$expected.'T00:00:00','allowanceAmount'=>22569.91,'commissionAmount'=>-2534.93,'amount'=>25329,'status'=>'Satış'];
$mapped=FinanceMapper::pazarama($raw);check($mapped['netCents']===2256991&&$mapped['state']==='pending','Net hakedişten komisyon ikinci kez düşülmez');
 $refund=FinanceMapper::pazarama([...$raw,'allowanceAmount'=>-1000,'status'=>'İade']);check($refund['key']!==$mapped['key'],'Aynı trxId altındaki iade satıştan ayrı hareket olmalı');
 check(FinanceMapper::pazarama([...$raw,'status'=>'Ödeme Yapıldı'])['state']==='sent','Resmi ödeme bildirimi bekleyende kalmamalı');
check(FinanceMapper::pazarama([...$raw,'transferredDate'=>null,'allowanceAmount'=>-1200])['expectedDate']==='','Bilinmeyen tarih uydurulmaz');
$k=FinanceMapper::koctas(['id'=>'cycle-1','currency_iso_code'=>'TRY','date_created'=>$day.'T00:00:00Z','amount_transferred_to_seller'=>65565.15,'pay_out'=>['state'=>'PAID','transaction_date'=>$day.'T00:00:00Z']]);check($k['state']==='sent','Pazaryeri gönderimi banka teyidi değildir');
$key=tempnam(sys_get_temp_dir(),'finance-key');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$vault->save($company,['sellerId'=>uuid(2),'apiKey'=>'fake','apiSecret'=>'fake','enabled'=>true],'pazarama');
 $token='a.'.base64_encode(json_encode(['client_seller_id'=>uuid(2)])).'.b';$auth=new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600])]);
 $requests=[];$fail=false;$reader=new FinanceClient(function($method,$url,$headers,$body)use(&$requests,&$fail,$raw,$day){$q=json_decode($body,true);$requests[]=$q;check($method==='POST'&&str_ends_with($url,'/order/paymentAgreement'),'Finans yalnızca resmi okuma servisine gider');if($fail)return ['status'=>429,'retryAfter'=>7200];$rows=substr($q['startDate'],0,10)<=$day&&substr($q['endDate'],0,10)>=$day?[$raw,[...$raw,'allowanceAmount'=>-1200,'status'=>'İade']]:[];return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['transactionList'=>$rows]])];});
 $adapter=new FinanceAdapter($db,$vault,'pazarama',$reader,$auth);
 for($i=0;$i<4;$i++){$adapter->syncReadOnly($company,time()+30);if($i<3)check(!archived($db,$company),'Eksik tarama ödeme toplamını değiştirmez');$row=(new FinanceStore($db))->state($company,'pazarama');$state=json_decode($row['payload'],true);$state['nextRequestAt']=0;$db->run('UPDATE finance_sync SET payload=? WHERE company_id=? AND provider=?',[json_encode($state),$company,'pazarama']);}
 $payments=archived($db,$company);check(count($payments)===1&&$payments[0]['expectedCents']===2136991,'Aynı tarihin artı eksi hareketleri net toplanır');check($payments[0]['actualCents']===null&&$payments[0]['status']==='pending','Hesaba geçiş otomatik doğrulanmaz');
 check(!$repo->records($other,'payments',['role'=>'admin']),'Firma finans kayıtları ayrıdır');
 $fail=true;try{$adapter->syncReadOnly($company,time()+30);throw new RuntimeException('Hata bekleniyordu');}catch(SyncFailure $e){check($e->retryAfter>=7200,'Retry After korunur');}check(archived($db,$company)[0]['expectedCents']===2136991,'API hatası mevcut hakedişi korur');
 $report=(new PaymentCalendar($db))->report(['role'=>'admin'],$company,$today->format('Y-m-01'),$today->modify('last day of this month')->format('Y-m-d'));if(substr($expected,0,7)===$today->format('Y-m'))check($report['summary']['confirmedCents']===0,'Finans arşivi manuel hakediş takvimine karışmaz');
 $manual=['channel'=>'n11','expectedDate'=>$day,'expectedCents'=>12345,'status'=>'pending','source'=>'Manuel','paidDate'=>''];$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,json_encode($manual),gmdate('c')]);
 $db->transaction(fn()=>(new FinanceStore($db))->publish($company,'pazarama',[$mapped],$day,$day));$db->transaction(fn()=>(new FinanceStore($db))->publish($company,'pazarama',[$mapped],$day,$day));check(count(archived($db,$company))===2,'Tekrarlanan import çoğalmaz ve elle girilen kayıt korunur');
 $archive=BackupArchive::read(BackupArchive::create($db,str_repeat('x',32)),str_repeat('x',32));check(isset($archive['tables']['finance_entries'],$archive['tables']['finance_sync']),'Finans verisi şifreli yedekte');
 $vault->save($company,['apiKey'=>'00000000-0000-0000-0000-000000000000','shopId'=>123,'enabled'=>true],'koctas');$calls=0;
 $koctas=new FinanceClient(function($method,$url)use(&$calls,$day){$calls++;check($method==='GET'&&str_contains($url,'/api/seller-billing-cycles?')&&str_contains($url,'shop_id=123'),'Koçtaş doğru mağaza kapsamı');return ['status'=>200,'body'=>json_encode(['data'=>[['id'=>'cycle-live','currency_iso_code'=>'TRY','date_created'=>$day.'T00:00:00Z','amount_transferred_to_seller'=>65565.15,'pay_out'=>['state'=>'PAID','transaction_date'=>$day.'T00:00:00Z']]]])];});
 $daily=new FinanceAdapter($db,$vault,'koctas',$koctas);$daily->syncReadOnly($company,time()+30);$daily->syncReadOnly($company,time()+30);check($calls===1&&$daily->nextDelay()>86000,'Koçtaş gün içinde tekrar sorgulanmaz');
 echo "Finans: net tutar, kesinti, bilinmeyen tarih, banka ayrımı, kuyruk/rate limit, atomik tarama, firma izolasyonu, takvim ve yedek uyumu geçti.\n";
}finally{unlink($key);}

check(!array_filter($repo->records($company,'payments',['role'=>'admin']),fn($p)=>!empty($p['integration'])),'Finans arşivi manuel hakediş ekranına karışmaz');
