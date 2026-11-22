<?php
declare(strict_types=1);
namespace Omnigo;

/** Observes existing workers locally. No marketplace requests, new schedules or cursor writes. */
final class IntegrationImportReconciler
{
    public function __construct(private Database $db,private ?\Closure $notify=null){}
    public function check(int $company,?string $provider=null,?int $now=null):void
    {
        if(!IntegrationImportSchema::available($this->db))return;$now??=time();
        $rows=$this->db->all("SELECT run_id FROM integration_import_runs WHERE company_id=? AND state NOT IN ('superseded','complete')".($provider===null?'':' AND provider=?'),$provider===null?[$company]:[$company,$provider]);
        foreach($rows as $row)$this->db->transaction(fn()=>$this->observe($company,$row['run_id'],$now));
    }
    private function observe(int $company,string $id,int $now):void
    {
        // Same lock order as credential saves and source commits prevents cross-revision races.
        $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
        $run=$this->db->one('SELECT * FROM integration_import_runs WHERE company_id=? AND run_id=?'.$this->db->lock(),[$company,$id]);
        if(!$run||in_array($run['state'],['complete','superseded'],true))return;
        $provider=$run['provider'];$credential=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        if((int)($credential['version']??0)!==(int)$run['credential_version']){$this->db->run("UPDATE integration_import_runs SET state='superseded',updated_at=? WHERE run_id=? AND company_id=?",[gmdate('c',$now),$id,$company]);return;}
        $record=$this->db->one('SELECT payload,version FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);$sync=$record?json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $job=$this->db->one('SELECT state,error,next_run,attempts FROM jobs WHERE company_id=? AND provider=?',[$company,IntegrationImportPresentation::PROVIDERS[$provider]]);
        $report=json_decode($run['report'],true,32,JSON_THROW_ON_ERROR);$baseline=json_decode($run['baseline'],true,32,JSON_THROW_ON_ERROR);
        $isolated=(new OrderImportQueue($this->db))->count($company,$provider);
        $orders=$report['stages']['orders']['status']==='complete'||IntegrationImportEvidence::orders($run,$sync,(int)($record['version']??0),$isolated);
        $catalogDone=!$baseline['inventoryEnabled']||!$baseline['catalogSupported'];$catalog=null;$catalogJob=null;
        if(!$catalogDone){$catalog=$this->db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]);$catalogJob=$this->db->one('SELECT error,next_run,attempts FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider]);$catalogDone=$report['stages']['stock']['status']==='complete'||IntegrationImportEvidence::catalog($run,$catalog);}
        $orderFailed=!$orders && in_array($job['state']??'',['blocked','failed'],true);
        $orderRetry=!$orders && (int)($job['attempts']??0)>0 && !empty($job['error']);
        $catalogFailed=!$catalogDone && (preg_match('/\b(?:401|403)\b/',(string)($catalogJob['error']??'')) || (int)($catalogJob['attempts']??0)>=12);
        $catalogRetry=!$catalogDone && in_array($catalog['state']??'',['retry','incomplete'],true);
        $orderScheduled=$orderFailed && (($job['state']??'')==='failed' || ($job['error']??'')==='Pazaryeri yetkisi doğrulanamadı.') && (int)($job['next_run']??0)>0;
        $catalogScheduled=$catalogFailed && (int)($catalogJob['next_run']??0)>0;
        $orderRetry=$orderRetry||$orderScheduled;
        $catalogRetry=$catalogRetry||$catalogScheduled;
        $orderFailed=$orderFailed&&!$orderScheduled;
        $catalogFailed=$catalogFailed&&!$catalogScheduled;
        $failed=($orders||$orderFailed)&&($catalogDone||$catalogFailed)&&($orderFailed||$catalogFailed);
        $retry=!$failed&&($orderRetry||$catalogRetry);
        $advanced=$record&&(int)$record['version']>(int)$baseline['syncVersion'];
        $pending=$advanced?'running':'queued';
        $complete=$orders&&$catalogDone;
        $state=$complete?'complete':($failed?'failed':($retry?'retry':$pending));
        $report['stages']['connection']['status']=$orderFailed?'failed':($orders||($advanced&&empty($job['error']))?'complete':($orderRetry?'retry':$pending));
        $report['stages']['orders']['status']=$orders?'complete':($orderFailed?'failed':($orderRetry?'retry':$pending));
        if($baseline['inventoryEnabled']&&$baseline['catalogSupported'])foreach(['products','stock'] as $stage)$report['stages'][$stage]['status']=$catalogDone?'complete':($catalogFailed?'failed':($catalogRetry?'retry':(($catalog['state']??'')==='running'?'running':'queued')));
        $report['state']=$state;$report['updatedAt']=gmdate('c',$now);$report['finishedAt']=in_array($state,['complete','failed'],true)?($run['state']===$state?$run['finished_at']:gmdate('c',$now)):null;
        $report['error']=$failed?(($job['state']??'')==='blocked'?'Pazaryeri API erişimi doğrulanamadı. Bağlantı bilgilerini ve yetkileri kontrol edin.':'Aktarım tamamlanamadı. Kontrollü yeniden deneme sürdürülecek.') : ($retry?'Kaynak verisi henüz tamamlanamadı; otomatik yeniden deneme planlandı.':null);
        if(!$catalogDone && preg_match('/\b(401|403)\b/',(string)($catalogJob['error']??''),$match)){
            $report['error']='Ürün ve stok sorgusu HTTP '.$match[1].' ile reddedildi. Bu mağazanın pazaryeri bağlantı bilgilerini ve API yetkisini kontrol edin.';
        }
        $due=array_filter([$orders?0:(int)($job['next_run']??0),$catalogDone?0:(int)($catalogJob['next_run']??0)],fn($at)=>$at>0);$next=$due?min($due):0;$report['nextRetryAt']=in_array($state,['retry','failed'],true)&&$next>0?gmdate('c',$next):null;
        $counts=(new IntegrationImportCounts($this->db))->snapshot($company,$provider,$sync,$orders,$catalogDone&&$baseline['inventoryEnabled']&&$baseline['catalogSupported'],strtotime($run['started_at'])?:PHP_INT_MAX);
        foreach($counts as $key=>$value)if($value!==null)$report['counts'][$key]=$value;
        $report['warnings']=$baseline['catalogSupported']&&!$baseline['inventoryEnabled']?['Bu pazaryerinin stok aktarımı henüz etkinleştirilmedi. Sipariş aktarımı bağımsız devam eder.']:[];
        if($provider==='hepsiburada'&&$baseline['inventoryEnabled']&&$baseline['catalogSupported']){
            $metadata=InventoryMetadataQueue::status($this->db,$company);
            $report['warnings']=!empty($metadata['pending'])?['Bazı ürün adları veya görselleri arka planda tamamlanmaya devam ediyor.']:[];
        }
        if($isolated>0||!empty($sync['financialSnapshotHeld']))$report['warnings'][]='Bazı sipariş veya mali bilgiler doğrulanmayı bekliyor.';
        $report['warnings']=array_values(array_unique($report['warnings']));
        $this->db->run('UPDATE integration_import_runs SET state=?,report=?,updated_at=?,finished_at=? WHERE run_id=? AND company_id=?',[$state,json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$report['updatedAt'],$report['finishedAt'],$id,$company]);
        if(in_array($state,['complete','failed'],true))$this->publish($run,$report,$state);
    }
    private function publish(array $run,array $report,string $state):void
    {
        $field=$state==='complete'?'success_notice_id':'failure_notice_id';if(!empty($run[$field]))return;
        $event=$state==='complete'?'marketplace_import_completed':'marketplace_import_failed';
        $detail=['audience'=>'admin','events'=>[$event],'section'=>'inventory','importReport'=>$report];$insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $added=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",[$run['company_id'],$run['provider'],hash('sha256','integration-import:'.$run['run_id'].':'.$state),'operation',json_encode($detail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$report['updatedAt']]);
        $notice=$this->db->one('SELECT id FROM order_notifications WHERE company_id=? AND provider=? AND order_key=?',[$run['company_id'],$run['provider'],hash('sha256','integration-import:'.$run['run_id'].':'.$state)]);
        $this->db->run("UPDATE integration_import_runs SET $field=? WHERE run_id=? AND company_id=?",[(int)$notice['id'],$run['run_id'],$run['company_id']]);
        if($added->rowCount()===1){if($this->notify)($this->notify)((int)$notice['id']);else PushRoutes::devices($this->db)->enqueue((int)$notice['id']);}
    }
}
