<?php
declare(strict_types=1);
namespace Omnigo;

/** Additive full-pass evidence only; never advances a source cursor or changes business data. */
final class IntegrationImportEvidence
{
    public static function completed(array &$state,array $config,bool $complete):void
    {
        if($complete&&!empty($config['revision']))$state['importCompletion']=['revision'=>$config['revision'],'completedAt'=>gmdate('c')];
    }
    public static function orders(array $run,array $state,int $syncVersion,int $isolated):bool
    {
        $baseline=json_decode($run['baseline'],true,32,JSON_THROW_ON_ERROR);$marker=$state['importCompletion']??[];
        if($syncVersion<=(int)$baseline['syncVersion']||$isolated>0||!empty($state['financialSnapshotHeld'])||!empty($state['failure']))return false;
        if(($marker['revision']??'')!==$run['credential_revision']||(strtotime($marker['completedAt']??'')?:0)<(strtotime($run['started_at'])?:PHP_INT_MAX))return false;
        // Hepsiburada retains feed checkpoints across saves; every feed must finish anew.
        if($run['provider']==='hepsiburada'){
            $feeds=$state['feedCompletedAt']??[];
            foreach(HepsiburadaOrderDiscovery::FEEDS as $feed)if((strtotime($feeds[$feed]??'')?:0)<(strtotime($run['started_at'])?:PHP_INT_MAX)
                ||($feeds[$feed]??null)===($baseline['feedCompletedAt'][$feed]??null))return false;
            if(!empty($state['pendingOrders']))return false;
        }
        return true;
    }
    public static function catalog(array $run,?array $progress):bool
    {
        $baseline=json_decode($run['baseline'],true,32,JSON_THROW_ON_ERROR);$start=strtotime($run['started_at'])?:PHP_INT_MAX;$marker=$baseline['catalogCompletion']??[];
        if(($marker['revision']??'')!==$run['credential_revision']||(int)($marker['startedAt']??0)!==(int)($progress['started_at']??0))return false;
        return $progress&&$progress['state']==='complete'&&(int)$progress['started_at']>=(int)$start
            &&(int)$progress['started_at']>(int)$baseline['inventoryStartedAt']&&(int)$progress['completed_at']>=(int)$start
            &&(int)$progress['completed_at']>(int)$baseline['inventoryCompletedAt']&&(int)($progress['unknown_quantities']??0)===0;
    }
    /** Observes only committed catalogue pages and their actual credential revision. */
    public static function catalogPage(Database $db,int $company,array $job,array $page,string $revision):void
    {
        if($revision===''||!IntegrationImportSchema::available($db))return;
        $db->transaction(function()use($db,$company,$job,$revision){
            $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]);
            $run=$db->one("SELECT * FROM integration_import_runs WHERE company_id=? AND provider=? AND state NOT IN ('superseded','complete')".$db->lock(),[$company,$job['provider']]);
            if(!$run||$run['credential_revision']!==$revision)return;
            $credential=$db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$job['provider']]);
            if((int)($credential['version']??0)!==(int)$run['credential_version'])return;
            $progress=$db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$job['provider']]);
            $baseline=json_decode($run['baseline'],true,32,JSON_THROW_ON_ERROR);$started=(int)($progress['started_at']??0);
            if(!$baseline['inventoryEnabled']||$started<(strtotime($run['started_at'])?:PHP_INT_MAX)||$started<=(int)$baseline['inventoryStartedAt'])return;
            // A save during an old pass must wait for page zero of a new revision-owned pass.
            if((int)$job['page_no']===0&&(int)($job['partition_no']??0)===0)$baseline['catalogPass']=['revision'=>$revision,'startedAt'=>$started];
            $pass=$baseline['catalogPass']??[];
            if(($pass['revision']??'')!==$revision||(int)($pass['startedAt']??0)!==$started)return;
            if(($progress['state']??'')==='complete'&&(int)($progress['unknown_quantities']??0)===0)$baseline['catalogCompletion']=['revision'=>$revision,'startedAt'=>$started,'completedAt'=>(int)$progress['completed_at']];
            $db->run('UPDATE integration_import_runs SET baseline=? WHERE company_id=? AND run_id=?',[json_encode($baseline,JSON_THROW_ON_ERROR),$company,$run['run_id']]);
        });
    }
}
