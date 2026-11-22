<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,N11Connection,N11Credentials,N11ReadClient,N11Preview,N11ProbeAdapter,SyncQueue,SyncFailure,HttpError,ConnectionHealth,IntegrationRoutes};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $call,int $code):void{try{$call();}catch(SyncFailure|HttpError $error){check(($error instanceof SyncFailure?$error->httpStatus:$error->getCode())===$code,'Hata kodu');return;}throw new RuntimeException('Hata bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new Repository($db);$company=$repo->createCompany('n11 test firması');$other=$repo->createCompany('Diğer firma');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['admin@example.test','Test','unused','admin']);$user=$db->insertId();
$key=tempnam(sys_get_temp_dir(),'n11-test-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new N11Connection($db,$vault);
 $input=['apiKey'=>'fake-key','apiSecret'=>'fake-secret','version'=>0];
 $connection->save($company,$input,$user);$vault->save($company,['apiKey'=>'untouched-koctas']);
 check(!str_contains($db->one("SELECT payload FROM records WHERE kind='integration_n11'")['payload'],'fake'),'Şifreli saklama');
 check(!str_contains(json_encode($connection->status($company)),'fake'),'Sırların API yanıtına çıkmaması');
 check($vault->get($other,'n11')===null,'Firma izolasyonu');
 fails(fn()=>$connection->save($company,$input,$user),409);
 foreach(["line\r\ninjection",'','invalid key'] as $value){try{N11Credentials::fromArray(['apiKey'=>$value,'apiSecret'=>'valid']);throw new RuntimeException('Geçersiz anahtar kabul edildi');}catch(InvalidArgumentException){}}
 $package=['orderNumber'=>'123456789012345678901','id'=>null,'sellerId'=>123,'shipmentPackageStatus'=>'Picking','customerEmail'=>'PRIVATE-DO-NOT-STORE',
  'lines'=>[['orderLineId'=>999,'quantity'=>2,'productName'=>'Ürün','stockCode'=>'SKU','sellerInvoiceAmount'=>'579.80','orderItemLineItemStatusName'=>'Picking']]];
 $calls=0;$reader=new N11ReadClient(function($url,$headers,$deadline)use(&$calls,$package){
  $calls++;$parts=parse_url($url);parse_str($parts['query'],$q);
  check($parts['scheme']==='https'&&$parts['host']==='api.n11.com'&&$parts['path']==='/rest/delivery/v1/shipmentPackages','Sabit salt okunur adres');
  check($q['size']==='100'&&$q['page']==='0'&&$q['endDate']-$q['startDate']===7*86400000,'Sayfa ve süre sınırları');
  check(in_array('appKey: fake-key',$headers,true)&&in_array('appSecret: fake-secret',$headers,true),'API kimlik doğrulaması');
  return ['status'=>200,'body'=>json_encode(['totalPages'=>3,'page'=>0,'content'=>[$package]])];
 });
 $adapter=new N11ProbeAdapter($db,$vault,$reader);$queue=new SyncQueue($db,['n11'=>$adapter]);$queue->runOne();
 $status=$connection->status($company);check($status['sync']['phase']==='review_ready','Başarılı okuma');
 check($status['sync']['preview']['packages'][0]['invoiceCents']===57980,'Satır toplamı adetle yeniden çarpılmaz');
 check($status['sync']['totalPages']===3&&$status['sync']['sampleOnly']&& !$status['sync']['salesPublished'],'Örnek veri toplam sayılmaz');
 check(!str_contains(json_encode($status),'PRIVATE'),'Müşteri kişisel bilgileri atılmalı');
 check($status['sellerId']==='123','API mağaza numarası');
 check($db->one("SELECT id FROM records WHERE kind='sales'")===null,'Doğrulanmamış örnek müşteri cirosuna yazılamaz');
 $db->run("UPDATE jobs SET next_run=0 WHERE provider='n11'");$queue->runOne();check($calls===1,'Hazır örnek tekrar sorgulanmaz');
 check($vault->get($company)['apiKey']==='untouched-koctas','Diğer bağlantı korunmalı');
 $channels=IntegrationRoutes::channels($db,$company,$repo->records($company,'channels',['role'=>'client']));
 check(array_values(array_filter($channels,fn($r)=>$r['name']==='n11'))[0]['connection']==='review','Müşteri bağlantı durumu');
 $health=(new ConnectionHealth($db))->report(['role'=>'admin']);check(count(array_filter($health['items'],fn($r)=>$r['channel']==='n11'&&$r['status']==='probe'))===1,'Yönetici bağlantı kontrolü');
 $db->run("UPDATE jobs SET state='running',lease_until=? WHERE provider='n11'",[time()+300]);fails(fn()=>$connection->save($company,[...$input,'version'=>1],$user),409);
 $db->run("UPDATE jobs SET state='failed',next_run=? WHERE provider='n11'",[time()+7200]);$connection->retry($company);
 check($db->one("SELECT next_run FROM jobs WHERE provider='n11'")['next_run']>=time()+7199,'Retry-After korunmalı');
 $rate=new N11ReadClient(fn()=>['status'=>429,'retryAfter'=>7200]);
 try{$rate->packages(N11Credentials::fromArray($input),1000,2000,0,time()+20);throw new RuntimeException('429 bekleniyordu');}catch(SyncFailure $error){check($error->retryAfter===7200,'Sunucu bekleme süresi');}
 fails(fn()=>N11Preview::map([$package,[...$package,'sellerId'=>456]]),422);
 fails(fn()=>N11Preview::map([[...$package,'lines'=>[['quantity'=>2,'sellerInvoiceAmount'=>-1]]]]),422);
 fails(fn()=>(new N11ReadClient(fn()=>['status'=>200,'body'=>'{"message":"failure"}']))->packages(N11Credentials::fromArray($input),1000,2000,0,time()+20),422);
 // An API credential changed while a slow request was in flight must not publish the old response.
 $db->run("UPDATE jobs SET next_run=0,state='pending' WHERE provider='n11'");$connection->retry($company);
 $slow=new N11ReadClient(function()use($vault,$company,$package){$vault->save($company,['enabled'=>true,'revision'=>'changed','apiKey'=>'new','apiSecret'=>'new'],'n11');return ['status'=>200,'body'=>json_encode(['totalPages'=>1,'content'=>[$package]])];});
 fails(fn()=>(new N11ProbeAdapter($db,$vault,$slow))->syncReadOnly($company,time()+20),409);
 echo "n11: şifreleme, firma izolasyonu, salt okuma, tutar kontrolü, kuyruk, hız sınırı ve eski yanıt koruması geçti.\n";
}finally{unlink($key);}
