<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{CustomerOnboarding,Database,DeliveryCipher,DeliveryRepository,HttpError,NewOrderReadPage,NewOrderRecordStore,NewRecordRun,TableSyncBoundary,TableSyncTargets};

function check(bool $result, string $message): void {
    if (!$result) throw new RuntimeException($message);
}
function rejected(callable $operation, int $status): void {
    try { $operation(); } catch (HttpError $error) {
        check($error->getCode() === $status, 'Beklenmeyen HTTP durumu '.$error->getCode());
        return;
    }
    throw new RuntimeException('İşlem reddedilmeliydi.');
}
function requestFor(string $table, string $mode, int $sequence): array {
    return ['table'=>$table, 'mode'=>$mode, 'requestId'=>sprintf('12345678-4321-4321-4321-%012d',$sequence)];
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NewRecordRun::install($db);
NewRecordRun::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'test@example.invalid','Yetkili','x','admin',NULL)");
$db->run('INSERT INTO company_profiles (company_id,payload,version) VALUES(2,?,1)',[json_encode(['setupOptions'=>['live'=>['inbox']]])]);
$keys=['orders:n11','catalog:n11','questions:n11','questions:koctas','payouts:n11'];
$targets=array_column(TableSyncTargets::listing($db,1,$keys),null,'key');
check(count($targets)===10 && $targets['inventory']['providerSources']===['n11'=>['catalog']], 'Kurulum tabloları ve katalog kaynağı');
check($targets['inbox']['providers']===['n11'], 'Gelen kutusu şartı olan kaynak arka plan kuyruğuna eklenmemeli');
check(array_column(TableSyncTargets::listing($db,2,$keys),'key')===['inbox'], 'Etkin mağaza tablosu');
$empty=TableSyncTargets::listing($db,2,['questions:koctas'])[0];
check($empty['modes']===[] && $empty['providers']===[], 'Kaynağı olmayan tablo aktarım başlatamaz');
$store=new NewRecordRun($db);
$full=requestFor('inventory','full',1);
$run=$store->start(1,1,$keys,$full)['run'];
check($run['table']==='inventory' && $run['mode']==='full' && array_column($run['sources'],'key')===['catalog:n11'], 'Yalnız doğru tablo ve kaynak kuyruğa girer');
check($store->start(1,1,$keys,$full)['run']===$run, 'Tarayıcının aynı isteği güvenle tekrarlanır');
check($store->start(1,1,$keys,requestFor('inventory','full',2))['run']===$run, 'Aynı mağaza ve hedefte eşzamanlı iki düğme tek işe bağlanır');
rejected(fn()=>$store->start(1,1,$keys,requestFor('inventory','incremental',1)),409);
rejected(fn()=>$store->start(1,1,$keys,requestFor('fileCenter','full',3)),409);
rejected(fn()=>$store->start(2,1,$keys,$full),403);
rejected(fn()=>TableSyncTargets::request($full+['company'=>2]),400);
check(!$store->remembered(2,$full['requestId']), 'İşlem kimliği mağazalar arasında taşamaz');
$time=time()+1;
$first=$store->claim($time);
check($first && !$store->claim($time+1),'Aktif çalışan çakışmaz');
$recovery=$store->claim($time+91);
check($first['token']!==$recovery['token'] && $first['key']===$recovery['key'],'Kesilen çalışmanın kaldığı kaynak tekrar alınır');
try {
    $db->transaction(function() use($db,$store,$first) {
        $db->run("UPDATE companies SET name='Eski işçi' WHERE id=1");
        $store->finish($first,array_replace($first['source'],['state'=>'complete']),true);
    });
    throw new LogicException('Süresi geçen işçi kayıt bıraktı');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(),'çalışma sırası'), 'Beklenen iş sırası hatası');
}
check($db->one('SELECT name FROM companies WHERE id=1')['name']==='Birinci','Satır değişikliği ve ilerleme birlikte geri alınır');
$store->finish($recovery,array_replace($recovery['source'],['state'=>'complete','added'=>1]),true);
check($store->start(1,1,$keys,$full)['run']['state']==='complete','Bitmiş isteğin yinelenmesi yeni iş başlatmaz');
$next=requestFor('inventory','incremental',4);
$store->start(1,1,$keys,$next);
$lease=$store->claim(time()+1);
check($lease['source']['after']===$recovery['source']['requestedAt'],'Tablo ve kaynağın son başarılı sınırı');
$store->finish($lease,array_replace($lease['source'],['state'=>'failed']),true);
rejected(fn()=>$store->start(1,1,$keys,$full),409);
check(count($db->all('SELECT * FROM table_sync_checkpoints'))===1,'Başarısız kaynak başarı sınırı oluşturamaz');
$store->start(1,1,$keys,requestFor('fileCenter','incremental',5));
$lease=$store->claim(time()+1);
check($lease['source']['after']===0,'Dosya Merkezi ile stok aynı kaynağı okurken sınırları ayrı tutulur');
$store->finish($lease,array_replace($lease['source'],['state'=>'complete']),true);
$legacy=$store->start(2,1,['questions:n11'])['run'];
check($legacy['table']===null && $legacy['mode']==='incremental' && $legacy['state']==='queued', 'Kurulu genel kuyruk ile geriye uyum');
$db->run('INSERT INTO audit (company_id,action,after_value,created) VALUES(?,?,?,?)',[1,CustomerOnboarding::CREATED_AUDIT,json_encode(['shipmentSince'=>'2026-05-03']),'2026-08-12T12:00:00Z']);
$through=strtotime('2026-11-20T13:00:00Z');
$config=['startDate'=>'2026-01-01'];
check(TableSyncBoundary::first($db,1,'n11',$config,[],$through,'orders')===strtotime('2026-01-01T00:00:00+03:00'),'Siparişlerde yapılandırılmış kaynak başlangıcı');
check(TableSyncBoundary::first($db,1,'n11',$config,['startDate'=>'2026-07-01'],$through,'orders')===strtotime('2026-01-01T00:00:00+03:00'),'Kayıtlı bağlantı başlangıcı, sonradan değişen çalışma penceresiyle kısalmaz');
check(TableSyncBoundary::first($db,1,'n11',$config,[],$through,'shipments')===strtotime('2026-05-03T00:00:00+03:00'),'Sevkiyat çalışma başlangıcı korunur');
check(TableSyncBoundary::first($db,1,'trendyol',$config,[],$through,'orders')===$through-89*86400,'Trendyol geçmiş üst sınırı görünür ve uygulanır');
$sourceCalls=[];
$mirakl=new NewOrderReadPage($db,koctas:static function(string $path,array $query)use(&$sourceCalls) {
    $sourceCalls[]=$query;
    return ['orders'=>[],'total_count'=>0];
});
$local=new NewOrderRecordStore($db,new DeliveryRepository($db,DeliveryCipher::withKey(random_bytes(32))));
$begin=$through-15*86400;
$page=$mirakl->read(1,'koctas',['revision'=>'v1','shopId'=>125],['verifiedRevision'=>'v1'],
    ['windowFrom'=>$begin,'through'=>$through,'target'=>['table'=>'orders','mode'=>'full']],time()+10,$local);
check(!$page['done'] && $page['cursor']['windowFrom']===$begin+13*86400,'Uzun dönem tek işlemle gereksiz sayıda API isteği yapmaz');
check($sourceCalls[0]['start_date']===gmdate('c',$begin) && $sourceCalls[0]['end_date']===gmdate('c',$begin+13*86400)
    && !isset($sourceCalls[0]['end_update_date']), 'Eski sipariş sonraki bir tarihte güncellense bile oluşturulduğu tarih aralığına girer');
echo "Tablo aktarımı: kaynak ve mağaza kapsamı, tekrar, kesinti, işlem bütünlüğü, ilerleme sınırı, eski iş ve tarih sınırları geçti.\n";
