<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\Database;
use Omnigo\HttpError;
use Omnigo\OrderNotificationSchema;
use Omnigo\UpcomingPayoutNotice;
use Omnigo\UpcomingPayoutReviewStore;

function ensure(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectHttp(int $status, callable $callback): void
{
    try { $callback(); }
    catch (HttpError $error) {
        ensure($error->getCode() === $status,'Beklenmeyen istek hatası.');
        return;
    }
    throw new RuntimeException('Beklenen istek hatası oluşmadı.');
}

function panel(int $cents, string $day = '2026-11-23', string $observed = '2026-11-14'): array
{
    return ['id'=>'panel:Hepsiburada:'.$day,'channel'=>'Hepsiburada','date'=>$day,'amountCents'=>$cents,
        'source'=>'marketplace_panel','verification'=>'estimated','observedDate'=>$observed];
}

function manual(int $cents, int $id, string $day = '2026-11-23', string $verification = 'estimated'): array
{
    return ['id'=>$id,'channel'=>'Hepsiburada','date'=>$day,'amountCents'=>$cents,
        'source'=>'manual','verification'=>$verification];
}

function sample(array $items, string $today = '2026-11-14', array $settled = []): array
{
    return compact('today','items','settled');
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ensure(!UpcomingPayoutReviewStore::available($db),'Şema kurulmadan kullanılabilir sayılmamalı.');
UpcomingPayoutReviewStore::install($db);
UpcomingPayoutReviewStore::install($db);
ensure(UpcomingPayoutReviewStore::available($db),'Hakediş onay şeması bulunamadı.');
$db->run("INSERT INTO companies(id,name) VALUES(1,'VİZYONA'),(2,'Örnek')");
$db->run("INSERT INTO users(id,email,password,name,role) VALUES(1,'admin@example.test','hash','Yönetici','admin')");
$store = new UpcomingPayoutReviewStore($db);
$actor = ['id'=>1,'role'=>'admin'];
$notices = [];
$notice = function (int $company, array $change) use ($db,&$notices): void {
    ensure($db->pdo->inTransaction(),'Bildirim tutarla aynı işlemde yazılmalı.');
    $notices[] = [$company,$change];
};

$initial = $store->observe(1,sample([panel(10000),panel(5000,'2026-11-10')]),$notice);
ensure(array_column($initial['items'],'amountCents') === [10000] && !$initial['pendingChanges'],
    'İlk tutar doğrudan kaydedilmeli ve geçmiş veri oluşturulmamalı.');
$changed = $store->observe(1,sample([panel(12000)]),$notice);
$request = $changed['pendingChanges'][0];
ensure(array_column($changed['items'],'amountCents') === [10000]
    && $request['beforeCents'] === 10000 && $request['proposedCents'] === 12000,
    'Yeni tutar onaya kadar eski yayımlanan tutarın yerini alamaz.');
ensure(count($notices)===1 && $store->pendingRevision(1,$request['revision'])===$request
    && $store->pendingRevision(2,$request['revision'])===null, 'Bildirim yalnız ilgili şirketin değişikliğini açmalı.');
$repeat = $store->observe(1,sample([panel(12000,'2026-11-23','2026-11-15')],'2026-11-15'),$notice);
ensure($repeat['pendingChanges'] === [$request] && count($notices)===1,'Eşit tutar yeni bildirim üretmemeli.');
$old = $store->observe(1,sample([panel(12500)]),$notice);
ensure($old['pendingChanges'] === [$request] && count($notices)===1,'Eski kaynak gözlemi yeni öneriyi ezmemeli.');
expectHttp(403,fn()=> $store->approve(['id'=>1,'role'=>'client'],1,$request['key'],$request['revision']));
expectHttp(409,fn()=> $store->approve($actor,2,$request['key'],$request['revision']));
expectHttp(409,fn()=> $store->approve($actor,1,$request['key'],$request['revision'],
    fn()=>sample([manual(13000,10)]),$notice));
ensure($store->pendingRevision(1,$request['revision'])===$request && count($notices)===1,
    'Kaynak yokken manuel tahmin eskisini değiştirmemeli veya bekleyen onayı uygulamamalı.');

expectHttp(409,fn()=> $store->approve($actor,1,$request['key'],$request['revision'],
    fn()=>sample([panel(13000,'2026-11-23','2026-11-15')],'2026-11-15'),$notice));
$replacement = $store->report(1,'2026-11-15')['pendingChanges'][0];
ensure($replacement['proposedCents'] === 13000 && $replacement['revision'] !== $request['revision']
    && count($notices)===2 && $store->pendingRevision(1,$request['revision'])===null,
    'Kaydın değişmesi eski onayı devre dışı bırakmalı ve yeni bildirimi kaydetmeli.');
$latest = fn()=>sample([panel(13000,'2026-11-23','2026-11-15')],'2026-11-15');
$approval = $store->approve($actor,1,$replacement['key'],$replacement['revision'],$latest,$notice);
ensure($approval['approved'] && $store->approve($actor,1,$replacement['key'],$replacement['revision'],$latest,$notice)===$approval
    && (int)$db->one('SELECT COUNT(*) AS n FROM audit')['n'] === 1,
    'Onay yinelenirse aynı işlem dönmeli ve ikinci denetim kaydı yazılmamalı.');
ensure(array_column($store->report(1,'2026-11-16')['items'],'amountCents') === [13000],
    'Onaylanan tutar tabloya geçmeli.');

$replaced = $store->observe(1,sample([panel(14000,'2026-11-23','2026-11-16')],'2026-11-16'),$notice);
$reverted = $store->observe(1,sample([panel(13000,'2026-11-23','2026-11-17')],'2026-11-17'),$notice);
ensure($replaced['pendingChanges'] && !$reverted['pendingChanges'] && count($notices)===3,
    'Kaynak aynı tutara geri döndüğünde eski teklif kapanmalı.');
expectHttp(409,fn()=> $store->approve($actor,1,$replaced['pendingChanges'][0]['key'],$replaced['pendingChanges'][0]['revision']));

$manuals = $store->observe(2,sample([manual(50,10),manual(100,11)]),$notice);
ensure(array_column($manuals['items'],'amountCents') === [50,100],'Aynı gündeki ayrı manuel kalemler korunmalı.');
$edit = $store->observe(2,sample([manual(200,10)]),$notice);
ensure(array_column($edit['items'],'amountCents')===[200] && !$edit['pendingChanges'] && count($notices)===3,
    'Doğrudan manuel düzenleme ve silme kendiliğinden ek onay istememeli.');
$combined = $store->observe(2,sample([panel(800)]),$notice);
ensure(array_column($combined['items'],'amountCents')===[200] && count($combined['pendingChanges'])===1 && count($notices)===4,
    'Otomatik kaynak aynı günün manuel tutarını iki defa göstermeden onaya sunmalı.');
$omitted = $store->observe(2,sample([manual(200,10)]),$notice);
ensure($omitted['pendingChanges']===$combined['pendingChanges'] && count($notices)===4,
    'Kaynak geçici olarak eksikse bekleyen değişiklik kapanmamalı.');
$removed = $store->observe(2,sample([]),$notice);
ensure(!$removed['items'] && !$removed['pendingChanges'],'Silinen manuel kayıt sonraki döneme taşınmamalı.');

$paid = [['channel'=>'Hepsiburada','date'=>'2026-11-23']];
$future = $store->observe(1,sample([],'2026-11-19',$paid),$notice);
ensure(!$future['items'] && !$future['pendingChanges'],'Gelecekteki kapanmış ödeme çağdaş tahminden çıkarılmalı.');
$history = $store->report(1,'2026-11-24');
ensure(array_column($history['items'],'amountCents') === [13000],'Gerçekleşen günün tahmin geçmişi korunmalı.');
$pendingPast = $store->observe(1,sample([panel(100,'2026-12-01','2026-11-25')],'2026-11-25'),$notice);
$pendingPast = $store->observe(1,sample([panel(200,'2026-12-01','2026-11-26')],'2026-11-26'),$notice)['pendingChanges'][0];
ensure($store->approve($actor,1,$pendingPast['key'],$pendingPast['revision'],fn()=>sample([],'2026-12-02'))['approved'],
    'Kaynak günü geçse de kayıtlı değişiklik onaylanabilmeli.');
ensure(array_column($store->report(1,'2026-12-02')['items'],'amountCents') === [13000,200],
    'Onay geçmişteki doğru satır üzerinde saklanmalı.');

$futureDay = '2026-12-12';
$live = [...panel(17000,$futureDay,'2026-12-02'),'source'=>'marketplace',
    'observedAt'=>strtotime('2026-12-02T13:00:00+03:00')];
unset($live['observedDate']);
$store->observe(1,sample([$live],'2026-12-02'),$notice);
$fresh = [...$live,'amountCents'=>19000,'observedAt'=>strtotime('2026-12-02T14:00:00+03:00')];
$change = $store->observe(1,sample([$fresh],'2026-12-02'),$notice)['pendingChanges'][0];
$oldPanel = $store->observe(1,sample([panel(16000,$futureDay,'2026-12-02')],'2026-12-02'),$notice);
ensure($oldPanel['pendingChanges']===[$change] && array_column($oldPanel['items'],'amountCents')===[13000,200,17000],
    'Kesinti sırasında önceki panel görüntüsü yeni canlı değer ve onayın yerine geçmez.');
$zero = [...$fresh,'amountCents'=>0,'observedAt'=>strtotime('2026-12-02T15:00:00+03:00')];
$zeroResult = $store->observe(1,sample([$zero],'2026-12-02'),$notice);
$zeroChange = $zeroResult['pendingChanges'][0];
ensure($zeroChange['proposedCents']===0 && array_column($zeroResult['items'],'amountCents')===[13000,200,17000],
    'Pazaryerinde açıkça sıfırlanan tutar yayından çıkmadan önce onaya sunulur.');
$store->approve($actor,1,$zeroChange['key'],$zeroChange['revision'],fn()=>sample([$zero],'2026-12-02'),$notice);
ensure(array_column($store->report(1,'2026-12-02')['items'],'amountCents')===[13000,200],
    'Sıfıra inen ve onaylanan ödeme tabloda ve dışa aktarım girdisinde yer almaz.');
$store->observe(1,sample([[...$zero,'date'=>'2026-12-16']],'2026-12-02'),$notice);
ensure(count($store->report(1,'2026-12-02')['items'])===2,
    'Daha önce ödeme olmayan bir tarihte sıfır tutar gösterilmez.');

$day='2026-12-04';
$entered=[...manual(100,41,$day),'manualEntry'=>true];
$store->observe(2,sample([$entered]));
$official=[...panel(7300,$day),'source'=>'marketplace','observedAt'=>1795550000];
$live=$store->observe(2,sample([$official]));
ensure(array_column($live['items'],'amountCents')===[7300] && !$live['pendingChanges'],
    'İlk canlı yayın aynı günün tahmini manuel tabanını doğrudan değiştirir.');
$following=[...$official,'amountCents'=>7600,'observedAt'=>$official['observedAt']+1];
$changed=$store->observe(2,sample([$following]));
ensure(array_column($changed['items'],'amountCents')===[7300] && $changed['pendingChanges'][0]['proposedCents']===7600,
    'İki canlı tutar arasındaki fark yöneticinin onayını bekler.');
$fallback=$store->observe(2,sample([$entered]));
ensure($fallback['items']===$changed['items'] && $fallback['pendingChanges']===$changed['pendingChanges'],
    'Geçici kaynak yokluğu manuel tahmini geri getirmez veya onay sırasını bozmaz.');

OrderNotificationSchema::install($db);
$db->run("INSERT INTO companies(id,name) VALUES(3,'Dönem Testi')");
$notifier=new UpcomingPayoutNotice($db);
$days=['2026-11-20','2026-12-04','2026-12-05'];
$initial=array_map(fn(string $day):array=>panel(10000,$day),$days);
$new=array_map(fn(string $day):array=>panel(12000,$day),$days);
$store->observe(3,sample($initial,'2026-11-20'),periodNotices:$notifier);
$recorded=$store->observe(3,sample($new,'2026-11-20'),periodNotices:$notifier);
ensure(count($recorded['pendingChanges'])===3,'İleri dönemdeki teklif kaydedilmeli.');
$noticeDates=fn():array=>array_map(fn(array $row):string=>json_decode($row['payload'],true)['payoutDate'],
    $db->all('SELECT payload FROM order_notifications WHERE company_id=3 ORDER BY id'));
ensure($noticeDates()===array_slice($days,0,2),'İlk ve on beşinci gün bildirim alır, on altıncı gün bekler.');
$store->observe(3,sample($new,'2026-11-23'),periodNotices:$notifier);
ensure($noticeDates()===array_slice($days,0,2),'Açık dönemin bildirimi yinelenmemeli.');
$store->observe(3,sample([$new[2]],'2026-12-05'),periodNotices:$notifier);
ensure($noticeDates()===$days,'İleri kayıt kendi dönemi başladığında bir kez bildirilir.');
$db->run("INSERT INTO companies(id,name) VALUES(4,'Manuel onay'),(5,'Kaynak dönüşü')");
$original=[...panel(8291186,'2026-11-27'),'channel'=>'n11','id'=>'n11:2026-11-27','source'=>'marketplace','observedAt'=>100];
$proposed=[...$original,'amountCents'=>4348832,'observedAt'=>200];
foreach([4,5] as $company){
    $store->observe($company,sample([$original],'2026-11-27'));
    $store->observe($company,sample([$proposed],'2026-11-27'));
    $store->observe($company,sample([],'2026-11-27'));
}
$manualRequest=$store->report(4,'2026-11-27')['pendingChanges'][0];
expectHttp(403,fn()=>$store->approve(['id'=>1,'role'=>'client'],4,$manualRequest['key'],$manualRequest['revision'],fn()=>sample([],'2026-11-27'),manual:true));
expectHttp(409,fn()=>$store->approve($actor,5,$manualRequest['key'],$manualRequest['revision'],fn()=>sample([],'2026-11-27'),manual:true));
$manualResult=$store->approve($actor,4,$manualRequest['key'],$manualRequest['revision'],fn()=>sample([],'2026-11-27'),manual:true);
ensure($manualResult['approved'],'Kaynak yokken açık yönetici onayı kabul edilmeli.');
$afterManual=$store->observe(4,sample([[...$proposed,'amountCents'=>99999,'observedAt'=>300]],'2026-11-27'));
ensure($afterManual['items'][0]['amountCents']===4348832&&!$afterManual['pendingChanges']
    &&$afterManual['items'][0]['manualAmountApproval']['userId']===1
    &&!isset($afterManual['items'][0]['paymentApproval']),'Manuel tutar korunmalı; ödeme onayı oluşturulmamalı.');
$replay=$store->approve($actor,4,$manualRequest['key'],$manualRequest['revision'],fn()=>sample([],'2026-11-27'),manual:true);
ensure($replay===$manualResult,'Manuel onay yinelenince aynı sonuç dönmeli.');
$empty=$store->report(5,'2026-11-27');
$recoveryRequest=$empty['pendingChanges'][0];
ensure($empty['items'][0]['amountCents']===8291186,'Eksik kaynak tutarı silmemeli.');
$stale=$store->observe(5,sample([$proposed],'2026-11-27'));
ensure($stale['items'][0]['amountCents']===8291186,'Aynı eski gözlem otomatik kabul edilmemeli.');
$recovered=$store->observe(5,sample([[...$proposed,'observedAt'=>300]],'2026-11-27'));
ensure($recovered['items'][0]['amountCents']===4348832&&!$recovered['pendingChanges']
    &&!isset($recovered['items'][0]['paymentApproval']),'Yeni tamamlanan aktarım tutarı düzeltmeli; ödeme onayı oluşturmamalı.');
expectHttp(409,fn()=>$store->approve($actor,5,$recoveryRequest['key'],$recoveryRequest['revision'],fn()=>sample([],'2026-11-27'),manual:true));

Omnigo\FinanceSchema::install($db);
$db->run("INSERT INTO companies(id,name) VALUES(6,'Elle düzenleme'),(7,'Onay sırasında aktarım')");
foreach([6,7] as $company){
    $store->observe($company,sample([$original],'2026-11-27'));
    $store->observe($company,sample([$proposed],'2026-11-27'));
    $store->observe($company,sample([],'2026-11-27'));
}
(new Omnigo\FinanceStore($db))->writeState(6,'payout_adjustments',[$original['id']=>['amountCents'=>7654321]],null);
$edited=$store->observe(6,sample([[...$proposed,'observedAt'=>300]],'2026-11-27'));
ensure($edited['items'][0]['amountCents']===8291186&&count($edited['pendingChanges'])===1,
    'Elle düzenleme varken kaynak dönüşü onaylı tabanı otomatik değiştirmemeli.');
ensure(json_decode((new Omnigo\FinanceStore($db))->state(6,'payout_adjustments')['payload'],true)[$original['id']]['amountCents']===7654321,
    'Elle girilen tutar aktarım sonrasında korunmalı.');
$during=$store->report(7,'2026-11-27')['pendingChanges'][0];
expectHttp(409,fn()=>$store->approve($actor,7,$during['key'],str_repeat('a',32),fn()=>sample([],'2026-11-27'),manual:true));
$store->approve($actor,7,$during['key'],$during['revision'],fn()=>sample([[...$proposed,'observedAt'=>300]],'2026-11-27'),manual:true);
$protected=$store->observe(7,sample([[...$proposed,'amountCents'=>99999,'observedAt'=>400]],'2026-11-27'));
ensure($protected['items'][0]['amountCents']===4348832&&isset($protected['items'][0]['manualAmountApproval']),
    'Onay sırasında kaynak dönse bile açık manuel onay kaydedilmeli ve korunmalı.');

echo "Hakediş tutarı onayı ve geçmişi doğrulandı.\n";
