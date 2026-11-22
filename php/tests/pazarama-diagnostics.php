<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,PazaramaConnection,PazaramaProbeAdapter,PazaramaAuthClient,PazaramaReadClient,PazaramaDiagnostics,SyncQueue};
function ensure(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$company=(new Repository($db))->createCompany('Deneme');
$other=(new Repository($db))->createCompany('Diğer firma');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['test@example.test','Deneme','unused','admin']);
$user=(int)$db->insertId();
$keyPath=tempnam(sys_get_temp_dir(),'pazarama-diagnostics-');
file_put_contents($keyPath,random_bytes(32));
try {
    $vault=new IntegrationVault($db,$keyPath);$connection=new PazaramaConnection($db,$vault);
    $connection->save($company,['sellerId'=>'00000000-0000-0000-0000-000000000001','apiKey'=>'test-client','apiSecret'=>'test-secret','version'=>0],$user);
    $authReply=['status'=>200,'body'=>'{"access_token":"test-token","token_type":"Bearer","expires_in":3600}'];
    $orderReply=['status'=>200,'body'=>'{"success":true,"data":[]}'];
    $auth=new PazaramaAuthClient(function() use (&$authReply) {return $authReply;});
    $reader=new PazaramaReadClient(function() use (&$orderReply) {return $orderReply;});
    $adapter=new PazaramaProbeAdapter($db,$vault,$auth,$reader);
    $queue=new SyncQueue($db,['Pazarama'=>$adapter]);
    foreach (['authentication','orders','preview'] as $stage) {
        $authReply=['status'=>200,'body'=>'{"access_token":"test-token","token_type":"Bearer","expires_in":3600}'];
        $orderReply=['status'=>200,'body'=>'{"success":true,"data":[]}'];
        if ($stage==='authentication') $authReply=['status'=>503,'body'=>'secret-provider-detail'];
        if ($stage==='orders') $orderReply=['status'=>200,'body'=>'not-json-secret-provider-detail'];
        if ($stage==='preview') $orderReply=['status'=>200,'body'=>'{"success":true,"data":[{"customerName":"secret-provider-detail"}]}'];
        $db->run("UPDATE jobs SET next_run=0,state='pending',attempts=0");
        ensure($queue->runOne(),'Kontrol işi çalışmalı');
        $status=$connection->status($company);
        ensure($status['sync']['failure']['stage']===$stage,'Hatanın aşaması korunmalı');
        ensure($status['job']['state']==='pending','Geçici hata kuyrukta yeniden denenmeli');
        $json=json_encode($status);
        foreach (['secret-provider-detail','test-secret','test-token','test-client'] as $secret) ensure(!str_contains($json,$secret),'Tanı bilgisine gizli veri yazılmamalı');
        ensure(!isset($connection->status($other)['sync']['failure']),'Tanı bilgisi başka firmaya sızmamalı');
    }
    $authReply=['status'=>400,'body'=>'{"error":"invalid_client"}'];
    $db->run("UPDATE jobs SET next_run=0,state='pending',attempts=0");$queue->runOne();
    ensure($connection->status($company)['job']['state']==='blocked','Yetki hatasında otomatik deneme durmalı');
    $authReply=['status'=>429,'body'=>'','retryAfter'=>7200];
    $db->run("UPDATE jobs SET next_run=0,state='pending',attempts=4");$queue->runOne();
    $status=$connection->status($company);
    ensure($status['job']['state']==='pending' && $status['job']['next_run']>=time()+7199,'Hız sınırı ve uzun bekleme korunmalı');
    // A later successful response must clear the old diagnosis, without publishing sample sales.
    $authReply=['status'=>200,'body'=>'{"access_token":"test-token","token_type":"Bearer","expires_in":3600}'];
    $orderReply=['status'=>200,'body'=>'{"success":true,"data":[]}'];
    $db->run("UPDATE jobs SET next_run=0,state='pending',attempts=0");$queue->runOne();
    $status=$connection->status($company);
    ensure($status['sync']['phase']==='review_ready' && !isset($status['sync']['failure']),'Başarı eski hatayı temizlemeli');
    ensure($db->one("SELECT id FROM records WHERE kind='sales'")===null,'Örnek kontrol ciro yayımlamamalı');
    $safe=PazaramaDiagnostics::failure('unexpected-secret',new RuntimeException('secret-provider-detail'));
    ensure($safe['stage']==='internal' && !str_contains(json_encode($safe),'secret'),'Beklenmeyen hatalar gizli ayrıntı taşımamalı');
    echo "Pazarama: hata aşamaları, gizlilik, firma yalıtımı, hız sınırı ve hata sonrası toparlanma geçti.\n";
} finally {unlink($keyPath);}
