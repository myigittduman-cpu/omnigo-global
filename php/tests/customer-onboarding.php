<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationVault,KoctasConnection,KoctasAdapter,MarketplaceConnection,CustomerOnboarding,HttpError,SyncFailure};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $action,int $status):void{try{$action();throw new RuntimeException('Hata bekleniyordu');}catch(HttpError $e){check($e->getCode()===$status,'Beklenen durum '.$status);}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));\Omnigo\InventorySchema::install($db);
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Test','x','admin',NULL)");
$zone=new DateTimeZone('Europe/Istanbul');$today=new DateTimeImmutable('today',$zone);
$company=CustomerOnboarding::create($db,['id'=>1],'Yeni müşteri',$today->format('Y-m-01'));
$other=CustomerOnboarding::create($db,['id'=>1],'Diğer müşteri',$today->format('Y-m-d'));
check($company!==$other&&count($db->all("SELECT * FROM records WHERE company_id=? AND kind='channels'",[$company]))===8,'Bağımsız çalışma alanı ve katalog kurulur');
$initial=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='shipment_auto_since'",[$company])['payload'],true);
check($initial['since']===$today->modify('first day of this month')->getTimestamp(),'Ayın mevcut siparişleri sevkiyat başlangıcından dışlanmaz');
fails(fn()=>CustomerOnboarding::create($db,['id'=>1],'Geçersiz',$today->modify('+1 day')->format('Y-m-d')),400);
check(count($db->all('SELECT * FROM companies'))===2,'Geçersiz tarih müşteri kaydı bırakmaz');
$stockPolicy=new Omnigo\MarketplaceStockPolicy($db);
$managedPolicy=Omnigo\CustomerInitialPolicy::template($db);
$managedPolicy['inventory']=['mode'=>'horoz','marketplaceManagement'=>true];
$managed=CustomerOnboarding::create($db,['id'=>1],'Pazaryeri stok yönetimi',null,null,['Hepsiburada'],
    initialPolicy:$managedPolicy,setupOptions:['live'=>['inventory'],'manual'=>['storeStock']]);
check($stockPolicy->enabled($managed)&&!$stockPolicy->enabled($company)&&!$stockPolicy->enabled($other),
    'Pazaryeri stok yönetimi yalnız seçildiği yeni mağazada açılır');
Omnigo\MarketplaceStockSchema::install($db);
check(!$db->one('SELECT request_id FROM marketplace_stock_requests WHERE company_id=?',[$managed]),
    'Yeni mağaza kurulumu pazaryerine stok gönderimi oluşturmaz');
fails(fn()=>CustomerOnboarding::create($db,['id'=>1],'Uyumsuz stok modeli',null,
    initialPolicy:[...$managedPolicy,'inventory'=>['mode'=>'store','marketplaceManagement'=>true]]),400);
$key=tempnam(sys_get_temp_dir(),'onboard-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new KoctasConnection($db,$vault);
 $credentials=['apiKey'=>'11111111-1111-1111-1111-111111111111','shopId'=>123,'version'=>0];
 $connection->save($company,$credentials,1);
 check($connection->status($company)['configured']&&$connection->status($company)['version']===1,'Koçtaş bağlantısı panelden kurulur');
 check(!str_contains(json_encode($connection->status($company)),$credentials['apiKey']),'Anahtar tarayıcıya geri verilmez');
 check($db->one("SELECT state FROM jobs WHERE company_id=? AND provider='Koçtaş'",[$company])['state']==='pending','Doğrulama asenkron kuyruğa alınır');
 $snapshot=$vault->get($company);fails(fn()=>$connection->save($company,$credentials,1),409);
 check($snapshot===$vault->get($company),'Eski sürüm anahtarı değiştiremez');
 fails(fn()=>$connection->save($company,[...$credentials,'version'=>1,'shopId'=>456],1),409);
 $connection->save($company,[...$credentials,'version'=>1,'apiKey'=>'22222222-2222-2222-2222-222222222222'],1);
 check($connection->status($company)['version']===2&&$vault->get($company)['startDate']===$snapshot['startDate'],'Anahtar düzeltme geçmiş başlangıcını korur');
 $snapshot=$vault->get($company);$db->run("UPDATE jobs SET state='running',lease_until=? WHERE company_id=? AND provider='Koçtaş'",[time()+300,$company]);
 fails(fn()=>$connection->save($company,[...$credentials,'version'=>2],1),409);check($snapshot===$vault->get($company),'Çalışan aktarımın anahtarı değişmez');
 $db->run("UPDATE jobs SET state='blocked',lease_until=NULL,next_run=? WHERE company_id=? AND provider='Koçtaş'",[time()+120,$company]);$connection->retry($company);
 check((int)$connection->status($company)['job']['next_run']>=time()+119,'Yeniden dene API bekleme süresini atlamaz');
 $paths=[];$adapter=new KoctasAdapter($db,$vault,function($path)use(&$paths){$paths[]=$path;return ['shop_id'=>456];});
 try{$adapter->syncReadOnly($company,time()+30);throw new RuntimeException('Yanlış mağaza kabul edildi');}catch(SyncFailure $e){check($e->httpStatus===403&&$paths===['/api/account'],'Mağaza yetkisi doğrulanmadan sipariş okunmaz');}
 $paths=[];$adapter=new KoctasAdapter($db,$vault,function($path)use(&$paths){$paths[]=$path;return $path==='/api/account'?['shop_id'=>123]:['orders'=>[],'total_count'=>0];});
 $adapter->syncReadOnly($company,time()+30);
 check($paths===['/api/account','/api/orders']&&isset($connection->status($company)['sync']['lastSuccessAt']),'Yetki doğrulanınca sipariş okuma otomatik başlar');
 check(!$connection->status($other)['configured'],'Bağlantı diğer müşteriye açılmaz');
 $vault->save($company,['apiKey'=>'old','accessToken'=>'old','startDate'=>'2026-06-01','storeName'=>'Mağaza','connectedAt'=>123,'restoreShipments'=>true,'enabled'=>true],'pttavm');
 (new MarketplaceConnection($db,$vault,'pttavm'))->save($company,['apiKey'=>'new','accessToken'=>'new','version'=>1]);
 $current=$vault->get($company,'pttavm');check($current['startDate']==='2026-06-01'&&$current['connectedAt']===123&&$current['storeName']==='Mağaza'&&$current['restoreShipments'],'PTT anahtar düzeltmesi aktarım kapsamını kaybetmez');
 (new MarketplaceConnection($db,$vault,'pttavm'))->save($other,['apiKey'=>'new','accessToken'=>'new','version'=>0]);
 check(abs($vault->get($other,'pttavm')['connectedAt']-time())<5,'Yeni PTT bağlantısı yeni sipariş bildirim başlangıcını kaydeder');
 $hb=new Omnigo\HepsiburadaConnection($db,$vault);
 $hbInput=['merchantId'=>'33333333-3333-3333-3333-333333333333','username'=>'test','serviceKey'=>'test-key','userAgent'=>'test-agent','version'=>0];
 $hb->save($other,$hbInput,1);$hbConfig=$vault->get($other,'hepsiburada');
 check($hbConfig['ordersEnabled']&&$hbConfig['salesEnabled']&&!empty($hbConfig['salesStartDate'])&&!empty($hbConfig['notifyFrom']),'Yeni Hepsiburada bağlantısı deneme okumada kalmadan tam aktarımı başlatır');
 $db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='sync_hepsiburada'",[json_encode(['ordersPublished'=>true,'lastSuccessAt'=>gmdate('c'),'financial'=>['through'=>'2026-10-20'],'offsets'=>['packages'=>10]]),$other]);
 $hb->retry($other);$state=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$other])['payload'],true);
 check($state['financial']['through']==='2026-10-20'&&$state['offsets']['packages']===10,'Hepsiburada yeniden dene geçmişi ve kaldığı sayfayı silmez');
 $hb->save($other,[...$hbInput,'version'=>1,'serviceKey'=>'new-key'],1);$updated=$vault->get($other,'hepsiburada');
 check($updated['salesStartDate']===$hbConfig['salesStartDate']&&$updated['notifyFrom']===$hbConfig['notifyFrom'],'Hepsiburada anahtar düzeltmesi tarih ve bildirim kapsamını korur');
 echo "Müşteri kurulumu: firma, başlangıç tarihi, şifreli bağlantı, mağaza doğrulama, anahtar düzeltme, yarış/rol kapsamı ve tekrar kuyruğu geçti.\n";
}finally{unlink($key);}
