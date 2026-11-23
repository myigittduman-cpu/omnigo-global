<?php
declare(strict_types=1);
namespace Omnigo;

final class ReportingRoutes
{
    public function __construct(private Database $db,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        (new FinanceOverviewRoutes($this->db,$this->security))->handle($path,$method,$user);
        if ($path==='/api/payments/upcoming-panel') {
            (new UpcomingPayoutSourceRoute($this->db))->handle($method,$user);
        }
        if ($path==='/api/payments/upcoming-adjustment' && $method==='POST') {
            Security::admin($user);
            $data=Http::body(2048);
            $company=$this->security->company($user,$data['company']??null);
            Http::json((new UpcomingPayoutAdjustment($this->db))->save($user,$company,$data));
        }
        if ($path==='/api/payments/upcoming-review' && $method==='POST') {
            Security::admin($user);
            $data=Http::body(2048);
            $company=$this->security->company($user,$data['company']??null);
            $key=$data['key']??null;$revision=$data['revision']??null;
            if (!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)
                ||!is_string($revision)||!preg_match('/^[a-f0-9]{32}$/D',$revision)) {
                Http::fail(400,'Hakediş değişikliği geçersiz.');
            }
            if (!UpcomingPayoutReviewStore::available($this->db)) Http::fail(503,'Hakediş onayı şu anda kullanılamıyor.');
            Http::json((new UpcomingPayoutReviewService($this->db))->approve($user,$company,$key,$revision));
        }
        if($method!=='GET')return;
        if($path==='/api/work/pending-counts'){
            $company=$this->security->company($user,$_GET['company']??null);
            $cipher=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
            Http::json(['counts'=>(object)(new PendingWorkSummary($this->db,$cipher))->read($user,$company)]);
        }
        if($path==='/api/admin/connection-health') {
            Security::admin($user);$page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT);
            if($page===false)Http::fail(400,'Sayfa numarası geçersiz.');
            $company=$this->security->company($user,$_GET['company']??null);
            Http::json((new ConnectionHealth($this->db))->report($user,$page,$company));
        }
        if(!in_array($path,['/api/analytics/report','/api/payments/calendar'],true))return;
        $company=$this->security->company($user,$_GET['company']??null);
        $from=Validation::date($_GET['from']??null);$to=Validation::date($_GET['to']??null);
        $channel=Validation::choice($_GET['channel']??'all',['all',...Repository::CHANNELS]);
        $periodStart = $path === '/api/payments/calendar' && isset($_GET['period'])
            ? ($_GET['period'] === '' ? '' : Validation::date($_GET['period'])) : null;
        $result=$path==='/api/analytics/report'
            ?(new ReportingService($this->db,new HistoricalSalesArchive($this->db)))->report($user,$company,$from,$to,$channel)
            :(new PaymentCalendar($this->db))->report($user,$company,$from,$to,$channel,true,true,periodStart:$periodStart);
        $analytics=$path==='/api/analytics/report';
        Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,$result,salesAmounts:$analytics,
            page:$analytics?'analytics':'payments'));
    }
}
