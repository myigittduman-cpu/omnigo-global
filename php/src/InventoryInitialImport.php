<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only first-import presentation; recurring jobs keep their existing checkpoints. */
final class InventoryInitialImport
{
    public function __construct(private Database $db) {}

    public function status(int $company): array
    {
        $rows=$this->db->all('SELECT j.provider,j.last_success,j.next_run,p.state,p.pages_done,p.total_pages,p.started_at FROM inventory_catalog_jobs j LEFT JOIN inventory_catalog_progress p ON p.company_id=j.company_id AND p.provider=j.provider WHERE j.company_id=? ORDER BY j.provider',[$company]);
        $done=0;$fractions=0.0;$known=true;$eta=0;$states=[];
        foreach($rows as $row){
            // A successful first import remains complete across subsequent scans/retries.
            if(!empty($row['last_success'])){$done++;$fractions++;continue;}
            $state=$row['state']??'queued';$states[]=$state;
            $pages=(int)($row['pages_done']??0);$expected=(int)($row['total_pages']??0);
            // PTT has multiple partitions; its current partition total is not a job total.
            $measurable=$row['provider']!=='pttavm'&&$pages>=2&&$expected>$pages&&(int)$row['started_at']>0&&$state==='running';
            if(!$measurable){$known=false;continue;}
            $fractions+=min(.99,$pages/$expected);
            $eta+=(int)ceil(max(0,time()-(int)$row['started_at'])/$pages*($expected-$pages))+max(0,(int)$row['next_run']-time());
        }
        $count=count($rows);$complete=$count>0&&$done===$count;
        $state=$complete?'complete':(in_array('retry',$states,true)?'retry':(in_array('incomplete',$states,true)?'incomplete':(in_array('running',$states,true)?'running':'queued')));
        return ['state'=>$state,'complete'=>$complete,'percent'=>$complete?100:($known&&$count?min(99,(int)floor($fractions/$count*100)):null),'estimatedSeconds'=>$complete?0:($known&&$count?$eta:null),'completedProviders'=>$done,'providerCount'=>$count];
    }
}
