<?php
declare(strict_types=1);
namespace Omnigo;

/** A credential save owns its run. Old revisions and historical successes cannot finish it. */
final class IntegrationImportRuns
{
    public function __construct(private Database $db){}
    public function begin(int $company,string $provider,int $version,string $revision,?int $now=null):string
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Aktarım kaydı bağlantıyla birlikte kaydedilmeli.');
        if(!isset(IntegrationImportPresentation::PROVIDERS[$provider])||$version<1||$revision==='')throw new \InvalidArgumentException('Aktarım bağlantısı geçersiz.');
        $existing=$this->db->one('SELECT run_id FROM integration_import_runs WHERE company_id=? AND provider=? AND credential_version=?',[$company,$provider,$version]);
        if($existing)return $existing['run_id'];
        $now??=time();$at=gmdate('c',$now);$id=bin2hex(random_bytes(16));
        $sync=$this->db->one('SELECT version,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
        $syncState=$sync?json_decode($sync['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $catalogAvailable=IntegrationImportSchema::tableAvailable($this->db,'inventory_catalog_progress','company_id');
        $tracking=IntegrationImportSchema::tableAvailable($this->db,'inventory_tracking','company_id')&&(new InventorySettings($this->db))->marketplaceEnabled($company);
        // begin() is called by the enabled, revision-bearing vault save callback.
        if(in_array($provider,InventoryCatalogPage::SUPPORTED,true)&&$catalogAvailable&&$tracking)(new InventoryCatalogEnrollment($this->db))->ensure($company,$provider,true);
        $progress=$catalogAvailable?$this->db->one('SELECT started_at,completed_at FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]):null;
        $catalogJob=$catalogAvailable?$this->db->one('SELECT provider FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider]):null;
        $enabled=$tracking&&(bool)$catalogJob;
        $supported=in_array($provider,InventoryCatalogPage::SUPPORTED,true);
        $catalogStatus=!$supported?'unsupported':(!$enabled?'not_enabled':'queued');
        $report=['runId'=>$id,'provider'=>$provider,'providerLabel'=>IntegrationImportPresentation::PROVIDERS[$provider],'state'=>'queued','startedAt'=>$at,'updatedAt'=>$at,'finishedAt'=>null,
            'counts'=>['products'=>null,'stockRecords'=>null,'orders'=>null,'returns'=>null],'countsScope'=>'persisted_snapshot','error'=>null,'nextRetryAt'=>null,
            'stages'=>['connection'=>['status'=>'queued'],'orders'=>['status'=>'queued'],'products'=>['status'=>$catalogStatus],'stock'=>['status'=>$catalogStatus]],'warnings'=>$supported&&!$enabled?['Bu pazaryerinin stok aktarımı henüz etkinleştirilmedi. Sipariş aktarımı bağımsız devam eder.']:[]];
        $baseline=['syncVersion'=>(int)($sync['version']??0),'feedCompletedAt'=>$syncState['feedCompletedAt']??[],'inventoryStartedAt'=>(int)($progress['started_at']??0),'inventoryCompletedAt'=>(int)($progress['completed_at']??0),'inventoryEnabled'=>$enabled,'catalogSupported'=>$supported];
        $this->db->run("UPDATE integration_import_runs SET state='superseded',updated_at=? WHERE company_id=? AND provider=? AND state<>'superseded'",[$at,$company,$provider]);
        $this->db->run('INSERT INTO integration_import_runs(run_id,company_id,provider,credential_version,credential_revision,state,started_at,updated_at,baseline,report) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$company,$provider,$version,$revision,'queued',$at,$at,json_encode($baseline,JSON_THROW_ON_ERROR),json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $detail=['audience'=>'admin','events'=>['marketplace_import_started'],'section'=>'inventory','importReport'=>$report];
        $this->db->run('INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)',[$company,$provider,hash('sha256','integration-import:'.$id.':started'),'operation',json_encode($detail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$at]);
        PushRoutes::devices($this->db)->enqueue((int)$this->db->pdo->lastInsertId());
        return $id;
    }
    public function listing(int $company):array
    {
        $rows=IntegrationImportSchema::available($this->db)?$this->db->all("SELECT provider,baseline,report,state,success_notice_id,failure_notice_id FROM integration_import_runs WHERE company_id=? AND state<>'superseded' ORDER BY started_at DESC,provider",[$company]):[];
        $reports=[];$liveCatalogRuns=[];
        foreach($rows as $row){
            $report=json_decode($row['report'],true,32,JSON_THROW_ON_ERROR);
            $notice=match($row['state']){'complete'=>$row['success_notice_id'],'failed'=>$row['failure_notice_id'],default=>null};
            if($notice)$report['noticeId']=(int)$notice;
            if(in_array($row['provider'],InventoryCatalogPage::SUPPORTED,true)){
                $baseline=json_decode($row['baseline'],true,32,JSON_THROW_ON_ERROR);
                if(empty($baseline['catalogSupported'])||empty($baseline['inventoryEnabled']))$liveCatalogRuns[$report['runId']]=$row['provider'];
            }
            $reports[]=$report;
        }
        $liveProviders=array_values(array_unique(array_values($liveCatalogRuns)));
        $excluded=array_values(array_filter(array_column($reports,'provider'),static fn($provider)=>!in_array($provider,$liveProviders,true)));
        $snapshots=(new IntegrationImportSnapshot($this->db))->forCompany($company,$excluded);
        if($liveProviders){
            foreach($snapshots as $index=>$snapshot)if(in_array($snapshot['provider'],$liveProviders,true)){
                foreach($reports as &$report)if(($liveCatalogRuns[$report['runId']]??null)===$snapshot['provider'])$report=self::currentCatalog($report,$snapshot);
                unset($report);unset($snapshots[$index]);
            }
        }
        return [...(new IntegrationImportCatalogSummary($this->db))->attach($company,$reports),...array_values($snapshots)];
    }

    /** Presentation only: historical reports/notices are never reopened or rewritten. */
    private static function currentCatalog(array $report,array $snapshot):array
    {
        foreach(['products','stock'] as $stage)$report['stages'][$stage]=$snapshot['stages'][$stage];
        foreach(['products','stockRecords'] as $count)$report['counts'][$count]=$snapshot['counts'][$count];
        $status=$snapshot['stages']['stock']['status'];
        if(!in_array($status,['complete','not_enabled'],true)){
            $report['state']=in_array($snapshot['state'],['failed','retry','running','queued'],true)?$snapshot['state']:'running';
            $report['finishedAt']=null;$report['error']=$snapshot['error'];$report['nextRetryAt']=$snapshot['nextRetryAt'];
        }
        $report['catalogUpdatedAt']=$snapshot['updatedAt'];
        $oldWarnings=$report['warnings']??[];
        if($status!=='not_enabled')$oldWarnings=array_values(array_filter($oldWarnings,static fn($warning)=>$warning!=='Bu pazaryerinin stok aktarımı henüz etkinleştirilmedi. Sipariş aktarımı bağımsız devam eder.'));
        $report['warnings']=array_values(array_unique([...$oldWarnings,...($snapshot['warnings']??[])]));
        return $report;
    }
}
