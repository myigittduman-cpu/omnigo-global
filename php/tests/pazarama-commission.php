<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,FinanceClient,FinanceSchema,FinanceStore,IntegrationVault,PazaramaAuthClient,
    PazaramaCommissionAdapter,PazaramaCommissionStore,PazaramaCommissionWindow,SyncFailure,SyncQueue};

function verify(bool $result, string $description): void
{
    if (!$result) throw new RuntimeException($description);
}

function reject(callable $action, int $code): void
{
    try {
        $action();
    } catch (SyncFailure $error) {
        verify($error->httpStatus === $code, 'Beklenmeyen aktarım hatası.');
        return;
    }
    throw new RuntimeException('Geçersiz finans kaydı kabul edildi.');
}

$day = '2026-11-09';
$transaction = static fn(string $amount, string $status, string $date = '2026-11-09'): array => [
    'trxId'=>'cab6971e-946b-40ec-97ab-08daed6ab000','trxCode'=>'SIP-1001','status'=>$status,
    'transactionDate'=>$date.'T14:00:00+03:00','allowanceAmount'=>'40.00','amount'=>'50.00',
    'commissionAmount'=>$amount,'transferredDate'=>null,
];
$debit = $transaction('-8.40', 'Satış');
$credit = $transaction('2.40', 'İade');
$result = PazaramaCommissionWindow::read(['totalCommission'=>'-6','transactionList'=>[$debit,$credit]],$day,$day);
verify($result === ['days'=>[$day=>600],'reconciled'=>true], 'Komisyon iadesi masrafı azaltmalı.');
verify(PazaramaCommissionWindow::read(['totalCommission'=>0,'transactionList'=>[]],$day,$day) === ['days'=>[],'reconciled'=>true], 'Boş ve teyitli dönem.');
verify(PazaramaCommissionWindow::read(['totalCommission'=>0,'transactionList'=>[$transaction('0.00','Satış')]],$day,$day)['days'] === [$day=>0], 'Açık sıfır korunmalı.');
verify(PazaramaCommissionWindow::read(['totalCommission'=>-2,'transactionList'=>[$debit,$credit]],$day,$day) ===
    ['days'=>[$day=>600],'reconciled'=>false], 'Kaynak satırları görünmeli, uyuşmayan toplam teyitsiz kalmalı.');
reject(fn()=>PazaramaCommissionWindow::read(['totalCommission'=>'-16.80','transactionList'=>[$debit,$debit]],$day,$day),422);
reject(fn()=>PazaramaCommissionWindow::read(['totalCommission'=>0,'transactionList'=>[$transaction('0.001','Satış')]],$day,$day),422);
reject(fn()=>PazaramaCommissionWindow::read(['totalCommission'=>0,'transactionList'=>[$transaction('0','Satış','2026-11-10')]],$day,$day),422);

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'A'),(2,'B')");
$key = tempnam(sys_get_temp_dir(), 'commission-fixture');
file_put_contents($key, random_bytes(32));
try {
    $provider = PazaramaCommissionStore::PROVIDER;
    $vault = new IntegrationVault($db,$key);
    $settings = ['enabled'=>true,'sellerId'=>'00000000-0000-0000-0000-000000000001','apiKey'=>'test','apiSecret'=>'test'];
    $vault->save(1,$settings,'pazarama');
    $commission = new PazaramaCommissionStore($db);
    verify($commission->report(1,$day,$day)['coverage'] === 'unavailable','Şeması olmayan eski kurulumda veri bilinmiyor.');
    FinanceSchema::install($db);
    $requests = 0;
    $httpStatus = 200;
    $auth = new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode([
        'access_token'=>'a.'.base64_encode(json_encode(['client_seller_id'=>$settings['sellerId']])).'.b',
        'token_type'=>'Bearer','expires_in'=>3600,
    ])]);
    $client = new FinanceClient(function ($method,$url,$headers,$body) use (&$requests,&$httpStatus,$transaction): array {
        $requests++;
        verify($method === 'POST' && str_ends_with($url,'/order/paymentAgreement'),'Yalnız mali kayıt okuma servisi çağrılmalı.');
        if ($httpStatus !== 200) return ['status'=>$httpStatus,'retryAfter'=>75];
        $query = json_decode($body,true);
        $date = substr($query['startDate'],0,10);
        return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>[
            'totalCommission'=>'-1.25','transactionList'=>[$transaction('-1.25','Satış',$date)],
        ]])];
    });
    $adapter = new PazaramaCommissionAdapter($db,$vault,$client,$auth);
    $queue = new SyncQueue($db,[PazaramaCommissionAdapter::JOB=>$adapter]);
    PazaramaCommissionAdapter::schedule($db,$vault,$queue);
    verify(!$queue->runOne(null,null,null,[PazaramaCommissionAdapter::JOB]),'Sipariş işçisi komisyon kaydını almamalı.');
    $range = new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul'));
    $from = $range->modify('-90 days')->format('Y-m-d');
    $to = $range->modify('-1 day')->format('Y-m-d');
    for ($index=0; $index<3; $index++) {
        $db->run('UPDATE jobs SET next_run=0 WHERE provider=?',[PazaramaCommissionAdapter::JOB]);
        verify($queue->runOne(null,[PazaramaCommissionAdapter::JOB],time()+20),'Kuyruk komisyon aralığını okumalı.');
        if ($index<2) verify($commission->report(1,$from,$to)['coverage'] === 'unavailable','Eksik pencere yayımlanmamalı.');
    }
    $published = $commission->report(1,$from,$to);
    verify($requests === 3 && $published['coverage'] === 'complete' && count($published['items']) === 3,'Tam mali dönem yayımlanmalı.');
    verify(array_sum(array_column($published['items'],'amountCents')) === 375,'Kuruşlar doğrudan mali kaynaktan gelmeli.');
    verify($commission->report(1,$from,$range->format('Y-m-d'))['coverage'] === 'partial','Açık gün tam sayılmamalı.');
    verify($commission->report(2,$from,$to)['coverage'] === 'unavailable','Veri mağazaya özel olmalı.');
    verify(!$db->one("SELECT id FROM records WHERE kind='payments'") && !$db->one('SELECT external_key FROM finance_entries'),'Hakediş arşivi ve ödemeler değişmemeli.');
    $httpStatus = 429;
    reject(fn()=>$adapter->syncReadOnly(1,time()+20),429);
    verify($commission->report(1,$from,$to) === $published,'Hata önceki teyitli dönemi silmemeli.');
    $db->run("DELETE FROM records WHERE company_id=1 AND kind='integration_pazarama'");
    verify($commission->report(1,$from,$to)['coverage'] === 'unavailable','Bağlantı ayrıldıktan sonra eski kimliğin verisi gösterilmemeli.');
} finally {
    unlink($key);
}
echo "Pazarama komisyon kaynağı, pencere mutabakatı, kuyruk, hata ve mağaza ayrımı doğrulandı.\n";
