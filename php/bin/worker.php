<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
$config = require (getenv('OMNIGO_CONFIG') ?: dirname(__DIR__).'/config.php');
if (is_file(dirname(__DIR__).'/mail.config.php')) $config['mail']=require dirname(__DIR__).'/mail.config.php';
$db = new \Omnigo\Database($config['database']);
\Omnigo\PaymentPolicy::disableAutomaticJobs($db);
$vault = new \Omnigo\IntegrationVault($db,dirname(__DIR__).'/storage/backup.key');
$deliveries = new \Omnigo\DeliveryRepository($db,new \Omnigo\DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
$hepsiburadaQuestions = \Omnigo\HepsiburadaQuestionClient::JOB;
$trendyolQuestions = \Omnigo\TrendyolQuestionClient::JOB;
$queue = new \Omnigo\SyncQueue($db,['Trendyol'=>new \Omnigo\TrendyolSyncAdapter($db,$vault,$deliveries),'n11 Sorular'=>new \Omnigo\MarketplaceQuestionAdapter($db,$vault,'n11'),'idefix Sorular'=>new \Omnigo\MarketplaceQuestionAdapter($db,$vault,'idefix'),$trendyolQuestions=>new \Omnigo\TrendyolQuestionAdapter($db,$vault),$hepsiburadaQuestions=>new \Omnigo\MarketplaceQuestionAdapter($db,$vault,\Omnigo\HepsiburadaQuestionClient::PROVIDER),'Koçtaş'=>new \Omnigo\KoctasAdapter($db,$vault,deliveries:$deliveries),'Koçtaş Sorular'=>new \Omnigo\KoctasQuestionAdapter($db,$vault),'Pazarama'=>new \Omnigo\PazaramaSyncAdapter($db,$vault,deliveries:$deliveries),'Pazarama Sorular'=>new \Omnigo\PazaramaQuestionAdapter($db,$vault),'PTT AVM'=>new \Omnigo\PttavmSyncAdapter($db,$vault,$deliveries),'idefix'=>new \Omnigo\IdefixSyncAdapter($db,$vault,$deliveries),'Hepsiburada Satış'=>new \Omnigo\HepsiburadaSalesSync($db,$vault),'Hepsiburada'=>new \Omnigo\HepsiburadaOrderAdapter($db,$vault,$deliveries),'n11'=>new \Omnigo\N11SyncAdapter($db,$vault,deliveries:$deliveries)]);
\Omnigo\MarketplaceQuestionAdapter::schedule($db,$vault);
\Omnigo\TrendyolQuestionAdapter::schedule($db,$vault,$queue);
\Omnigo\KoctasQuestionAdapter::schedule($db,$vault,$queue);



$end = time()+45;
try {
    if(is_file(dirname(__DIR__).'/storage/order-reconciliation.enabled')){
        try{
            $path=dirname(__DIR__).'/storage/reconciliation-status.json';$previous=is_file($path)?json_decode(file_get_contents($path),true):[];
            $result=(new \Omnigo\ReconciliationMonitor($db,new \Omnigo\DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->run(min($end,time()+5),(int)($previous['cursor']??0));
            file_put_contents($path,json_encode($result,JSON_THROW_ON_ERROR),LOCK_EX);
        }catch(\Throwable $error){error_log('Omnigo mutabakat hazırlığı: '.get_class($error));}
    }
    $mail=new \Omnigo\MailQueue($db,$config);
    try{(new \Omnigo\OperationalMailEvents($db,new \Omnigo\DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->enqueue($mail);}
    catch(\Throwable $error){error_log('Omnigo operasyon e-posta hazırlığı: '.get_class($error));}
    $inbox=new \Omnigo\InboxWorker($db,$vault);
    $inbox->runOne();
    try { (new \Omnigo\PaymentApprovalReminder($db))->run(); }
    catch (\Throwable $error) { error_log('Omnigo hakediş onay hatırlatması: '.get_class($error)); }
    $push=new \Omnigo\PushWorker($db);$pushEnd=min($end,time()+15);
    while(time()<$pushEnd && $push->runOne()) {}
    while (time()<$end && $mail->runOne()) {}
    $backgroundProviders=is_file(dirname(__DIR__).'/storage/order-workers.enabled')&&\Omnigo\RecentOrderSchema::available($db)?['Trendyol','n11 Sorular','idefix Sorular','Pazarama Sorular','Koçtaş Sorular',$hepsiburadaQuestions,$trendyolQuestions]:null;
    $financeJobs=[\Omnigo\PazaramaCommissionAdapter::JOB,...array_values(\Omnigo\UpcomingPayoutAdapter::JOBS)];
    while (time()<$end && $queue->runOne(null,$backgroundProviders,$end,[...$financeJobs,\Omnigo\TrendyolClaimAdapter::JOB])) {}
    try {
        $commissionJob=\Omnigo\PazaramaCommissionAdapter::JOB;
        $commissionQueue=new \Omnigo\SyncQueue($db,[$commissionJob=>new \Omnigo\PazaramaCommissionAdapter($db,$vault)]);
        \Omnigo\PazaramaCommissionAdapter::schedule($db,$vault,$commissionQueue);
        $commissionQueue->runOne(null,[$commissionJob],time()+12);
    } catch (\Throwable $error) { error_log('Omnigo komisyon kuyruğu: '.get_class($error)); }
    try { \Omnigo\UpcomingPayoutAdapter::run($db,$vault,time()+12); }
    catch (\Throwable $error) { error_log('Omnigo yaklaşan hakediş kuyruğu: '.get_class($error)); }
    try { \Omnigo\UpcomingPayoutReviewService::run($db); }
    catch (\Throwable $error) { error_log('Omnigo hakediş değişikliği kontrolü: '.get_class($error)); }
    try { (new \Omnigo\InboxHepsiburadaReplyVerification($db))->pollDue($vault,time()+8); }
    catch (\Throwable $error) { error_log('Omnigo Hepsiburada yanıt kuyruğu: '.get_class($error)); }
    file_put_contents(dirname(__DIR__).'/worker-status.json',json_encode(['lastRun'=>gmdate('c'),'mailConfigured'=>$mail->ready(),'imageProcessing'=>function_exists('imagecreatefromstring')]));
}
catch (\Throwable $error) { error_log('Omnigo queue: '.get_class($error)); }
finally {
    try{(new \Omnigo\IntegrationAlertMonitor($db,new \Omnigo\MailQueue($db,$config)))->run();}catch(\Throwable $error){error_log('Omnigo bağlantı uyarıları: '.get_class($error));}
}
try { \Omnigo\TrendyolClaimAdapter::run($db,$vault,time()+12); }
catch (\Throwable $error) { error_log('Omnigo Trendyol iade kuyruğu: '.get_class($error)); }
try {
    $manualRecords=new \Omnigo\NewRecordWorker($db,$vault,$deliveries);
    $manualEnd=time()+18;
    while(time()+9<$manualEnd&&$manualRecords->runOne($manualEnd)) {}
} catch (\Throwable $error) { error_log('Omnigo yeni kayıt kuyruğu: '.get_class($error)); }

try { \Omnigo\StoreSetupRun::check($db); }
catch (\Throwable $error) { error_log('Omnigo mağaza kurulum aktarımı: '.get_class($error)); }

try {
    $historyImporter=new \Omnigo\PazaramaHistoryImporter($db,new \Omnigo\DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
    (new \Omnigo\PazaramaHistoryWorker($db,$vault,$historyImporter))->runOne(time()+15);
} catch (\Throwable $error) { error_log('Omnigo Pazarama arşiv aktarımı: '.get_class($error)); }

