<?php
declare(strict_types=1);
namespace Omnigo;

final class ConnectionHealth
{
    public function __construct(private Database $db) {}
    private function latestUpdates(int $company): array
    {
        $latest=[];
        $record=static function(string $provider,int $created,string $state) use (&$latest):void {
            $previous=$latest[$provider]['created']??-1;
            if($created<$previous)return;
            if($created>$previous)$latest[$provider]=['created'=>$created,'states'=>[]];
            $latest[$provider]['states'][]=$state;
        };
        if(IntegrationImportSchema::tableAvailable($this->db,'marketplace_price_jobs','state')){
            $rows=$this->db->all("SELECT DISTINCT q.provider,q.created,q.state FROM marketplace_price_jobs q
                JOIN (SELECT company_id,provider,MAX(created) AS created FROM marketplace_price_jobs WHERE company_id=? AND state IN ('succeeded','failed') GROUP BY company_id,provider) newest
                ON newest.company_id=q.company_id AND newest.provider=q.provider AND newest.created=q.created WHERE q.state IN ('succeeded','failed')",[$company]);
            foreach($rows as $row)$record($row['provider'],(int)$row['created'],$row['state']);
        }
        if(IntegrationImportSchema::tableAvailable($this->db,'marketplace_stock_requests','state')){
            foreach(MarketplaceStockJob::CHANNELS as $provider=>$label){
                $filter=$provider==='hepsiburada'?"(request_id LIKE ? OR request_id NOT LIKE 'pilot:%')":"request_id LIKE ?";
                $args=[$company,'pilot:'.$provider.':%'];
                $rows=$this->db->all("SELECT DISTINCT created,state FROM marketplace_stock_requests WHERE company_id=? AND state IN ('failed','succeeded') AND $filter
                    AND created=(SELECT MAX(created) FROM marketplace_stock_requests WHERE company_id=? AND state IN ('failed','succeeded') AND $filter)",[...$args,...$args]);
                foreach($rows as $row)$record($provider,strtotime($row['created']),$row['state']);
            }
        }
        $result=[];
        foreach($latest as $provider=>$entry){
            $states=array_unique($entry['states']);
            $result[$provider]=in_array('failed',$states,true)?'error':($states===['succeeded']?'ready':'unverified');
        }
        return $result;
    }

    public function report(array $user,int $page=0,?int $selectedCompany=null): array
    {
        Security::admin($user);
        if($page<0||$page>10000)Http::fail(400,'Sayfa numarası geçersiz.');
        $offset=$page*20;$companies=$selectedCompany===null?$this->db->all('SELECT id,name FROM companies ORDER BY id LIMIT 21 OFFSET '.$offset):$this->db->all('SELECT id,name FROM companies WHERE id=?',[$selectedCompany]);
        if($selectedCompany!==null&&!$companies)Http::fail(404,'Firma bulunamadı.');
        if($selectedCompany!==null)$page=0;
        $hasMore=count($companies)>20;$companies=array_slice($companies,0,20);$items=[];
        foreach($companies as $company) {
            $id=(int)$company['id'];$state=[];$configured=[];
            // Encrypted integration payloads, API keys and lease tokens are never selected.
            foreach($this->db->all("SELECT kind FROM records WHERE company_id=? AND kind IN ('integration_koctas','integration_pazarama','integration_n11','integration_hepsiburada','integration_pttavm','integration_idefix','integration_trendyol')",[$id]) as $r)$configured[$r['kind']]=true;
            foreach($this->db->all("SELECT kind,payload FROM records WHERE company_id=? AND kind IN ('sync_koctas','sync_pazarama','sync_n11','sync_hepsiburada','sync_pttavm','sync_idefix','sync_trendyol')",[$id]) as $r)$state[$r['kind']]=json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR);
            $jobs=[];foreach($this->db->all('SELECT provider,state,next_run,attempts FROM jobs WHERE company_id=?',[$id]) as $r)$jobs[$r['provider']]=$r;
            $markets=(new Repository($this->db))->records($id,'channels',$user);
            $catalogJobs=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_jobs','company_id')
                ?array_column($this->db->all('SELECT provider,last_success,error,attempts FROM inventory_catalog_jobs WHERE company_id=?',[$id]),null,'provider'):[];
            foreach($this->db->all("SELECT kind,payload FROM records WHERE company_id=? AND kind LIKE 'catalog_fault_%'",[$id]) as $fault){
                $provider=substr($fault['kind'],strlen('catalog_fault_'));
                if(!isset($catalogJobs[$provider]))continue;
                $catalogJobs[$provider]['error']=json_decode($fault['payload'],true)['error'];
                $catalogJobs[$provider]['attempts']=max(1,(int)$catalogJobs[$provider]['attempts']);
            }
            $pilot=MarketplaceStockPilot::load();
            $updatesEnabled=MarketplaceStockJob::allowed($this->db,$pilot,$id);
            $latestUpdates=$updatesEnabled?$this->latestUpdates($id):[];
            $incidentRow=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$id]);
            $incidents=json_decode($incidentRow['payload']??'{}',true)??[];
            $serviceHealth=new MarketplaceServiceHealth($this->db);
            $clock=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul'));
            $sales=$selectedCompany===null?[]:array_column((new MarketplaceSalesSummary($this->db))->dashboard($id,$markets,$clock->format('Y-m'),$clock)['channels'],null,'channel');
            foreach($markets as $market) {
                if(!MarketplaceVisibility::visible($market['name'])||($market['name']==='Trendyol'&&empty($market['selected'])))continue;
                $key=match($market['name']){'Koçtaş'=>'koctas','Pazarama'=>'pazarama','n11'=>'n11','Hepsiburada'=>'hepsiburada','PTT AVM'=>'pttavm','idefix'=>'idefix','Trendyol'=>'trendyol',default=>''};
                $s=$state['sync_'.$key]??[];$j=$jobs[$market['name']]??null;$connected=isset($configured['integration_'.$key]);
                $last=in_array($key,['koctas','n11','pazarama','idefix','hepsiburada','pttavm','trendyol'],true)?($s['lastSuccessAt']??$s['watermark']??$s['checkedAt']??null):($s['checkedAt']??null);
                $status=!$connected?'not_configured':match($j['state']??'missing'){'failed','blocked'=>'error','running'=>'running',default=>!$j?'missing_queue':(!$last?'waiting':((in_array($key,['pttavm','hepsiburada','idefix','trendyol'],true)&&empty($s['ordersPublished']))||(in_array($key,['n11','pazarama'],true)&&empty($s['salesPublished']))?'probe':(time()-strtotime($last)>(SyncFreshness::fast($key)?SyncFreshness::TARGET_SECONDS:3600)?'stale':'ready')))};
                if((($key==='koctas'&&isset($s['scanEnd']))||($key==='trendyol'&&($s['phase']??'')==='syncing')||(in_array($key,['n11','pazarama','idefix'],true)&&isset($s['scan'])))&&!in_array($status,['error','missing_queue'],true))$status='running';
                if($connected&&($j['state']??null)==='pending'&&(int)$j['attempts']>0)$status='retry';
                if($key==='hepsiburada'&&($s['phase']??'')==='syncing'&&!in_array($status,['error','missing_queue','retry'],true))$status='running';
                $message=match($status){'not_configured'=>'Otomatik bağlantı kurulmadı.','error'=>'Aktarım durdu. Bağlantı bilgilerini ve erişim yetkisini kontrol edin.','missing_queue'=>'Bağlantı kayıtlı ancak zamanlanmış aktarım işi bulunamadı.','waiting'=>'İlk başarılı kontrol bekleniyor.','running'=>'İlk doğrulanmış aktarım tamamlanıyor.','stale'=>SyncFreshness::fast($key)?'Son başarılı aktarım iki dakikadan eski; güncel veri bekleniyor.':'Son başarılı aktarım bir saatten eski.','probe'=>'Okuma kontrolü mevcut. Satış aktarımı henüz yayımlanmıyor.',default=>'Son sipariş aktarımı başarılı.'};
                if($key==='hepsiburada'&&$status==='ready'){
                    $message=!empty($s['salesPublished'])?'Son sipariş, satış, iptal ve gerçekleşmiş iade aktarımı başarılı.':'Son sipariş aktarımı başarılı. İade ve aylık satış mutabakatı henüz doğrulanmadı.';
                    if(!empty($s['financial']['error'])||(!empty($s['financial']['lastSuccessAt'])&&time()-strtotime($s['financial']['lastSuccessAt'])>180))$message='Son sipariş aktarımı başarılı. Satış/iade özeti için yeni doğrulama bekleniyor; son başarılı tutarlar korunuyor.';
                }
                if(in_array($key,['idefix','pttavm','trendyol'],true)&&$status==='ready')$message='Son sipariş aktarımı başarılı. Satış özeti son doğrulanmış siparişlerden hesaplandı.';
                if($status==='retry')$message='Son deneme tamamlanamadı. Kuyruk gecikmeli olarak yeniden deneyecek.';
                $isolated=(new OrderImportQueue($this->db))->count($id,$key);
                if($connected&&($isolated||!empty($s['financialSnapshotHeld']))&&!in_array($status,['error','missing_queue'],true)){
                    $status='retry';$message=$isolated.' siparişin kaynak kontrolü bekleniyor. Diğer siparişler aktarılıyor; çelişkili tutarlar yerine son doğrulanmış satış özeti korunuyor.';
                }
                // idefix summaries use the completed order snapshot, not a separate sales record.
                $salesPublished=in_array($key,['koctas','n11','pazarama'],true)&&!empty($s['watermark']);
                if(in_array($key,['idefix','pttavm','trendyol'],true))$salesPublished=!empty($s['ordersPublished'])&&!empty($s['watermark'])&&!empty($s['startDate']);
                if($key==='hepsiburada')$salesPublished=!empty($s['salesPublished'])&&!empty($s['financial']['lastSuccessAt']);
                $active=$market['status']==='active';
                $incident=$incidents[$key]??[];
                $waiting=!empty($incident['open'])?($incident['waitingFor']??[]):[];
                $services=$serviceHealth->channel($id,$key,$s,$jobs,$connected,$active);
                if($connected&&$active){
                    foreach(['questions'=>($key==='hepsiburada'?HepsiburadaQuestionClient::JOB:($key==='trendyol'?TrendyolQuestionClient::JOB:$market['name'].' Sorular')),
                             'requests'=>($key==='trendyol'?TrendyolClaimAdapter::JOB:null),
                             'payouts'=>UpcomingPayoutAdapter::JOBS[$key]??null] as $scope=>$job){
                        if($job===null||!array_key_exists($job,$waiting)||!isset($services[$scope]))continue;
                        if($services[$scope]['status']!=='ready'||!IntegrationHealthSnapshot::successAfterIncident($job,$services[$scope]['lastSuccessAt'],$incident)){
                            $services[$scope]['status']='error';
                        }
                    }
                    // Order, question and finance workers can recover independently. Reconciliation
                    // and the overall incident keep their separate evidence and notification rules.
                    $orderJob=$market['name'];
                    $orderSuccess=$s['lastSuccessAt']??$s['watermark']??null;
                    if($status==='running'&&$last&&$salesPublished&&(int)($j['attempts']??0)===0&&SyncFreshness::age($last,time())<=(SyncFreshness::fast($key)?SyncFreshness::TARGET_SECONDS:3600)){
                        $status='ready';
                        $message='Son sipariş aktarımı başarılı.';
                    }
                    if(array_key_exists($orderJob,$waiting)&&($status!=='ready'||!IntegrationHealthSnapshot::successAfterIncident($orderJob,$orderSuccess,$incident))){
                        if($status!=='error')$message='Son aktarım sorununun düzeldiği henüz doğrulanmadı.';
                        $status='error';
                    }
                }
                $period=$sales[$market['name']]['periods']['month']??null;
                $items[]=['companyId'=>$id,'companyName'=>$company['name'],'channel'=>$market['name'],'storeName'=>$market['storeName']??'','active'=>$active,'status'=>$status,'message'=>$message,'lastSuccessAt'=>$last,'coverageFrom'=>in_array($key,['koctas','n11','pazarama','idefix','hepsiburada','pttavm','trendyol'],true)?($s['startDate']??null):null,'nextRunAt'=>($j['state']??null)==='pending'?gmdate(DATE_ATOM,(int)$j['next_run']):null,'queueState'=>$j['state']??null,'attempts'=>(int)($j['attempts']??0),'salesPublished'=>$salesPublished,'reconciliation'=>ReconciliationPresentation::summary($s),
                    'catalogRead'=>array_intersect_key($catalogJobs[$key]??[],array_flip(['last_success','error','attempts'])),
                    'updateResult'=>$latestUpdates[$key]??null,'updatesEnabled'=>$updatesEnabled&&($key!=='pazarama'||MarketplaceStockJob::pazaramaEnabled($this->db,$pilot,$id)),'services'=>$services,'salesTurnoverCents'=>!empty($period['available'])?$period['salesTurnoverCents']:null];
            }
        }
        return ['items'=>$items,'page'=>$page,'hasMore'=>$hasMore,'checkedAt'=>gmdate(DATE_ATOM),...($selectedCompany===null?[]:['company'=>['id'=>$selectedCompany,'name'=>$companies[0]['name']]])];
    }
}
