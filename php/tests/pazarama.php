<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,PazaramaConnection,PazaramaProbeAdapter,PazaramaAuthClient,PazaramaReadClient,PazaramaPreview,SyncQueue,SyncFailure,HttpError,KoctasDiagnostics};
function ensure(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
function fails(callable $action,int $status): void {
    try {$action();} catch(SyncFailure|HttpError $error) {
        ensure(($error instanceof SyncFailure?$error->httpStatus:$error->getCode())===$status,'Beklenen hata kodu');return;
    }
    throw new RuntimeException('Hata bekleniyordu.');
}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$company=(new Repository($db))->createCompany('Deneme');$other=(new Repository($db))->createCompany('Diğer');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['test@example.test','Deneme','unused','admin']);$user=(int)$db->insertId();
$keyPath=tempnam(sys_get_temp_dir(),'pazarama-key-');file_put_contents($keyPath,random_bytes(32));
try {
    $vault=new IntegrationVault($db,$keyPath);$connection=new PazaramaConnection($db,$vault);
    $input=['sellerId'=>'00000000-0000-0000-0000-000000000002','apiKey'=>'fake-api-key','apiSecret'=>'fake-api-secret','version'=>0];
    $vault->save($company,['apiKey'=>'fake-koctas-key']);
    $connection->save($company,$input,$user);
    ensure($vault->get($company)['apiKey']==='fake-koctas-key','Koçtaş anahtarı korunmalı');
    ensure($vault->get($company,'pazarama')['apiKey']==='fake-api-key','Pazarama anahtarı açılmalı');
    ensure($vault->get($other,'pazarama')===null,'Firma yalıtımı');
    $encrypted=$db->one("SELECT payload FROM records WHERE kind='integration_pazarama'")['payload'];
    ensure(!str_contains($encrypted,'fake-api'),'Anahtarlar düz metin saklanmamalı');
    ensure(!str_contains(json_encode($connection->status($company)),'fake-api'),'API yanıtı sır içermemeli');
    fails(fn()=>$connection->save($company,array_replace($input,['apiKey'=>'wrong']),$user),409);
    ensure($vault->get($company,'pazarama')['apiKey']==='fake-api-key','Çakışmada anahtar işlemi geri alınmalı');
    $calls=0;
    $auth=new PazaramaAuthClient(function() use(&$calls) {$calls++;return ['status'=>200,'body'=>'{"access_token":"fake-token","token_type":"Bearer","expires_in":3600}'];});
    $response=['orderId'=>'00000000-0000-0000-0000-000000000003','orderNumber'=>123456789,'orderDate'=>'2026-10-07 12:00','orderAmount'=>100.25,'shipmentAmount'=>0,'discountAmount'=>10,'currency'=>'TL','customerName'=>'NEVER_STORE_ME','customerEmail'=>'private@example.test','items'=>[['address'=>'NEVER_STORE_ME']]];
    $reader=new PazaramaReadClient(function($url,$headers,$body) use(&$calls,$response) {
        $calls++;$query=json_decode($body,true);
        ensure($url==='https://isortagimapi.pazarama.com/order/getOrdersForApi','Sabit okuma adresi');
        ensure($query['pageSize']===20 && $query['pageNumber']===1,'Sınırlı örnek');
        ensure(!isset($query['status']) && !isset($query['sellerId']),'Sipariş değişikliği parametresi yok');
        return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>[$response]])];
    });
    $queue=new SyncQueue($db,['Pazarama'=>new PazaramaProbeAdapter($db,$vault,$auth,$reader)]);
    ensure($queue->runOne(),'Kontrol işi çalışmalı');
    $status=$connection->status($company);
    ensure($status['sync']['phase']==='review_ready','Kontrol hazır');
    ensure($status['sync']['preview'][0]['orderAmountCents']===10025,'Sipariş tutarı değiştirilmemeli');
    ensure($status['sync']['sellerVerified']===false && $status['sync']['salesPublished']===false,'Doğrulanmamış rakam yayımlanmamalı');
    ensure(!str_contains(json_encode($status),'NEVER_STORE_ME')&&!str_contains(json_encode($status),'fake-token'),'Kişisel bilgiler ve token atılmalı');
    ensure($db->one("SELECT id FROM records WHERE kind='sales'")===null,'Müşteri cirosuna örnek veri yazılmamalı');
    $db->run("UPDATE jobs SET next_run=0 WHERE provider='Pazarama'");$queue->runOne();ensure($calls===2,'Hazır kontrol tekrar ağ çağrısı yapmamalı');
    $db->run("UPDATE jobs SET state='running',lease_until=? WHERE provider='Pazarama'",[time()+300]);
    fails(fn()=>$connection->save($company,array_replace($input,['version'=>1]),$user),409);
    $db->run("UPDATE jobs SET state='failed',next_run=? WHERE provider='Pazarama'",[time()+7200]);
    $connection->retry($company);
    ensure($db->one("SELECT next_run FROM jobs WHERE provider='Pazarama'")['next_run']>=time()+7199,'Retry-After zamanı korunmalı');
    $mapped=PazaramaPreview::map([$response]);ensure($mapped[0]['discountAmountCents']===1000,'İndirim ayrıca gösterilmeli');
    fails(fn()=>PazaramaPreview::map([array_replace($response,['currency'=>'USD'])]),422);
    fails(fn()=>PazaramaPreview::map([$response,$response]),422);
    $failure=new PazaramaReadClient(fn()=>['status'=>429,'retryAfter'=>7200]);
    try {$failure->sample('fake-token','2026-10-01T00:00','2026-10-08T00:00',time()+30);throw new RuntimeException('Hız sınırı bekleniyordu');}
    catch(SyncFailure $error){ensure($error->httpStatus===429 && $error->retryAfter===7200,'Hız sınırı kuyruğa aktarılmalı');}
    // Local reconciliation distinguishes a portal mismatch from remote validation.
    $order=['externalId'=>'TEST-1','createdAt'=>'2026-10-01T00:00:00Z','state'=>'SHIPPED','month'=>'2026-10','grossCents'=>10025,'cancelCents'=>0,'returnCents'=>100,'orders'=>1];
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_koctas',json_encode($order),gmdate('c')]);
    $sale=['channel'=>'Koçtaş','integration'=>'koctas','month'=>'2026-10','grossCents'=>10025,'cancelCents'=>0,'returnCents'=>100,'orders'=>1];
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sales',json_encode($sale),gmdate('c')]);
    $report=(new KoctasDiagnostics($db))->report($company,'2026-10');
    ensure($report['localTotalsMatch'] && !$report['marketplaceVerified'] && $report['storedTotals']['netCents']===9925,'Yerel toplam ve kaynak doğrulaması ayrılmalı');
    $sale['grossCents']=11025;$db->run("UPDATE records SET payload=? WHERE kind='sales'",[json_encode($sale)]);
    $report=(new KoctasDiagnostics($db))->report($company,'2026-10');ensure(!$report['localTotalsMatch'] && $report['differences']['grossCents']===1000,'Toplam farkı görünmeli');
    ensure((new KoctasDiagnostics($db))->report($other,'2026-10')['sampleOrders']===[],'Sipariş kontrolünde firma yalıtımı');
    echo "Pazarama: şifreleme, kuyruk, tekrar deneme, veri gizliliği, yayın kilidi; Koçtaş: toplam kontrolü geçti.\n";
} finally {unlink($keyPath);}
