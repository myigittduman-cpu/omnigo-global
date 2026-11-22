<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only legacy connection projection. Never starts a run or announces historical success. */
final class IntegrationImportSnapshot
{
    public function __construct(private Database $db){}
    public function forCompany(int $company,array $excludeProviders=[]):array
    {
        $providers=IntegrationImportPresentation::PROVIDERS;$kinds=array_map(fn($key)=>'integration_'.$key,array_keys($providers));
        // Deliberately select only public record metadata, never encrypted API envelopes.
        $rows=$this->db->all('SELECT kind,version,updated FROM records WHERE company_id=? AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).') ORDER BY id',[$company,...$kinds]);
        $reports=[];$seen=array_fill_keys($excludeProviders,true);$selected=[];
        foreach($rows as $row){$provider=substr($row['kind'],12);if(isset($seen[$provider]))continue;$seen[$provider]=true;$selected[$provider]=$row;}
        if(!$selected)return [];$context=$this->context($company,array_keys($selected));
        foreach($selected as $provider=>$row)$reports[]=$this->project($provider,$row,$context);
        return $reports;
    }
    /** One bounded company read set, rather than repeating every query for each provider. */
    private function context(int $company,array $providers):array
    {
        $keys=[...array_map(fn($p)=>'sync_'.$p,$providers),...array_map(fn($p)=>'order_'.$p,$providers),...array_map(fn($p)=>'order_retry_'.$p,$providers)];
        $syncKeys=array_map(fn($p)=>'sync_'.$p,$providers);$slots=implode(',',array_fill(0,count($syncKeys),'?'));
        $sync=array_column($this->db->all("SELECT kind,payload,version,updated FROM records WHERE company_id=? AND kind IN ($slots)",[$company,...$syncKeys]),null,'kind');
        $slots=implode(',',array_fill(0,count($keys),'?'));
        $counts=array_column($this->db->all("SELECT kind,COUNT(*) n FROM records WHERE company_id=? AND kind IN ($slots) GROUP BY kind",[$company,...$keys]),'n','kind');
        $jobs=array_column($this->db->all('SELECT provider,state,error,attempts,next_run FROM jobs WHERE company_id=?',[$company]),null,'provider');
        $inventory=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_progress','company_id');
        $catalogJobs=$inventory?array_column($this->db->all('SELECT provider,error,next_run,attempts,last_success FROM inventory_catalog_jobs WHERE company_id=?',[$company]),null,'provider'):[];
        $catalog=$inventory?array_column($this->db->all('SELECT provider,state,started_at,completed_at,unknown_quantities FROM inventory_catalog_progress WHERE company_id=?',[$company]),null,'provider'):[];
        $tracking=IntegrationImportSchema::tableAvailable($this->db,'inventory_tracking','company_id')&&(new InventorySettings($this->db))->marketplaceEnabled($company);
        $listings=$inventory?array_column($this->db->all('SELECT provider,COUNT(DISTINCT product_key) products,SUM(CASE WHEN source_quantity IS NOT NULL THEN 1 ELSE 0 END) stock_records FROM inventory_listings WHERE company_id=? GROUP BY provider',[$company]),null,'provider'):[];
        $recent=RecentOrderSchema::available($this->db)?array_column($this->db->all('SELECT provider,COUNT(*) n FROM recent_orders WHERE company_id=? GROUP BY provider',[$company]),'n','provider'):null;
        $metadata=in_array('hepsiburada',$providers,true)&&$inventory&&IntegrationImportSchema::tableAvailable($this->db,'inventory_metadata_jobs','company_id')?InventoryMetadataQueue::status($this->db,$company):null;
        $catalogSummaries=(new IntegrationImportCatalogSummary($this->db))->forProviders($company,$providers);
        return compact('sync','counts','jobs','inventory','catalogJobs','catalog','tracking','listings','recent','metadata','catalogSummaries');
    }
    private function project(string $provider,array $credential,array $context):array
    {
        $label=IntegrationImportPresentation::PROVIDERS[$provider];$configured=strtotime($credential['updated'])?:PHP_INT_MAX;
        $record=$context['sync']['sync_'.$provider]??null;
        $invalidSync=false;
        try{$sync=$record?json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR):[];if(!is_array($sync)){$sync=[];$invalidSync=true;}}
        catch(\JsonException){$sync=[];$invalidSync=true;}
        $job=$context['jobs'][$label]??null;$catalogJob=$context['catalogJobs'][$provider]??null;$catalog=$context['catalog'][$provider]??null;
        $supported=in_array($provider,InventoryCatalogPage::SUPPORTED,true);$catalogEnabled=$context['tracking']&&(bool)$catalogJob&&$supported;
        $catalogComplete=$catalogEnabled&&($catalog['state']??'')==='complete'&&(int)($catalog['unknown_quantities']??0)===0
            &&(int)($catalog['started_at']??0)>=$configured&&(int)($catalog['completed_at']??0)>=$configured;
        $isolated=(int)($context['counts']['order_retry_'.$provider]??0);
        $orders=$this->ordersComplete($provider,$sync,$record,$configured)&&$isolated===0;
        $failed=in_array($job['state']??'',['blocked','failed'],true)||($catalogEnabled&&(preg_match('/(?:401|403)\b/',(string)($catalogJob['error']??''))||(int)($catalogJob['attempts']??0)>=12));
        $retry=!$failed&&(!empty($job['error'])||(int)($sync['code']??0)>=400||!empty($sync['failure'])||in_array($sync['phase']??'',['error','retry'],true)
            ||($catalogEnabled&&in_array($catalog['state']??'',['retry','incomplete'],true)));
        $complete=$orders&&(!$catalogEnabled||$catalogComplete)&&!$failed&&!$retry;
        $running=($job['state']??'')==='running'||isset($sync['scan'])||isset($sync['scanEnd'])||in_array($sync['phase']??'',['syncing','reading'],true)||($catalogEnabled&&($catalog['state']??'')==='running');
        $state=$failed?'failed':($invalidSync?'unknown':($retry?'retry':($complete?'complete':($running?'running':(($sync['phase']??'')==='pending'?'queued':'unknown')))));
        $stockStatus=!$supported?'unsupported':(!$catalogEnabled?'not_enabled':($catalogComplete?'complete':($failed?'failed':($retry?'retry':(($catalog['state']??'')==='running'?'running':'queued')))));
        $sourceAt=$this->latest([$sync['checkedAt']??null,$sync['lastPageAt']??null,$sync['deliveryLastPageAt']??null,$sync['lastSuccessAt']??null,$sync['historyLastSuccessAt']??null,$sync['recentOrders']['lastPageAt']??null,$catalogJob['last_success']??null,$catalog['completed_at']??null]);
        $lastSuccess=$provider==='pttavm'?($sync['historyLastSuccessAt']??null):($sync['lastSuccessAt']??null);
        $warnings=['Bu alan mevcut bağlantının kayıtlı aktarım durumunu gösterir; yeni bir aktarım başlatmaz.'];
        if($invalidSync)$warnings[]='Bu pazaryerinin kayıtlı aktarım durumu doğrulanamadı; diğer bağlantılar izlenmeye devam ediyor.';
        if($supported&&!$catalogEnabled)$warnings[]='Bu pazaryerinin stok aktarımı henüz etkinleştirilmedi. Sipariş aktarımı bağımsız devam eder.';
        if($isolated||!empty($sync['financialSnapshotHeld']))$warnings[]='Bazı sipariş veya mali bilgiler doğrulanmayı bekliyor.';
        $metadata=null;
        if($provider==='hepsiburada'&&$context['metadata']!==null){
            $metadata=$context['metadata'];
            if(!empty($metadata['pending']))$warnings[]='Bazı ürün adları veya görselleri arka planda tamamlanmaya devam ediyor.';
        }
        $counts=$this->counts($provider,$sync,$configured,$supported,$context);
        $due=array_filter([$orders?0:(int)($job['next_run']??0),$catalogComplete?0:(int)($catalogJob['next_run']??0)],fn($at)=>$at>0);
        return ['kind'=>'live_snapshot','runId'=>'snapshot-'.$provider.'-'.(int)$credential['version'],'provider'=>$provider,'providerLabel'=>$label,'state'=>$state,
            'startedAt'=>null,'updatedAt'=>$sourceAt,'finishedAt'=>null,'lastSuccessAt'=>$orders?$lastSuccess:null,'counts'=>$counts,'countsScope'=>'current_snapshot',
            'error'=>$failed?'Bağlantı veya aktarım kontrol gerektiriyor. Mevcut kayıtlar korunuyor.':($retry?'Kaynak verisi yeniden kontrol ediliyor.':null),
            'nextRetryAt'=>in_array($state,['retry','failed'],true)&&$due?gmdate('c',min($due)):null,
            'currentCatalogSummary'=>$context['catalogSummaries'][$provider],
            'stages'=>['connection'=>['status'=>$failed?'failed':($orders?'complete':($running?'running':'unknown'))],
                'orders'=>['status'=>$orders?'complete':($failed?'failed':($retry?'retry':($running?'running':'unknown')))],
                'products'=>['status'=>$stockStatus],'stock'=>['status'=>$stockStatus]],'metadata'=>$metadata,'warnings'=>$warnings];
    }
    private function counts(string $provider,array $sync,int $configured,bool $supported,array $context):array
    {
        $listing=$context['listings'][IntegrationImportPresentation::PROVIDERS[$provider]]??[];
        $orders=in_array($provider,['hepsiburada','pttavm','trendyol'],true)?($context['recent']===null?null:(int)($context['recent'][$provider]??0)):(int)($context['counts']['order_'.$provider]??0);
        $requests=$sync['returnRequests']??[];$returns=null;
        if(empty($sync['returnRequestError'])&&isset($requests['checkedAt'],$requests['through'])&&(strtotime($requests['checkedAt'])?:0)>=$configured&&is_array($requests['items']??null))$returns=count($requests['items']);
        return ['products'=>$context['inventory']&&$supported?(int)($listing['products']??0):null,'stockRecords'=>$context['inventory']&&$supported?(int)($listing['stock_records']??0):null,'orders'=>$orders,'returns'=>$returns];
    }
    private function ordersComplete(string $provider,array $state,?array $record,int $configured):bool
    {
        if(!$record||(strtotime($record['updated'])?:0)<$configured||isset($state['scan'])||isset($state['scanEnd'])||isset($state['recentOrders']['scan'])
            ||!empty($state['pendingOrders'])||!empty($state['failure'])||!empty($state['financialSnapshotHeld']))return false;
        if($provider==='hepsiburada'){
            if(($state['phase']??'')!=='live')return false;
            foreach(HepsiburadaOrderDiscovery::FEEDS as $feed)if((strtotime($state['feedCompletedAt'][$feed]??'')?:0)<$configured)return false;
        }elseif($provider==='koctas'){
            if((int)($state['offset']??0)!==0||empty($state['detailsVersion']))return false;
        }elseif(($state['phase']??'')!=='ready')return false;
        if($provider==='trendyol'&&(!isset($state['historyNext'],$state['historyEnd'],$state['recentThrough'])||$state['historyNext']<$state['historyEnd']))return false;
        $success=$provider==='pttavm'?($state['historyLastSuccessAt']??null):($state['lastSuccessAt']??null);
        return (strtotime($success??'')?:0)>=$configured;
    }
    private function latest(array $values):?string
    {
        $valid=array_filter(array_map(static fn($value)=>is_int($value)?$value:(is_string($value)?(strtotime($value)?:0):0),$values),fn($at)=>$at>0);
        return $valid?gmdate('c',max($valid)):null;
    }
}
