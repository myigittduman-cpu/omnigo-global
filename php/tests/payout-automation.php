<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,FinanceSchema,FinanceMapper,FinanceClient,FinanceStore,PaymentCalendar,IntegrationVault,HepsiburadaFinanceAdapter,HepsiburadaCredentials,SyncFailure,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function archived(Database $db,int $company):array{return array_map(fn($r)=>\Omnigo\PaymentPolicy::decorate([...json_decode($r['payload'],true),'id'=>(int)$r['id']]),$db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='payments'",[$company]));}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));FinanceSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$other=$repo->createCompany('Diğer');$store=new FinanceStore($db);
$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul'));$day=$today->format('Y-m-d');$from=$today->format('Y-m-01');$to=$today->modify('last day of this month')->format('Y-m-d');
$raw=['trxId'=>'00000000-0000-0000-0000-000000000001','transactionDate'=>$day.'T00:00:00','transferredDate'=>null,'allowanceAmount'=>10900.37,'amount'=>10900.37,'status'=>'Erken Ödemeden Kalan Tutar'];
$entry=FinanceMapper::pazarama($raw);$db->transaction(fn()=>$store->publish($company,'pazarama',[$entry],$day,$day));
$calendar=new PaymentCalendar($db);$report=$calendar->report(['role'=>'client','company_id'=>$company],$company,$from,$to);
check(count($report['undatedItems'])===0&&$report['undatedCents']===0&&!$report['items']&&$report['summary']['confirmedCents']===0,'Tarihsiz API tutarı manuel takvimde görünmez');
check($calendar->report(['role'=>'client','company_id'=>$company],$company,$from,$to,'n11')['undatedCents']===0,'Kanal filtresi');
check($calendar->report(['role'=>'client','company_id'=>$other],$other,$from,$to)['undatedCents']===0,'Firma izolasyonu');
$dated=FinanceMapper::pazarama([...$raw,'transferredDate'=>$day.'T00:00:00']);check($dated['key']===$entry['key'],'Tarih atanması aynı hareket kimliğini korur');
for($i=0;$i<2;$i++)$db->transaction(fn()=>$store->publish($company,'pazarama',[$dated],$day,$day));
$report=$calendar->report(['role'=>'client','company_id'=>$company],$company,$from,$to);check(!$report['undatedItems']&&count($report['items'])===0&&$report['summary']['confirmedCents']===0,'Tarih atanan API kaydı arşivde kalır; manuel takvimi etkilemez');
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,json_encode(['channel'=>'n11','expectedDate'=>$day,'expectedCents'=>99999,'status'=>'sent','source'=>'Eski kayıt','paidDate'=>'']),gmdate('c')]);
$report=$calendar->report(['role'=>'admin'],$company,$from,$to);check(!$report['legacyItems']&&count($report['items'])===1&&$report['summary']['sentCents']===99999,'Eski manuel hakediş korunur, güncel toplam şişmez');
check($repo->records($company,'payments',['role'=>'admin'])[0]['expectedCents']===99999,'Manuel hakediş arşivden ayrı olarak görünür');
$merchant='00000000-0000-0000-0000-000000000002';$credentials=HepsiburadaCredentials::fromArray(['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'fake','userAgent'=>'example_dev']);
$hb=['id'=>'movement-1','merchantId'=>$merchant,'transactionType'=>'Payment','status'=>'WillBePaid','amount'=>['value'=>120,'currencyCode'=>'949'],'netAmount'=>['value'=>100,'currencyCode'=>'949'],'isIncome'=>true,'dueDate'=>$day.'T00:00:00','paymentDate'=>null];
check(FinanceMapper::hepsiburada($hb)['netCents']===12000,'KDV hariç netAmount yanlışlıkla hakediş sayılmaz');
check(FinanceMapper::hepsiburada([...$hb,'id'=>'fee','transactionType'=>'Commission','isIncome'=>false,'amount'=>['value'=>-20,'currencyCode'=>'949']])['netCents']===-2000,'Komisyon işareti ikinci kez çevrilmez');
check(FinanceMapper::hepsiburada([...$hb,'status'=>'Paid','paymentDate'=>$day.'T00:00:00'])===null,'Ödenen bileşen aktarım toplamına ikinci kez eklenmez');
$transfer=FinanceMapper::hepsiburada([...$hb,'transactionType'=>'TotalPayment','status'=>'Paid','paymentDate'=>$day.'T00:00:00','amount'=>['value'=>-712448.29,'currencyCode'=>'949']]);
check($transfer['state']==='sent'&&$transfer['netCents']===71244829,'TotalPayment tek transferdir, banka teyidi sayılmaz');
$wrong=new FinanceClient(fn()=>['status'=>200,'body'=>json_encode(['count'=>1,'items'=>[[...$hb,'merchantId'=>'other']]])]);
try{$wrong->hepsiburada($credentials,$from,$to,0,time()+20);throw new RuntimeException('Kimlik reddi bekleniyordu');}catch(SyncFailure $e){check($e->httpStatus===403,'Mağaza kimliği doğrulanır');}
$key=tempnam(sys_get_temp_dir(),'payout-key');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$vault->save($company,['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'fake','userAgent'=>'example_dev','enabled'=>true],'hepsiburada');
 $calls=0;$failure=false;
 $reader=new FinanceClient(function($method,$url,$headers)use(&$calls,&$failure,$hb,$day){
  $calls++;check($method==='GET'&&str_starts_with($url,'https://mpfinance-external.hepsiburada.com/transactions/merchantid/'),'Yalnız resmi salt okunur uç nokta');
  if($failure)return ['status'=>429,'retryAfter'=>900];parse_str(parse_url($url,PHP_URL_QUERY),$q);
  check((new DateTimeImmutable($q['DueDateStart']))->diff(new DateTimeImmutable($q['DueDateEnd']))->days<=29,'30 günlük pencere sınırı');
  $count=$q['DueDateStart']<=$day&&$q['DueDateEnd']>=$day?105:0;$rows=[];
  for($i=(int)$q['Offset'];$i<min($count,(int)$q['Offset']+100);$i++)$rows[]=[...$hb,'id'=>'movement-'.$i];
  return ['status'=>200,'body'=>json_encode(['count'=>$count,'items'=>$rows])];
 });
 $adapter=new HepsiburadaFinanceAdapter($db,$vault,$reader);
 $reset=function()use($db,$store,$company){$s=json_decode($store->state($company,'hepsiburada')['payload'],true);$s['nextRequestAt']=0;$db->run("UPDATE finance_sync SET payload=? WHERE company_id=? AND provider='hepsiburada'",[json_encode($s),$company]);};
 $adapter->syncReadOnly($company,time()+30);check(count(array_filter(archived($db,$company),fn($p)=>($p['integration']??null)==='hepsiburada'))===0,'Yarım tarama görünür toplamı değiştirmez');
 for($i=0;$i<4;$i++){$reset();$adapter->syncReadOnly($company,time()+30);$s=json_decode($store->state($company,'hepsiburada')['payload'],true);if($s['phase']==='ready')break;}
 check($s['phase']==='ready','Sayfalı tarama tamamlanır');$payments=archived($db,$company);$api=array_values(array_filter($payments,fn($p)=>($p['integration']??null)==='hepsiburada'));check(count($api)===1&&$api[0]['expectedCents']===1260000,'Tam API taraması net hareketleri toplar');
 $failure=true;$reset();try{$adapter->syncReadOnly($company,time()+30);throw new RuntimeException('Hata bekleniyordu');}catch(SyncFailure $e){check($e->retryAfter>=900,'Kota beklemesi korunur');}
 check(archived($db,$company)===$payments,'Kesintide son başarılı hakedişler korunur');
 check($calendar->report(['role'=>'admin'],$company,$from,$to)===$calendar->report(['role'=>'client','company_id'=>$company],$company,$from,$to),'İki rol aynı hakediş ve tarihsiz ödeme sonucunu alır');
 echo "Finans arşivi ve manuel hakediş ayrımı: resmi uç nokta, mağaza izolasyonu, sayfalama, atomik yayın, kota/kesinti, tarih atama, mükerrer koruması, eski kayıt ayrımı ve iki rol eşitliği geçti.\n";
}finally{unlink($key);}

check(!array_filter($repo->records($company,'payments',['role'=>'admin']),fn($p)=>!empty($p['integration'])),'Finans arşivi manuel hakediş ekranına karışmaz');
