<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Omnigo\Database;
use Omnigo\HttpError;
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
echo "Hakediş tutarı onayı ve geçmişi doğrulandı.\n";
