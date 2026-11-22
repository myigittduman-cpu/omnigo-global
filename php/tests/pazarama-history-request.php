<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliverySchema,FinanceSchema,FinanceStore,HistoricalSalesArchive,HttpError,IntegrationVault,ManagementSchema,
    PazaramaAuthClient,PazaramaHistoryImporter,PazaramaHistoryJobStore,PazaramaHistoryWorker,PazaramaOrderHistory,PazaramaReadClient,
    PazaramaSalesMapper,ProviderRequestGate,RecentOrderSchema,Repository,ShipmentSchema,SyncFailure,SyncQueue};

function expect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $action,int $status):void {
    try {$action();}catch(HttpError $error){expect($error->getCode()===$status,'İstek hata kodu');return;}
    throw new RuntimeException('Geçersiz istek reddedilmeliydi.');
}
function uid(int $n):string{return sprintf('00000000-0000-4000-8000-%012d',$n);}
function money(string $value):array{return ['currency'=>'TL','value'=>$value];}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);ManagementSchema::install($db);ProviderRequestGate::install($db);FinanceSchema::install($db);
$company=(new Repository($db))->createCompany('Bir');$other=(new Repository($db))->createCompany('İki');
$db->run("INSERT INTO users(email,name,password,role) VALUES('test@test.invalid','Yönetici','unused','admin')");
$actor=$db->insertId();
$key=tempnam(sys_get_temp_dir(),'pz-key-');file_put_contents($key,random_bytes(32));
$archiveFile=tempnam(sys_get_temp_dir(),'pz-hist-');
try{
    $line=['orderItemId'=>uid(101),'orderItemStatus'=>11,'quantity'=>1,'product'=>['name'=>'Ürün','stockCode'=>'SKU'],
        'salePrice'=>money('10249'),'discountAmount'=>money('200'),'totalPrice'=>money('10049'),'discountDescription'=>'Kupon (Pazarama İndirimi - 614.94 TL)'];
    $raw=['orderId'=>uid(1),'orderDate'=>'2026-06-20 11:30','currency'=>'TL','orderAmount'=>'10049','orderNumber'=>'T1','items'=>[$line]];
    $archived=PazaramaSalesMapper::order($raw);unset($archived['products'][0]['lineId']);
    file_put_contents($archiveFile,json_encode(['version'=>1,'companyId'=>$company,'companyName'=>'Bir','providers'=>['pazarama'=>[
        'from'=>'2026-06-01T00:00:00+03:00','untilExclusive'=>'2026-07-01T00:00:00+03:00','orders'=>[$archived]]]],JSON_THROW_ON_ERROR));
    $vault=new IntegrationVault($db,$key);$seller=uid(2);
    $cred=['sellerId'=>$seller,'apiKey'=>'fake','apiSecret'=>'fake','enabled'=>true,'revision'=>'test'];
    $vault->save($company,$cred,'pazarama');
    $importer=new PazaramaHistoryImporter($db,DeliveryCipher::withKey(str_repeat('x',32)),new HistoricalSalesArchive($db,$archiveFile));
    $service=new PazaramaOrderHistory($db,$importer);
    $state=['startDate'=>'2026-09-01T00:00:00+03:00','watermark'=>'2026-11-18T12:00:00+03:00','scan'=>['page'=>2]];
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_pazarama',?,?)",[$company,json_encode($state),gmdate('c')]);
    $queue=new SyncQueue($db);
    foreach(['Pazarama','Pazarama Sorular','n11'] as $provider)$queue->enqueue($company,$provider);
    $db->run('UPDATE jobs SET next_run=? WHERE company_id=?',[time()+3600,$company]);
    $before=$db->all('SELECT * FROM jobs ORDER BY id');
    $live=$db->all('SELECT * FROM records ORDER BY id');
    $store=new PazaramaHistoryJobStore($db);
    $finance=new FinanceStore($db);
    $now=gmdate('c');
    $otherJob=['company_id'=>$other,'connection_version'=>1,'from_date'=>'2026-06-01','until_date'=>'2026-07-01',
        'cursor_day'=>'2026-07-01','page_no'=>1,'state'=>'verified','attempts'=>0,'next_run'=>0,
        'token'=>null,'page_hash'=>null,'error_code'=>null,'requested_at'=>$now,'updated_at'=>$now];
    $db->transaction(function()use($finance,$store,$company,$otherJob){
        $finance->writeState($company,'pazarama',['state'=>'queued','next_run'=>0,'watermark'=>'canlı finans kontrolü'],null);
        $store->save($otherJob);
    });
    $sentinels=fn()=>$db->all("SELECT company_id,provider,payload,version FROM finance_sync
        WHERE company_id<>? OR provider<>'pazarama_history' ORDER BY company_id,provider",[$company]);
    $untouched=$sentinels();
    expect(count($untouched)===2&&$store->get($company)===null&&$store->get($other)['state']==='verified','Bağımsız mağaza ve finans işlerinin başlangıcı');
    expect($store->due(time())===null,'Diğer finans türleri ve tamamlanmış işler çalışmaya aday olamaz');
    $schemaVersion=fn()=>(int)$db->pdo->query('PRAGMA schema_version')->fetchColumn();
    $schemaBefore=$schemaVersion();
    expect($service->status($company,$state['startDate'])['missing']===1&&$service->status($other,$state['startDate'])===null,'Firma sınırı ve başlangıç kapsamı');
    $request=['action'=>'extendHistory','from'=>'2026-06-01','version'=>1];
    rejects(fn()=>$service->request($company,[...$request,'version'=>2],$actor),409);
    rejects(fn()=>$service->request($company,[...$request,'from'=>'2026-09-15'],$actor),400);
    $service->request($company,$request,$actor);$service->request($company,$request,$actor);
    rejects(fn()=>$service->request($company,[...$request,'from'=>'2026-06-15'],$actor),409);
    expect((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='pazarama.archive_requested'")['n']===1,'İki tıklama tek istek');
    expect($schemaVersion()===$schemaBefore,'Durum ve istek işlemleri veritabanı şemasını değiştirmemeli');
    expect($store->due(time())===$company&&$store->get($company)['state']==='queued','Sırada yalnız hedef mağazanın Pazarama geçmiş işi var');
    $token='a.'.rtrim(strtr(base64_encode(json_encode(['client_seller_id'=>$seller])),'+/','-_'),'=').'.test';
    $authCalls=0;
    $auth=new PazaramaAuthClient(function()use($token,&$authCalls){
        $authCalls++;
        return ['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600])];
    });
    $mode='429';$queries=[];
    $reader=new PazaramaReadClient(function($url,$headers,$body)use(&$mode,&$queries,$raw){
        $q=json_decode($body,true);$queries[]=$q;
        expect(str_ends_with($url,'/order/getOrdersForApi')&&$q['pageSize']===20,'Resmî sipariş okuması');
        if($mode==='429')return ['status'=>429,'retryAfter'=>600];
        if($mode==='full'){
            $data=[];for($i=21;$i<=40;$i++)$data[]=['orderId'=>uid($i)];
        }else $data=$mode==='match'?[$raw]:[];
        return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>$data])];
    });
    $worker=new PazaramaHistoryWorker($db,$vault,$importer,$auth,$reader);
    $job=fn()=>$store->get($company);
    $changeJob=fn(array $changes)=>$db->transaction(function()use($store,$company,$changes){
        $row=$store->get($company,true);
        expect($row!==null,'Hedef mağazanın arşiv işi bulunmalı');
        $store->save(array_replace($row,$changes));
    });
    $due=fn()=>$changeJob(['next_run'=>0]);
    $run=fn()=>$worker->runOne(time()+19);
    $run();
    expect($job()['error_code']===429&&(int)$job()['next_run']>=time()+599,'Retry-After bekleme süresi');
    expect($queries[0]['startDate']==='2026-06-01T00:00'&&$queries[0]['endDate']==='2026-06-14T23:59','İlk pencere sınırları');
    $endCooldown=fn()=>$db->run("UPDATE provider_request_gates SET retry_until=0 WHERE company_id=? AND provider='pazarama'",[$company]);
    $endCooldown();
    $liveOrderDue=$db->one("SELECT next_run FROM jobs WHERE company_id=? AND provider='Pazarama'",[$company])['next_run'];
    $setLiveOrderDue=fn(int $at)=>$db->run("UPDATE jobs SET next_run=? WHERE company_id=? AND provider='Pazarama'",[$at,$company]);
    $setLiveOrderDue(time()-180);
    $overdueJobs=$db->all('SELECT * FROM jobs ORDER BY id');
    $calls=count($queries);
    $mode='full';$due();$run();
    expect($job()['state']==='queued'&&$authCalls===$calls&&count($queries)===$calls,'Yeni arşiv sayfası otomatik sipariş işini bekler');
    $changeJob(['slot_wait_from'=>time()-100,'next_run'=>0]);
    $gate=new ProviderRequestGate($db);
    $lease=$gate->acquire($company,'pazarama',time()+60);
    expect($lease!==null,'Canlı aktarıma ait etkin istek kilidi');
    $run();
    expect($job()['state']==='queued'&&$authCalls===$calls&&count($queries)===$calls,'Bekleme eşiğinden sonra da etkin istek kilidi korunur');
    $gate->release($company,'pazarama',$lease,new SyncFailure(429,600));
    $due();$run();
    expect($job()['state']==='queued'&&(int)$job()['next_run']>=time()+599&&$authCalls===$calls&&count($queries)===$calls,'Bekleyen arşiv sağlayıcının 429 süresini aşamaz');
    $endCooldown();$due();$run();
    expect((int)$job()['page_no']===2&&$authCalls===$calls+1&&count($queries)===$calls+1,'Bekleme eşiği aşılınca boş istek aralığında tek sayfa okunur');
    $due();$run();
    expect($job()['state']==='queued'&&$authCalls===$calls+1&&count($queries)===$calls+1,'Yeni sayfanın bekleme süresi yeniden başlar');
    $db->transaction(function()use($store,$company){
        $legacy=$store->get($company,true);
        unset($legacy['slot_wait_from']);
        $store->save([...$legacy,'requested_at'=>gmdate('c',time()-100),'next_run'=>0]);
    });
    $run();
    expect($job()['state']==='failed'&&(int)$job()['error_code']===422&&count($queries)===$calls+2,'Eski iş istek tarihinden devam eder ve yinelenen tam sayfa durdurulur');
    expect($db->all('SELECT * FROM jobs ORDER BY id')===$overdueJobs,'Arşiv işlemleri geciken gerçek sipariş işinin zamanını veya sürümünü değiştirmedi');
    $setLiveOrderDue((int)$liveOrderDue);
    $service->request($company,$request,$actor);$mode='empty';
    $changeJob(['state'=>'running','token'=>'crash','next_run'=>time()+90]);
    expect(!$run(),'Geçerli kilit korunur');$due();$run();expect($job()['cursor_day']==='2026-06-15','Süresi dolan iş kaldığı yerden alındı');
    $due();$run();$due();$run();expect($job()['state']==='incomplete','Bütün aralık okunsa da kaynakta bulunamayan kayıt doğrulanmaz');
    $service->request($company,$request,$actor);$run();$mode='match';$due();$run();
    $status=$service->status($company,$state['startDate']);
    expect($status['job']['state']==='verified'&&$status['missing']===0&&$status['present']===1,'Arşiv kimliği ile sonuç doğrulandı');
    expect($db->all('SELECT * FROM jobs ORDER BY id')===$before&&$db->all('SELECT * FROM records ORDER BY id')===$live,'Canlı kayıtlar, ciro ve diğer kuyruklar korundu');
    expect($sentinels()===$untouched,'Diğer finans sağlayıcısı ve başka mağazanın arşiv işi sürümleriyle korundu');
    expect($schemaVersion()===$schemaBefore&&!$db->one("SELECT name FROM sqlite_master WHERE name='pazarama_history_jobs'"),'Arşiv işçisi mevcut şemayla çalıştı');
    echo "Pazarama arşiv kuyruğu, API pencereleri, yinelenen sayfa, kesinti, hız sınırı ve mağaza ayrımı doğrulandı.\n";
}finally{unlink($archiveFile);unlink($key);}
