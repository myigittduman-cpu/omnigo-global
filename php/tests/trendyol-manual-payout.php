<?php
declare(strict_types=1);

$bootstrap=$argv[1]??dirname(__DIR__).'/bootstrap.php';
require $bootstrap;
require_once dirname(__DIR__).'/src/UpcomingManualPayout.php';
require_once dirname(__DIR__).'/src/MarketplaceServiceHealth.php';

use Omnigo\{CustomerMarketplaces, Database, DeliverySchema, FinanceSchema, FinanceStore, HttpError, MarketplaceServiceHealth, UpcomingPayoutAdjustment,
    OrderNotificationSchema, PaymentCalendar, PushSchema, Repository, UpcomingManualPayout, UpcomingPayoutAdapter, UpcomingPayoutReviewStore,
    UpcomingPayoutReviewService, UpcomingPayoutSource, UpcomingPayoutStore};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function deny(callable $action, int $status): void
{
    try { $action(); }
    catch (HttpError $error) { check($error->getCode() === $status, 'Yanlış HTTP hata kodu.'); return; }
    throw new RuntimeException('Yetkisiz hakediş kaydı engellenmedi.');
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(dirname($bootstrap).'/tests/schema.sqlite.sql'));
FinanceSchema::install($db);
DeliverySchema::install($db);
UpcomingPayoutReviewStore::install($db);
OrderNotificationSchema::install($db);
PushSchema::install($db);
$repo = new Repository($db);
$admin = ['id'=>1, 'role'=>'admin', 'name'=>'Test yöneticisi'];
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Test yöneticisi','hash','admin',NULL)");
$connected = $repo->createCompany('Trendyol mağazası', ['Trendyol','Hepsiburada']);
$pending = $repo->createCompany('API bilgisi tamamlanmamış', ['Trendyol']);
$unselected = $repo->createCompany('Seçimsiz eski mağaza');
$existing = $repo->createCompany('Mevcut manuel kaynaklar', ['PTT AVM','idefix','Koçtaş','Pazarama']);
$disabled = $repo->createCompany('Manuel kapalı', ['Trendyol']);
$readyButInactive = $repo->createCompany('Pazaryeri pasif', ['Trendyol']);

function connection(Database $db, int $company, string $provider): void
{
    // The production eligibility check only needs the presence of the scoped integration record.
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)', [$company,'integration_'.$provider,'{}',gmdate('c')]);
}
foreach ([$connected,$unselected,$disabled,$readyButInactive] as $company) connection($db, $company, 'trendyol');
connection($db, $connected, 'hepsiburada');
$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)', [$disabled,'{"setupOptions":{"live":["payments"],"manual":[]}}']);
$inactiveRow = $repo->records($readyButInactive, 'channels', $admin)[0];
$db->run('UPDATE records SET payload=? WHERE company_id=? AND id=?', [json_encode([...$inactiveRow,'status'=>'inactive'], JSON_THROW_ON_ERROR),$readyButInactive,$inactiveRow['id']]);

check(UpcomingManualPayout::channels($db,$connected,['Trendyol','Hepsiburada']) === array_values(array_filter(Repository::CHANNELS,fn($name)=>$name!=='Teknosa')), 'Tüm görünür pazaryerlerinde elle giriş bulunmalı.');
check(UpcomingManualPayout::channels($db,$pending,['Trendyol']) === UpcomingManualPayout::channels($db,$connected,['Trendyol']), 'API kurulmamış pazaryerlerinde elle giriş açık olmalı.');
check(UpcomingManualPayout::channels($db,$unselected,['Trendyol']) === UpcomingManualPayout::channels($db,$connected,['Trendyol']), 'Bağlantı kaydı olmayan pazaryerlerinde elle giriş açık olmalı.');
check(UpcomingManualPayout::channels($db,$disabled,['Trendyol']) === UpcomingManualPayout::channels($db,$connected,['Trendyol']), 'Otomatik kaynak ayarı manuel girişleri kapatmamalı.');
check(in_array('Pazarama',UpcomingManualPayout::channels($db,$existing,['PTT AVM','idefix','Koçtaş','Pazarama']),true), 'Otomatik ve eksik kaynakların hepsi manuel listede bulunmalı.');
check(!isset(UpcomingPayoutSource::PANEL_CHANNELS['trendyol']) && !isset(UpcomingPayoutAdapter::JOBS['trendyol']), 'Trendyol otomatik kaynak olarak açılmamalı.');

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Istanbul'));
$from = $today->format('Y-m-d');
$to = $today->modify('+14 days')->format('Y-m-d');
$date = $today->modify('+2 days')->format('Y-m-d');
$payout = ['channel'=>'Trendyol','expectedDate'=>$date,'expectedCents'=>129900,'status'=>'pending','source'=>UpcomingManualPayout::SOURCE,'verification'=>'estimated'];
foreach ([$pending,$unselected,$disabled,$readyButInactive] as $company) $repo->save($admin,$company,'payments',$payout,null);
deny(fn()=>$repo->save($admin,$connected,'payments',[...$payout,'expectedCents'=>0],null),400);
deny(fn()=>$repo->save($admin,$connected,'payments',[...$payout,'expectedDate'=>$today->modify('-1 day')->format('Y-m-d')],null),400);
$client = ['id'=>7,'role'=>'client','company_id'=>$connected];
deny(fn()=>$repo->save($client,$connected,'payments',$payout,null),403);

$finance = new FinanceStore($db);
$finance->writeState($connected, UpcomingPayoutAdapter::stateKey('hepsiburada'), ['published'=>[
    'checkedAt'=>time(), 'connection'=>(new UpcomingPayoutStore($db))->connection($connected,'hepsiburada'), 'days'=>[$date=>242500],
]],null);
$calendar = new PaymentCalendar($db);
$before = $calendar->report($admin,$connected,$from,$to,'all',true,true)['upcoming'];
check($before['estimatedCents'] === 242500 && in_array('Trendyol',$before['manualChannels'],true), 'Trendyol girişinden önce mevcut otomatik kaynak aynı olmalı.');
check(in_array('Hepsiburada',$calendar->report($admin,$connected,$from,$to,'Hepsiburada',true)['upcoming']['manualChannels'],true), 'Otomatik pazaryeri filtresinde elle giriş açık olmalı.');
$saved = $repo->save($admin,$connected,'payments',$payout,null);
check($repo->save($admin,$connected,'payments',$payout,null)['id'] === $saved['id'], 'Tekrarlanan istek ikinci tahmini oluşturmamalı.');
$amount = 139900;
$updated = $repo->save($admin,$connected,'payments',[...$payout,'expectedCents'=>$amount],null);
check($updated['id'] === $saved['id'] && $updated['version'] === $saved['version']+1, 'Aynı gün ve pazaryeri tahmini tek kayıtta değişmeli.');

$calendarReport = $calendar->report($admin,$connected,$from,$to,'all',true,true);
$forecast = $calendarReport['upcoming'];
$market = array_column($forecast['periodItems'], null, 'channel');
check($calendarReport['summary']['estimatedCents'] === $amount, 'Takvim toplamı Trendyol tahminini içermeli.');
check($market['Trendyol']['source'] === 'manual' && $market['Trendyol']['amountCents'] === $amount, 'Ortak hakediş ve dışa aktarma satırı manuel olmalı.');
check($market['Hepsiburada']['source'] === 'marketplace' && $market['Hepsiburada']['amountCents'] === 242500, 'Otomatik kaynak aynı kalmalı.');
$overview = $repo->dashboard($admin,$connected,$today->format('Y-m'))['upcoming'];
check($overview['periodItems'] === array_map(fn($item)=>array_diff_key($item,['adjustmentRevision'=>true]),$forecast['periodItems'])
    && $overview['remainingCents'] === $amount+242500, 'Genel Bakış aynı dönem kayıtlarını toplamalı.');
check(!isset($overview['periodItems'][0]['adjustmentRevision'],$overview['manualRecords']), 'Düzenleme verileri yalnız yönetim ekranına gönderilmeli.');
$automatic=$market['Hepsiburada'];
$adjustments=new UpcomingPayoutAdjustment($db);
$edit=['id'=>$automatic['id'],'revision'=>$automatic['adjustmentRevision'],'action'=>'edit','date'=>$date,'amountCents'=>310000];
deny(fn()=>$adjustments->save($client,$connected,$edit),403);
deny(fn()=>$adjustments->save($admin,$pending,$edit),409);
$adjustments->save($admin,$connected,$edit);
deny(fn()=>$adjustments->save($admin,$connected,$edit),409);
$edited=array_column($calendar->report($admin,$connected,$from,$to,'all',true)['upcoming']['periodItems'],null,'channel')['Hepsiburada'];
check($edited['amountCents']===310000 && $edited['adjusted'], 'Düzeltme sonraki okumada görünmeli.');
$sourceKey=UpcomingPayoutAdapter::stateKey('hepsiburada');
$sourceConnection=(new UpcomingPayoutStore($db))->connection($connected,'hepsiburada');
$newDate=$today->modify('+18 days')->format('Y-m-d');
$refreshSource=function(int $amount)use($finance,$connected,$sourceKey,$sourceConnection,$date,$newDate):void{
    $finance->writeState($connected,$sourceKey,['published'=>[
        'checkedAt'=>time(),'connection'=>$sourceConnection,'days'=>[$date=>$amount,$newDate=>175000],
    ]],$finance->state($connected,$sourceKey));
};
$refreshSource(270000);
$pendingReport=$calendar->report($admin,$connected,$from,$to,'all',true,true)['upcoming'];
$proposal=$pendingReport['pendingChanges'][0];
$automaticRows=array_column(array_filter($pendingReport['periodItems'],fn($item)=>$item['channel']==='Hepsiburada'),null,'date');
check($automaticRows[$date]['amountCents']===310000&&$proposal['beforeCents']===242500&&$proposal['proposedCents']===270000,
    'Aynı tarihin yeni tutarı onaydan önce yönetici düzeltmesinin yerine geçmemeli.');
check($automaticRows[$newDate]['amountCents']===175000,'Yeni tarihli otomatik kayıt doğrudan görünmeli.');
$review=new UpcomingPayoutReviewService($db);
$review->approve($admin,$connected,$proposal['key'],$proposal['revision']);
$acceptedReport=$calendar->report($admin,$connected,$from,$to,'all',true)['upcoming'];
$accepted=array_column($acceptedReport['periodItems'],null,'id')[$edited['id']];
check($accepted['amountCents']===270000&&empty($accepted['adjusted'])&&!$acceptedReport['pendingChanges'],
    'Onaylanan tutar önceki yönetici düzeltmesinin yerine geçmeli.');
$adjustments->save($admin,$connected,['id'=>$accepted['id'],'revision'=>$accepted['adjustmentRevision'],'action'=>'remove']);
$refreshSource(280000);
$afterRemoval=$calendar->report($admin,$connected,$from,$to,'all',true,true)['upcoming'];
$removalProposal=$afterRemoval['pendingChanges'][0];
$review->approve($admin,$connected,$removalProposal['key'],$removalProposal['revision']);
check(!in_array($accepted['id'],array_column($calendar->report($admin,$connected,$from,$to,'all',true,true)['upcoming']['periodItems'],'id'),true),
    'Kaldırılan satır kaynak yenilendiğinde ve yeni tutar onaylandığında geri gelmemeli.');
check((new UpcomingPayoutStore($db))->rows($connected,['Hepsiburada'],$from)[0]['amountCents']===280000,'Otomatik aktarım kaynağı güncellenmeye devam etmeli.');
check(array_column($calendar->report($admin,$pending,$from,$to,'all',true,true)['upcoming']['periodItems'],'amountCents')===[$payout['expectedCents']], 'Bir mağazanın düzenlemesi diğer mağazayı etkilememeli.');
$health = new MarketplaceServiceHealth($db);
check($health->channel($connected,'trendyol',[],[],true,true)['payouts']['status'] === 'manual', 'Bağlantı kontrolü yaklaşan hakedişi manuel göstermeli.');
check($health->channel($connected,'trendyol',[],[],true,true)['payouts']['coverage'] === 'paid_orders_only', 'Gerçekleşen ödeme kapsamı korunmalı.');
check($health->channel($disabled,'trendyol',[],[],true,true)['payouts']['status'] === 'probe', 'İsteğe bağlı manuel kapsam açılmadıkça mevcut bilgi görünmeli.');

$marketplaces = new CustomerMarketplaces($db);
$marketRow = array_column($marketplaces->listing($connected),null,'provider')['trendyol'];
$marketplaces->change($admin,$connected,'trendyol',['action'=>'disconnect','version'=>$marketRow['version']]);
check(in_array('Trendyol',UpcomingManualPayout::channels($db,$connected,['Trendyol']),true), 'Bağlantı kaldırılınca manuel giriş açık kalmalı.');
check(count($repo->records($connected,'payments',$admin)) === 1, 'Bağlantı kaldırma eski belge ve ödeme kayıtlarını silmemeli.');
echo "Trendyol manuel hakediş, kapsam ve yetki, takvim, genel bakış, dışa aktarım satırı ve otomatik kaynak ayrımı geçti.\n";
