<?php
declare(strict_types=1);
namespace Omnigo;

final class Repository
{
    public const CHANNELS = ['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Teknosa','Trendyol'];
    public function __construct(private Database $db,private ?UndeliveredSalesReader $salesProjection=null) {}
    public function createCompany(string $name,?array $marketplaces=null): int
    {
        $this->db->run('INSERT INTO companies(name) VALUES(?)', [$name]);
        $id = $this->db->insertId();
        foreach ($marketplaces??self::CHANNELS as $channel) {
            $value = ['name'=>$channel,'status'=>'active','storeName'=>'','connection'=>'not_connected',...($marketplaces===null?[]:['selected'=>true])];
            $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)', [$id,'channels',json_encode($value,JSON_UNESCAPED_UNICODE),gmdate('c')]);
        }
        return $id;
    }
    public function records(int $company, string $kind, array $user): array
    {
        $order = $kind === 'channels' ? 'ASC' : 'DESC';
        $rows = $this->db->all('SELECT * FROM records WHERE company_id=? AND kind=? ORDER BY id '.$order, [$company,$kind]);
        $result = [];
        foreach ($rows as $row) {
            $value = json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if($kind==='payments'&&(!empty($value['integration'])||!empty($value['archivedAt'])))continue;
            if ($user['role'] !== 'admin' && (($kind === 'tasks' && $value['visibility'] !== 'client') || ($kind === 'reports' && $value['status'] !== 'published'))) continue;
            $result[] = [...$value, 'id'=>(int)$row['id'],'version'=>(int)$row['version'],'updated'=>$row['updated']];
        }
        if ($kind === 'channels') {
            // Existing company records retain their IDs; presentation order is shared by all consumers.
            $ranks = array_flip(self::CHANNELS);
            usort($result,fn($a,$b)=>($ranks[$a['name']]??PHP_INT_MAX)<=>($ranks[$b['name']]??PHP_INT_MAX));
        }
        if($kind==='payments')$result=array_map([PaymentPolicy::class,'decorate'],$result);
        return $result;
    }
    public function save(array $user, int $company, string $kind, array $data, ?int $id): array
    {
        if($kind==='payments')Security::admin($user);
        if($user['role']!=='admin'){
            if(!in_array($kind,['tasks','reports'],true))Security::admin($user);
            (new CustomerPermissions($this->db,$company))->require($user,$kind,$id?'edit':'create');
            if($kind==='tasks')$data['visibility']='client';
            if($kind==='reports')$data['status']='published';
        }
        $value = Validation::record($kind,$data);
        $manualForecast = $kind === 'payments' && $id === null && $value['source'] === UpcomingManualPayout::SOURCE;
        $save = function() use($user,$company,$kind,$data,$id,$value,$manualForecast) {
            // Serialise writes per company, including duplicate period checks.
            if ($manualForecast && !$this->db->mysql()) $this->db->run('UPDATE records SET version=version WHERE 1=0');
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if ($manualForecast && ($record=UpcomingManualPayout::existing($this->db,$this,$user,$company,$value))) {
                if ($record['source'] === $value['source'] && $record['expectedCents'] === $value['expectedCents']) return $record;
                $id = $record['id'];
                $data['version'] = $record['version'];
                $value = [...$value,...array_intersect_key($record,array_flip(['periodStart','periodEnd','reference','notes','taxBasis','details']))];
            }
            if($user['role']!=='admin')(new CustomerPermissions($this->db,$company))->require($user,$kind,$id?'edit':'create');
            $existing = $id ? $this->db->one('SELECT * FROM records WHERE id=? AND company_id=? AND kind=?',[$id,$company,$kind]) : null;
            if ($id && !$existing) Http::fail(404,'Kayıt bulunamadı.');
            if($existing&&$user['role']!=='admin'){
                $prior=json_decode($existing['payload'],true);
                if(($kind==='tasks'&&($prior['visibility']??'')!=='client')||($kind==='reports'&&($prior['status']??'')!=='published'))Http::fail(403,'Bu kaydı düzenleme yetkiniz yok.');
            }
            if ($existing && ($data['version']??null) !== (int)$existing['version']) Http::fail(409,'Bu kayıt başka bir işlemde değişti. Sayfayı yenileyin.');
            if($existing&&$kind==='payments'){
                $previous=json_decode($existing['payload'],true,32,JSON_THROW_ON_ERROR);
                if(!empty($previous['integration'])||!empty($previous['archivedAt']))Http::fail(409,'Bu hakediş arşivdedir. Yeni bir manuel hakediş ekleyin.');
                $value=PaymentApproval::afterCorrection($previous,$value,($data['correctApproved']??false)===true);
            }
            if ($existing && $kind==='sales' && in_array(json_decode($existing['payload'],true)['integration']??null,['koctas','n11','pazarama','hepsiburada'],true)) Http::fail(409,'Bu satış özeti pazaryeri API üzerinden güncellenir.');
            $channels = $this->records($company,'channels',['role'=>'admin']);
            if ($kind === 'channels') {
                if($existing)$value=[...array_intersect_key(json_decode($existing['payload'],true),array_flip(['selected','hidden'])),...$value];
                foreach ($channels as $channel) if ($channel['name'] === $value['name'] && $channel['id'] !== $id) Http::fail(409,'Bu pazaryeri zaten tanımlı.');
                if ($existing && json_decode($existing['payload'],true)['name'] !== $value['name']) Http::fail(400,'Pazaryeri adı değiştirilemez.');
            }
            if (in_array($kind,['sales','payments','tasks'],true) && !($kind==='tasks' && $value['channel']==='Tüm kanallar') && !in_array($value['channel'],array_column($channels,'name'),true)) Http::fail(400,'Firma için tanımlanmış bir pazaryeri seçin.');
            if ($kind === 'sales') foreach ($this->records($company,$kind,['role'=>'admin']) as $sale) {
                if ($sale['channel']===$value['channel'] && $sale['month']===$value['month'] && $sale['id']!==$id) Http::fail(409,'Bu kanal ve ay için satış özeti zaten var.');
            }
            $updated = gmdate('c');
            $payload = json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($existing) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,$updated,$id]);
            else {
                $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$payload,$updated]);
                $id = $this->db->insertId();
            }
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,$existing?'Güncellendi':'Eklendi',$id,$existing['payload']??null,$payload,$updated]);
            if($kind==='payments')CustomerServiceNotifications::payment($this->db,$user,$company,(int)$id,$this->db->insertId(),$existing!==null);
            if($kind==='payments'&&UpcomingPayoutReviewStore::available($this->db)) {
                (new UpcomingPayoutReviewService($this->db))->observe($company);
            }
            return [...$value,'id'=>$id,'version'=>$existing?(int)$existing['version']+1:1,'updated'=>$updated];
        };
        return $manualForecast ? TransactionRetry::run($this->db,$save) : $this->db->transaction($save);
    }
    public function dashboard(array $user, int $company, string $month, ?array $range=null): array
    {
        if($range!==null)ReportPeriod::range($range['from'],$range['to']);
        if(!$this->db->pdo->inTransaction())return $this->db->transaction(fn()=>$this->dashboard($user,$company,$month,$range));
        $result = ['company'=>CustomerBranding::read($this->db,$company,$user),'modules'=>CustomerModules::read($this->db,$company),'deliveryCities'=>CustomerCities::read($this->db,$company),'shippingScope'=>CustomerShippingScope::read((new CustomerManagement($this->db))->profile($company,true))];
        foreach (Validation::KINDS as $kind) $result[$kind] = $this->records($company,$kind,$user);
        $result['channels']=MarketplaceVisibility::rows($result['channels'],'name');
        $historyChannels=CustomerMarketplaces::historyChannels($this->db,$company,$result['channels'],$result['sales']);
        foreach (['sales','payments','tasks','reports'] as $kind) $result[$kind]=MarketplaceVisibility::rows($result[$kind],'channel');
        // A legacy placeholder does not enable a marketplace or revive its old figures.
        $unselected=array_column(array_filter($result['channels'],fn($row)=>!CustomerMarketplaces::available($row)),'name');
        $result['channels']=array_values(array_filter($result['channels'],[CustomerMarketplaces::class,'available']));
        foreach(['sales','payments','tasks','reports'] as $kind)$result[$kind]=array_values(array_filter($result[$kind],fn($row)=>!in_array($row['channel']??'',$unselected,true)));
        $result['channels']=IntegrationRoutes::channels($this->db,$company,$result['channels']);
        $health=(new IntegrationHealthSnapshot($this->db))->badges($company);
        foreach($result['channels'] as &$channel)$channel['health']=$health[$channel['name']]??null;
        unset($channel);
        if ($user['role'] !== 'admin') {
            $result['channels'] = array_values(array_filter($result['channels'],fn($r)=>$r['status']==='active'));
            $active = array_column([...$result['channels'],...$historyChannels],'name');
            $result['sales'] = array_values(array_filter($result['sales'],fn($r)=>in_array($r['channel'],$active,true)));
            $result['tasks'] = array_values(array_filter($result['tasks'],fn($r)=>in_array($r['status'],['planned','running'],true) && ($r['channel']==='Tüm kanallar'||in_array($r['channel'],$active,true))));
            // Company-scoped receipts and receivables share the same totals in both portals.
            $result['payments'] = array_values(array_filter($result['payments'],fn($r)=>in_array($r['status'],['pending','sent','paid'],true)));
        }
        $projection=$this->salesProjection??new UndeliveredSalesReader($this->db);
        $reportChannels=[...$result['channels'],...$historyChannels];
        $allPayments=$result['payments'];
        $clock=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul'));
        $result['marketplaceSales']=(new MarketplaceSalesSummary($this->db,$projection))->dashboard($company,$reportChannels,$month,$clock,$range,includePeriodMetrics:$user['role']==='admin',customerView:$user['role']==='client');
        // Hiding/deleting a store changes presentation and connectivity, never historical totals.
        $result['channels']=array_values(array_filter($result['channels'],[CustomerMarketplaces::class,'shown']));
        $idefix=MarketplaceSalesSummary::idefixSale($result['marketplaceSales'],$month);
        if($idefix){$result['sales']=array_values(array_filter($result['sales'],fn($sale)=>$sale['channel']!=='idefix'||$sale['month']!==$month));$result['sales'][]=$idefix;}
        $ptt=MarketplaceSalesSummary::idefixSale($result['marketplaceSales'],$month,'PTT AVM');
        if($ptt){$result['sales']=array_values(array_filter($result['sales'],fn($sale)=>$sale['channel']!=='PTT AVM'||$sale['month']!==$month));$result['sales'][]=$ptt;}
        $trendyol=MarketplaceSalesSummary::idefixSale($result['marketplaceSales'],$month,'Trendyol');
        if($trendyol){$result['sales']=array_values(array_filter($result['sales'],fn($sale)=>$sale['channel']!=='Trendyol'||$sale['month']!==$month));$result['sales'][]=$trendyol;}
        $result['salesToday']=SalesCalendar::today($result['sales']);
        foreach($result['marketplaceSales']['channels'] as $item)if(in_array($item['channel'],['idefix','PTT AVM','Trendyol'],true)&&$item['available']){
            $today=$item['periods']['today'];$result['salesToday']['channels'][]=['channel'=>$item['channel'],'available'=>$today['available'],'netCents'=>$today['amountCents'],'orders'=>$today['orders'],'asOf'=>$item['asOf']];
        }
        if($range===null&&$projection->enabled($company))$result=UndeliveredSales::dashboard($result,$month);
        if($range!==null){$result['sales']=DashboardDateRange::sales($result['marketplaceSales'],$month);$result['dateRange']=$range;}
        $inRange=fn(?string $date)=>$date!==null&&$date!==''&&($range!==null?DashboardDateRange::includes($date,$range['from'],$range['to']):str_starts_with($date,$month));
        $result['sales']=array_values(array_filter($result['sales'],fn($r)=>$r['month']===$month));
        $result['reports']=array_values(array_filter($result['reports'],fn($r)=>$range!==null?($r['month']>=substr($range['from'],0,7)&&$r['month']<=substr($range['to'],0,7)):$r['month']===$month));
        foreach ($result['sales'] as &$sale) unset($sale['dailySales']);
        unset($sale);
        $result['tasks'] = array_values(array_filter($result['tasks'],fn($r)=>$inRange($r['date'])));
        $result['missingPaymentChannels']=PaymentPolicy::missing($result['payments'],array_column($result['channels'],'name'));
        $result['payments'] = array_values(array_filter($result['payments'],fn($r)=>$r['expectedDate']===''||$inRange($r['expectedDate'])||$inRange($r['paidDate'])));
        $summary = array_fill_keys(['grossCents','cancelCents','returnCents','orders','expectedCents','paidCents','sentCents','undatedCents','undatedCount'],0);
        foreach ($result['sales'] as $row) foreach (['grossCents','cancelCents','returnCents','orders'] as $key) $summary[$key] += $row[$key];
        foreach ($result['payments'] as $row) {
            if($row['excludedFromTotals'])continue;
            if($row['status']!=='paid'&&$row['expectedDate']===''){$summary['undatedCents']+=$row['expectedCents'];$summary['undatedCount']++;}
            if ($row['status'] === 'pending' && $inRange($row['expectedDate'])) $summary['expectedCents'] += $row['expectedCents'];
            if ($row['status'] === 'sent' && $inRange($row['expectedDate'])) $summary['sentCents'] += $row['expectedCents'];
            if ($row['status'] === 'paid' && $inRange($row['paidDate'])) $summary[PaymentApproval::isApproved($row)?'paidCents':'sentCents'] += $row['actualCents'];
        }
        $summary['netCents'] = $summary['grossCents']-$summary['cancelCents']-$summary['returnCents'];
        $summary['refundsVerified']=!array_filter($result['sales'],fn($sale)=>($sale['refundsVerified']??true)===false);
        $result['summary'] = $summary;
        $result=TurnoverAmounts::dashboard($result,customerView:$user['role']==='client');
        $result=CustomerDashboardAccess::filter($this->db,$user,$company,$result);
        if(!empty($result['workspaceAccess']['grants']['payments']['view'])){
            $reportChannels=CustomerMarketplaces::payoutChannels($reportChannels,$allPayments);
            $paymentChannels=array_column($reportChannels,'name');
            $result['upcoming']=(new UpcomingPaymentForecast($this->db))->read($company,$allPayments,$reportChannels,$paymentChannels);
            if($user['role']==='admin')$result['overviewPaymentPeriods']=OverviewPaymentPeriods::summarize($allPayments,$paymentChannels,$clock);
        }
        if($user['role']==='admin') {
            $inbox=new InboxRepository($this->db);
            if($inbox->ready()){
                try{$result['marketplaceQuestionsPending']=$inbox->waitingCount($company);}
                catch(\PDOException $error){error_log('Omnigo soru sayacı: '.get_class($error));}
            }
            try{$result['marketplaceRequestsPending']=(new ReturnRequestList($this->db))->pendingCount($company);}
            catch(\Throwable $error){error_log('Omnigo talep sayacı: '.get_class($error));}
        }
        return $result;
    }
}
