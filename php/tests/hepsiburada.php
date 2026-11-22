<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,HepsiburadaConnection,HepsiburadaCredentials,HepsiburadaReadClient,HepsiburadaPreview,HepsiburadaProbeAdapter,SyncQueue,SyncFailure,HttpError,ConnectionHealth,IntegrationRoutes};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $call,int $code):void{try{$call();}catch(SyncFailure|HttpError $error){check(($error instanceof SyncFailure?$error->httpStatus:$error->getCode())===$code,'Hata kodu');return;}throw new RuntimeException('Hata bekleniyordu.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new Repository($db);$company=$repo->createCompany('HB test firması');$other=$repo->createCompany('Diğer firma');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['admin@example.test','Test','unused','admin']);$user=$db->insertId();
$key=tempnam(sys_get_temp_dir(),'hb-test-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new HepsiburadaConnection($db,$vault);
 $input=['merchantId'=>'12345678-1234-1234-1234-123456789012','username'=>'fake-user','serviceKey'=>'fake-secret','userAgent'=>'test-user','version'=>0];
 $connection->save($company,$input,$user);$vault->save($company,['apiKey'=>'untouched-koctas']);
 check(!str_contains($db->one("SELECT payload FROM records WHERE kind='integration_hepsiburada'")['payload'],'fake'),'Şifreli saklama');
 check(!str_contains(json_encode($connection->status($company)),'fake'),'Sırlar API yanıtına çıkmamalı');
 check($vault->get($other,'hepsiburada')===null,'Firma izolasyonu');
 fails(fn()=>$connection->save($company,$input,$user),409);
 foreach(['username'=>"line\r\ninjection",'serviceKey'=>'','merchantId'=>'../escape','userAgent'=>"new\nheader"] as $field=>$value){try{HepsiburadaCredentials::fromArray([...$input,$field=>$value]);throw new RuntimeException('Geçersiz alan kabul edildi');}catch(InvalidArgumentException){}}
 $item=['id'=>'line-1','orderNumber'=>'0123456789','merchantId'=>$input['merchantId'],'quantity'=>2,'status'=>'Open',
  'sku'=>'HBSKU','name'=>'Ürün','totalPrice'=>['currency'=>'TRY','amount'=>'579.80'],'customerName'=>'PRIVATE-DO-NOT-STORE'];
 $calls=0;$reader=new HepsiburadaReadClient(function($url,$headers)use(&$calls,$item){
  $calls++;$parts=parse_url($url);parse_str($parts['query'],$q);
  check($parts['scheme']==='https'&&$parts['host']==='oms-external.hepsiburada.com'&&str_starts_with($parts['path'],'/orders/merchantid/'),'Sabit salt okunur adres');
  check($q===['offset'=>'0','limit'=>'10'],'Sınırlı örnek okuma');
  check(in_array('User-Agent: test-user',$headers,true)&&in_array('Authorization: Basic '.base64_encode('fake-user:fake-secret'),$headers,true),'Yetki başlıkları');
  return ['status'=>200,'body'=>json_encode(['totalCount'=>15,'items'=>[$item]])];
 });
 $queue=new SyncQueue($db,['Hepsiburada'=>new HepsiburadaProbeAdapter($db,$vault,$reader)]);$queue->runOne();
 $status=$connection->status($company);check($status['sync']['phase']==='review_ready'&&$status['sync']['merchantVerified'],'Mağaza eşleşmeli başarılı okuma');
 check($status['sync']['preview']['items'][0]['amountCents']===57980,'Satır toplamı yeniden adetle çarpılmaz');
 check($status['sync']['totalCount']===15&&$status['sync']['sampleOnly']&&!$status['sync']['salesPublished'],'Örnekler aylık satış sayılmaz');
 check(!str_contains(json_encode($status),'PRIVATE'),'Kişisel bilgiler atılmalı');
 check($db->one("SELECT id FROM records WHERE kind='sales'")===null,'Örnek müşteri cirosuna yazılamaz');
 $db->run("UPDATE jobs SET next_run=0 WHERE provider='Hepsiburada'");$queue->runOne();check($calls===1,'Hazır örnek tekrar sorgulanmaz');
 check($vault->get($company)['apiKey']==='untouched-koctas','Diğer bağlantı korunmalı');
 $channels=IntegrationRoutes::channels($db,$company,$repo->records($company,'channels',['role'=>'client']));
 check(array_values(array_filter($channels,fn($r)=>$r['name']==='Hepsiburada'))[0]['connection']==='review','Müşteri bağlantı durumu');
 $health=(new ConnectionHealth($db))->report(['role'=>'admin']);check(count(array_filter($health['items'],fn($r)=>$r['channel']==='Hepsiburada'))===2,'Hepsiburada iki şirketin bağlantı kontrolünde görünür');
 $db->run("UPDATE jobs SET state='running',lease_until=? WHERE provider='Hepsiburada'",[time()+300]);fails(fn()=>$connection->save($company,[...$input,'version'=>1],$user),409);
 $db->run("UPDATE jobs SET state='failed',next_run=? WHERE provider='Hepsiburada'",[time()+7200]);$connection->retry($company);
 check($db->one("SELECT next_run FROM jobs WHERE provider='Hepsiburada'")['next_run']>=time()+7199,'Retry-After korunmalı');
 $rate=new HepsiburadaReadClient(fn()=>['status'=>429,'retryAfter'=>7200]);
 try{$rate->openOrders(HepsiburadaCredentials::fromArray($input),time()+20);throw new RuntimeException('429 bekleniyordu');}catch(SyncFailure $error){check($error->retryAfter===7200,'Sunucu bekleme süresi');}
 fails(fn()=>HepsiburadaPreview::map([[...$item,'merchantId'=>'other']],$input['merchantId']),422);
 fails(fn()=>HepsiburadaPreview::map([$item,$item],$input['merchantId']),422);
 fails(fn()=>HepsiburadaPreview::map([[...$item,'totalPrice'=>['currency'=>'EUR','amount'=>10]]],$input['merchantId']),422);
 check(!HepsiburadaPreview::map([],$input['merchantId'])['merchantVerified'],'Boş örnek mağazayı doğrulamaz');
 fails(fn()=>(new HepsiburadaReadClient(fn()=>['status'=>200,'body'=>'{"message":"failure"}']))->openOrders(HepsiburadaCredentials::fromArray($input),time()+20),422);
 $db->run("UPDATE jobs SET next_run=0,state='pending' WHERE provider='Hepsiburada'");$connection->retry($company);
 $slow=new HepsiburadaReadClient(function()use($vault,$company,$item,$input){$vault->save($company,[...$input,'enabled'=>true,'revision'=>'changed'],'hepsiburada');return ['status'=>200,'body'=>json_encode(['totalCount'=>1,'items'=>[$item]])];});
 fails(fn()=>(new HepsiburadaProbeAdapter($db,$vault,$slow))->syncReadOnly($company,time()+20),409);
 echo "Hepsiburada: şifreleme, firma izolasyonu, salt okuma, mağaza eşleşmesi, örnek veri sınırı ve kuyruk kontrolleri geçti.\n";
}finally{unlink($key);}
