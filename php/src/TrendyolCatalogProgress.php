<?php
declare(strict_types=1);
namespace Omnigo;

/** V2 pagination checkpoints commit with listings; other catalogue providers are unaffected. */
final class TrendyolCatalogProgress
{
    public function __construct(private Database $db,private IntegrationVault $vault){}

    public function prepare(int $company,array $job,string $revision):array
    {
        $record=$this->checkpoint($company);$state=$record?json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if((int)$job['page_no']===0&&(int)$job['partition_no']===0)return ['job'=>$job,'cursor'=>null];
        if(($state['revision']??'')!==$revision||(int)($state['page']??-1)!==(int)$job['page_no']||(int)($state['partition']??-1)!==(int)$job['partition_no']){
            $this->restart($company,$job);
            return ['restart'=>true];
        }
        return ['job'=>$job,'cursor'=>$state['cursor']??null];
    }

    /** Returns false only when a changed source catalogue requires a fresh, complete pass. */
    public function commit(int $company,array $job,array $page,string $revision):bool
    {
        return TransactionRetry::run($this->db,function()use($company,$job,$page,$revision):bool{
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $run=$this->db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider='trendyol'".$this->db->lock(),[$company]);
            if(!$run||!hash_equals((string)$run['lease_token'],(string)$job['token'])||(int)$run['lease_until']<=time())throw new SyncFailure(409);
            $access=$this->vault->get($company,'trendyol');
            if(empty($access['enabled'])||($access['revision']??'')!==$revision)throw new SyncFailure(409);
            $partition=(int)$job['partition_no'];$pageNo=(int)$job['page_no'];$record=$this->checkpoint($company);
            $state=$record?json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR):[];
            if($partition===0&&$pageNo===0)$state=['revision'=>$revision,'partition'=>0,'page'=>0,'cursor'=>null,'sourceSeen'=>0,'totals'=>[],'lastVerifiedVersion'=>$state['lastVerifiedVersion']??null];
            if(($state['revision']??'')!==$revision||(int)($state['partition']??-1)!==$partition||(int)($state['page']??-1)!==$pageNo)throw new SyncFailure(409);
            $total=(int)$page['sourceTotal'];
            if($pageNo>0&&(int)($state['totals'][$partition]??-1)!==$total){$this->restartLocked($company,$job,$run);return false;}
            $state['totals'][$partition]=$total;
            $seen=(int)($state['sourceSeen']??0)+(int)$page['sourceCount'];
            if(!$page['next']&&$seen!==$total){$this->restartLocked($company,$job,$run);return false;}
            $fingerprint=hash('sha256',json_encode(array_column($page['items'],'id'),JSON_THROW_ON_ERROR));
            if($pageNo===0)$this->db->run("DELETE FROM inventory_catalog_pages WHERE company_id=? AND provider='trendyol' AND partition_no=?",[$company,$partition]);
            if($page['items']&&$this->db->one("SELECT page_no FROM inventory_catalog_pages WHERE company_id=? AND provider='trendyol' AND partition_no=? AND fingerprint=? AND page_no<?",[$company,$partition,$fingerprint,$pageNo]))throw new SyncFailure(409);
            $catalog=new InventoryCatalog($this->db);$unknown=0;
            foreach($page['items'] as $item){$catalog->ingest($company,'Trendyol',$item);if($item['quantity']===null)$unknown++;}
            $this->db->run("DELETE FROM inventory_catalog_pages WHERE company_id=? AND provider='trendyol' AND partition_no=? AND page_no=?",[$company,$partition,$pageNo]);
            $this->db->run("INSERT INTO inventory_catalog_pages(company_id,provider,partition_no,page_no,fingerprint) VALUES(?,'trendyol',?,?,?)",[$company,$partition,$pageNo,$fingerprint]);
            $more=$page['next']||$partition===0;$nextPartition=$partition;$nextPage=$pageNo+1;$nextSeen=$seen;
            if(!$page['next']){$nextPartition=$partition===0?1:0;$nextPage=0;$nextSeen=0;}
            if($nextPage>10000)throw new SyncFailure(422);
            $unknownTotal=(int)$run['unknown_quantities']+$unknown;$itemsTotal=(int)$run['items_done']+count($page['items']);
            $stateName=$more?'running':($unknownTotal?'incomplete':'complete');
            if(!$more){
                $observed=gmdate('Y-m-d\TH:i:s\Z',(int)$run['started_at']);
                $distinct=(int)$this->db->one("SELECT COUNT(*) n FROM inventory_listings WHERE company_id=? AND provider='Trendyol' AND remote_id NOT LIKE 'order-sku:%' AND observed_at>=?",[$company,$observed])['n'];
                if($distinct!==$itemsTotal){$this->restartLocked($company,$job,$run);return false;}
            }
            $error=$stateName==='incomplete'?'Bazı Trendyol ürünlerinin stok miktarı eksik; yeniden kontrol edilecek.':null;
            $finished=time();
            $lastSuccess=$job['last_success'];
            if($stateName==='complete'){
                InventoryProgress::recordVerifiedPass($this->db,$company,'trendyol',(int)$run['started_at'],$finished);
                $credential=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_trendyol'",[$company]);
                $state['lastVerifiedVersion']=(int)$credential['version'];
                $lastSuccess=gmdate('c',$finished);
            }
            $this->db->run("UPDATE inventory_catalog_jobs SET page_no=?,partition_no=?,next_run=?,attempts=0,error=?,last_success=? WHERE company_id=? AND provider='trendyol'",[$nextPage,$nextPartition,time()+($more?10:300),$error,$lastSuccess,$company]);
            $expectedPages=isset($state['totals'][1])?(int)ceil($state['totals'][0]/100)+(int)ceil($state['totals'][1]/100)+((int)$state['totals'][0]===0?1:0)+((int)$state['totals'][1]===0?1:0):null;
            $this->db->run("UPDATE inventory_catalog_progress SET state=?,completed_at=?,heartbeat=?,pages_done=pages_done+1,items_done=?,total_pages=?,unknown_quantities=?,lease_until=0,lease_token=NULL WHERE company_id=? AND provider='trendyol'",[$stateName,$more?null:$finished,$finished,$itemsTotal,$expectedPages,$unknownTotal,$company]);
            $state=[...$state,'page'=>$nextPage,'partition'=>$nextPartition,'cursor'=>$page['next']?($page['cursor']??null):null,'sourceSeen'=>$nextSeen];
            $this->saveCheckpoint($company,$record,$state);
            return true;
        });
    }

    private function checkpoint(int $company):?array{return $this->db->one('SELECT payload FROM inventory_trendyol_checkpoints WHERE company_id=?',[$company]);}
    private function saveCheckpoint(int $company,?array $record,array $state):void
    {
        $json=json_encode($state,JSON_THROW_ON_ERROR);$at=gmdate('c');
        if($record)$this->db->run('UPDATE inventory_trendyol_checkpoints SET payload=?,version=version+1,updated=? WHERE company_id=?',[$json,$at,$company]);
        else $this->db->run('INSERT INTO inventory_trendyol_checkpoints(company_id,payload,updated) VALUES(?,?,?)',[$company,$json,$at]);
    }
    private function restart(int $company,array $job):void
    {
        TransactionRetry::run($this->db,function()use($company,$job):void{
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $run=$this->db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider='trendyol'".$this->db->lock(),[$company]);
            if(!$run||!hash_equals((string)$run['lease_token'],(string)$job['token'])||(int)$run['lease_until']<=time())throw new SyncFailure(409);
            $this->restartLocked($company,$job,$run);
        });
    }
    private function restartLocked(int $company,array $job,array $run):void
    {
        $this->db->run("UPDATE inventory_catalog_jobs SET page_no=0,partition_no=0,next_run=?,attempts=0,error=? WHERE company_id=? AND provider='trendyol'",[time()+10,'Ürün listesi değişti; eksiksiz tarama yeniden başlatılıyor.',$company]);
        $this->db->run("UPDATE inventory_catalog_progress SET state='queued',started_at=0,completed_at=NULL,pages_done=0,items_done=0,unknown_quantities=0,total_pages=NULL,lease_until=0,lease_token=NULL WHERE company_id=? AND provider='trendyol'",[$company]);
        $this->db->run('DELETE FROM inventory_trendyol_checkpoints WHERE company_id=?',[$company]);
    }
}
