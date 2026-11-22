<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,FinanceSchema,FinanceMapper,FinanceStore,IntegrationVault,N11FinanceClient,N11FinanceAdapter,SyncFailure};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function response(array $rows,int $total=0,int $page=0):string{
 $xml='<Envelope><Body><GetSettlementListResponse><result><status>success</status></result><settlementListData><metadata><currentPage>'.$page.'</currentPage><pageSize>100</pageSize><totalCount>'.$total.'</totalCount><pageCount>'.ceil($total/100).'</pageCount></metadata>';
 foreach($rows as $row){$xml.='<settlementList>';foreach($row as $k=>$v)$xml.='<'.$k.'>'.htmlspecialchars((string)$v,ENT_XML1).'</'.$k.'>';$xml.='</settlementList>';}
 return $xml.'</settlementListData></GetSettlementListResponse></Body></Envelope>';
}
$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul'));$day=$today->format('Y-m-d');
$raw=['settlementDate'=>$day,'remittanceDate'=>$day,'paymentAmount'=>'103414','deductionAmount'=>'13311.46','settlementAmount'=>'90102.54','status'=>'Success'];
$entry=FinanceMapper::n11($raw);check($entry['netCents']===9010254&&$entry['state']==='sent','Net uzlaşma kullanılır, banka teyidi sayılmaz');
$pending=FinanceMapper::n11([...$raw,'status'=>'Wait_Remittance','remittanceDate'=>'']);check($pending['key']===$entry['key']&&$pending['state']==='pending','Tarih/durum değişse de kimlik değişmez');
check(FinanceMapper::n11([...$raw,'status'=>'NotConfirmed'])['state']==='estimated','Onaylanmamış ödeme teyitli sayılmaz');
check(N11FinanceClient::parse(response([$raw],1),0)['entries'][0]===$entry,'Resmi SOAP yanıtı çözümlenir');
foreach([fn()=>FinanceMapper::n11([...$raw,'settlementAmount'=>'90102.55']),fn()=>FinanceMapper::n11([...$raw,'status'=>'UNKNOWN']),fn()=>N11FinanceClient::parse(response([$raw],2),0),fn()=>N11FinanceClient::parse('<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x/>',0)] as $reject){try{$reject();throw new RuntimeException('Ret bekleniyordu');}catch(SyncFailure $e){check($e->httpStatus===422,'Hatalı veri reddi');}}
function archived(Database $db,int $company):array{return array_map(fn($r)=>\Omnigo\PaymentPolicy::decorate([...json_decode($r['payload'],true),'id'=>(int)$r['id']]),$db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='payments'",[$company]));}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));FinanceSchema::install($db);$repo=new Repository($db);$company=$repo->createCompany('Örnek');$store=new FinanceStore($db);
$key=tempnam(sys_get_temp_dir(),'n11-finance');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$vault->save($company,['enabled'=>true,'apiKey'=>'test','apiSecret'=>'test'],'n11');$failure=false;$duplicate=false;
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_n11','{}',gmdate('c')]);
 N11FinanceAdapter::schedule($db,$vault);
 $db->run("UPDATE jobs SET state='failed',attempts=5,next_run=? WHERE company_id=? AND provider='n11 Hakediş'",[time()-1900,$company]);
 N11FinanceAdapter::schedule($db,$vault);
 check(!$db->one("SELECT id FROM jobs WHERE company_id=? AND provider='n11 Hakediş'",[$company]),'Manuel hakediş düzeninde otomatik finans işi oluşturulmaz');
$db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(?,'n11 Hakediş','blocked',0)",[$company]);
 $db->run("UPDATE jobs SET state='blocked' WHERE company_id=? AND provider='n11 Hakediş'",[$company]);
 N11FinanceAdapter::schedule($db,$vault);
 check($db->one("SELECT state FROM jobs WHERE company_id=? AND provider='n11 Hakediş'",[$company])['state']==='blocked','Yetki engeli otomatik kaldırılmaz');
 $reader=new N11FinanceClient(function($url,$xml)use($day,$raw,&$failure,&$duplicate){
  check($url==='https://api.n11.com/ws/settlementService/'&&str_contains($xml,'GetSettlementListRequest'),'Yalnız resmi listeleme metodu');
  if($failure)return ['status'=>429,'retryAfter'=>900];$doc=new DOMDocument();$doc->loadXML($xml);$xp=new DOMXPath($doc);
  $from=$xp->evaluate('string(//*[local-name()="startDate"])');$to=$xp->evaluate('string(//*[local-name()="endDate"])');
  $rows=$from<=$day&&$to>=$day?[$raw]:[];if($duplicate&&$rows)$rows[]=$raw;
  return ['status'=>200,'body'=>response($rows,count($rows))];
 });
 $adapter=new N11FinanceAdapter($db,$vault,$reader);
 $reset=function()use($db,$store,$company){$s=json_decode($store->state($company,'n11')['payload'],true);$s['nextRequestAt']=0;$db->run("UPDATE finance_sync SET payload=? WHERE company_id=? AND provider='n11'",[json_encode($s),$company]);};
 $adapter->syncReadOnly($company,time()+20);check(!archived($db,$company),'Yarım tarama görünür kayda dönüşmez');
 for($i=0;$i<4;$i++){$reset();$adapter->syncReadOnly($company,time()+20);$s=json_decode($store->state($company,'n11')['payload'],true);if($s['phase']==='ready')break;}
 check($s['phase']==='ready','Tam tarama tamamlanır');$payments=archived($db,$company);check(count($payments)===1&&$payments[0]['expectedCents']===9010254,'Doğru net hakediş yayınlanır');
 $db->transaction(fn()=>$store->publish($company,'n11',[$pending],$day,$day));$db->transaction(fn()=>$store->publish($company,'n11',[$entry],$day,$day));check(count(archived($db,$company))===1,'Durum/tarih değişiminde mükerrer oluşmaz');
 $payments=archived($db,$company);$failure=true;$reset();try{$adapter->syncReadOnly($company,time()+20);throw new RuntimeException('Kota hatası bekleniyordu');}catch(SyncFailure $e){check($e->retryAfter>=900,'Retry-After korunur');}
 check($payments===archived($db,$company),'Kesinti eski hakedişi değiştirmez');
 $failure=false;$duplicate=true;$reset();try{$adapter->syncReadOnly($company,time()+20);throw new RuntimeException('Tekillik hatası bekleniyordu');}catch(SyncFailure $e){check($e->httpStatus===409,'Aynı uzlaşma günü iki kez sayılmaz');}
 check($payments===archived($db,$company),'Mükerrer kaynakta son tamamlanmış toplam korunur');
 echo "n11 hakediş: net tutar, tarih/durum kimliği, SOAP güvenliği, sayfa bütünlüğü, atomik yayın, mükerrer ve kesinti kontrolleri geçti.\n";
}finally{unlink($key);}

$installment=[...$raw,'paymentAmount'=>'250','deductionAmount'=>'25','settlementAmount'=>'225'];
$daily=N11FinanceClient::parse(response([$raw,$installment],1),0);
check(count($daily['entries'])===1&&$daily['entries'][0]['netCents']===9032754,'Aynı günün ayrı kalemleri eksilmeden ve iki kez sayılmadan toplanır');
$separate=N11FinanceClient::parse(response([$raw,[...$installment,'status'=>'Wait_Remittance','remittanceDate'=>'']],1),0);
check(count($separate['entries'])===2&&count(array_unique(array_column($separate['entries'],'key')))===2,'Aynı günün farklı ödeme durumları karıştırılmaz');
foreach([response([$raw,$raw],1),response([$raw,[...$raw,'paymentAmount'=>'103414.00']],2)] as $xml){try{N11FinanceClient::parse($xml,0);throw new RuntimeException('Tekrar kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===409,'Birebir tekrarlı kalem finans toplamını şişiremez');}}
try{N11FinanceClient::parse(response([$raw,$installment],3),0);throw new RuntimeException('Eksik sayfa kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===422,'Beklenen gün/satır sayısı eksikse yayımlanmaz');}
echo "n11 çoklu hakediş günü, farklı ödeme durumları, biçimsel tekrar ve eksik sayfa kontrolleri geçti.\n";

check(!array_filter($repo->records($company,'payments',['role'=>'admin']),fn($p)=>!empty($p['integration'])),'Finans arşivi manuel hakediş ekranına karışmaz');
