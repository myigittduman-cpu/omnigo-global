<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable catalogue progress shared by every device, with exclusive expiring leases. */
final class InventoryProgress
{
    public function __construct(private Database $db){}
    public function ensure(int $company,string $provider):void
    {
        if(!$this->db->one('SELECT provider FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider]))
            $this->db->run('INSERT INTO inventory_catalog_jobs(company_id,provider) VALUES(?,?)',[$company,$provider]);
        if(!$this->db->one('SELECT provider FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]))
            $this->db->run('INSERT INTO inventory_catalog_progress(company_id,provider) VALUES(?,?)',[$company,$provider]);
    }
    public function claim(int $company,string $provider):?array
    {
        return $this->db->transaction(function()use($company,$provider){
            $run=$this->db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,$provider]);
            $job=$this->db->one('SELECT * FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider]);
            if(!$run||!$job||(int)$run['lease_until']>time()||(int)$job['next_run']>time())return null;
            if(in_array($run['state'],['complete','incomplete'],true)){
                $this->db->run('UPDATE inventory_catalog_progress SET previous_pages=pages_done,pages_done=0,items_done=0,unknown_quantities=0,total_pages=NULL,completed_at=NULL WHERE company_id=? AND provider=?',[$company,$provider]);
                $run['started_at']=0;
            }
            $token=bin2hex(random_bytes(20));
            $this->db->run("UPDATE inventory_catalog_progress SET state='running',started_at=?,heartbeat=?,lease_until=?,lease_token=? WHERE company_id=? AND provider=?",[(int)$run['started_at']?:time(),time(),time()+90,$token,$company,$provider]);
            return [...$job,'token'=>$token];
        });
    }
    /** Records and their checkpoint commit together; interrupted pages are safe to retry. */
    public function commit(int $company,array $job,array $page,?\Closure $checkpoint=null):void
    {
        $this->db->transaction(function()use($company,$job,$page,$checkpoint){
            $provider=$job['provider'];
            $run=$this->db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,$provider]);
            if(!$run||!hash_equals((string)$run['lease_token'],$job['token'])||(int)$run['lease_until']<=time())throw new \DomainException('Aktarım görevi başka işlem tarafından devralındı.');
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $sourceIds=$page['sourceIds']??array_column($page['items'],'id');
            $fingerprint=hash('sha256',json_encode($sourceIds,JSON_THROW_ON_ERROR));
            if((int)$job['page_no']===0)$this->db->run('DELETE FROM inventory_catalog_pages WHERE company_id=? AND provider=? AND partition_no=?',[$company,$provider,$job['partition_no']]);
            if($sourceIds&&$this->db->one('SELECT page_no FROM inventory_catalog_pages WHERE company_id=? AND provider=? AND partition_no=? AND fingerprint=? AND page_no<?',[$company,$provider,$job['partition_no'],$fingerprint,$job['page_no']]))throw new \DomainException('Katalog servisi aynı sayfayı tekrar döndürdü; eksiksizlik doğrulanamadı.');
            $catalog=new InventoryCatalog($this->db);$unknown=0;
            foreach($page['items'] as $item){$catalog->ingest($company,InventoryCatalogPage::LABELS[$provider],$item);if(($item['quantity']??null)===null)$unknown++;}
            $this->db->run('DELETE FROM inventory_catalog_pages WHERE company_id=? AND provider=? AND partition_no=? AND page_no=?',[$company,$provider,$job['partition_no'],$job['page_no']]);
            $this->db->run('INSERT INTO inventory_catalog_pages(company_id,provider,partition_no,page_no,fingerprint) VALUES(?,?,?,?,?)',[$company,$provider,$job['partition_no'],$job['page_no'],$fingerprint]);
            $partition=(int)$job['partition_no'];$next=$page['next'];$pageNo=$next?(int)$job['page_no']+1:0;
            if($checkpoint)[$pageNo,$partition,$next]=$checkpoint($pageNo,$partition,$next);
            if(!$next&&$provider==='pttavm'&&$partition<3){$partition++;$next=true;}elseif(!$next)$partition=0;
            if($pageNo>10000)throw new \DomainException('Katalog sayfalaması tamamlanamadı.');
            $incomplete=(int)$run['unknown_quantities']+$unknown>0;$state=$next?'running':($incomplete?'incomplete':'complete');
            $error=!$next&&$incomplete?'Bazı ürünlerin stok miktarı kaynakta eksik; yeniden kontrol edilecek.':null;
            $finished=time();
            if($state==='complete')self::recordVerifiedPass($this->db,$company,$provider,(int)$run['started_at'],$finished);
            $this->db->run('UPDATE inventory_catalog_jobs SET page_no=?,partition_no=?,next_run=?,attempts=0,error=?,last_success=? WHERE company_id=? AND provider=?',[$pageNo,$partition,time()+($next?10:300),$error,$next||$incomplete?$job['last_success']:gmdate('c',$finished),$company,$provider]);
            $this->db->run('UPDATE inventory_catalog_progress SET state=?,completed_at=?,heartbeat=?,pages_done=pages_done+1,items_done=items_done+?,total_pages=COALESCE(?,total_pages),unknown_quantities=unknown_quantities+?,lease_until=0,lease_token=NULL WHERE company_id=? AND provider=?',[$state,$next?null:time(),time(),count($page['items']),$page['totalPages']??null,$unknown,$company,$provider]);
        });
    }
    /** Called within the leased page transaction, only after the complete catalogue is reconciled. */
    public static function recordVerifiedPass(Database $db,int $company,string $provider,int $start,int $finish):void
    {
        $db->run("DELETE FROM records WHERE company_id=? AND kind=?",[$company,'catalog_fault_'.$provider]);
        if(!IntegrationImportSchema::tableAvailable($db,'inventory_catalog_verified_passes','company_id'))return;
        $db->run('DELETE FROM inventory_catalog_verified_passes WHERE company_id=? AND provider=?',[$company,$provider]);
        $db->run('INSERT INTO inventory_catalog_verified_passes(company_id,provider,started_at,completed_at) VALUES(?,?,?,?)',[$company,$provider,$start,$finish]);
    }
    public function fail(int $company,array $job,\Throwable $error):void
    {
        $attempt=min(12,(int)$job['attempts']+1);$wait=min(3600,30*(2**min(7,$attempt)));
        $status=$error instanceof SyncFailure?$error->httpStatus:0;
        if($error instanceof SyncFailure)$wait=max($wait,min(3600,(int)$error->retryAfter));
        $message=$status?'Ürün sorgusu yanıtı: '.$status:($error instanceof \DomainException?$error->getMessage():'Katalog kontrolü yeniden denenecek.');
        $this->db->transaction(function()use($company,$job,$attempt,$wait,$message){
            $run=$this->db->one('SELECT lease_token FROM inventory_catalog_progress WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,$job['provider']]);
            if(!$run||!hash_equals((string)$run['lease_token'],$job['token']))return;
            $kind='catalog_fault_'.$job['provider'];
            $this->db->run('DELETE FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
            $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode(['error'=>$message],JSON_THROW_ON_ERROR),gmdate('c')]);
            $this->db->run('UPDATE inventory_catalog_jobs SET attempts=?,next_run=?,error=? WHERE company_id=? AND provider=?',[$attempt,time()+$wait,mb_substr($message,0,200),$company,$job['provider']]);
            $this->db->run("UPDATE inventory_catalog_progress SET state='retry',heartbeat=?,lease_until=0,lease_token=NULL WHERE company_id=? AND provider=?",[time(),$company,$job['provider']]);
        });
    }
    public function status(int $company):array
    {
        $rows=$this->db->all('SELECT p.*,j.next_run,j.error,j.last_success FROM inventory_catalog_progress p JOIN inventory_catalog_jobs j ON j.company_id=p.company_id AND j.provider=p.provider WHERE p.company_id=? ORDER BY p.provider',[$company]);
        $items=[];$done=0;$known=true;$total=0;$pages=0;$eta=0;
        foreach($rows as $row){
            $complete=$row['state']==='complete';$count=(int)$row['pages_done'];$expected=$complete?$count:($row['total_pages']??$row['previous_pages']);$remaining=null;
            if(!$complete&&$count>=2&&$expected!==null&&(int)$expected>$count&&(int)$row['started_at']>0)
                $remaining=(int)ceil((time()-(int)$row['started_at'])/$count*((int)$expected-$count))+max(0,(int)$row['next_run']-time());
            if(!$complete&&($remaining===null||in_array($row['state'],['retry','incomplete'],true)))$known=false;
            $eta+=$remaining??0;$done+=(int)$complete;$total+=(int)($expected??0);$pages+=$count;
            $items[]=['provider'=>InventoryCatalogPage::LABELS[$row['provider']]??$row['provider'],'state'=>$row['state'],'pages'=>$count,'products'=>(int)$row['items_done'],'unknownQuantities'=>(int)$row['unknown_quantities'],'lastSuccess'=>$row['last_success'],'error'=>$row['error'],'retryAt'=>$row['state']==='retry'?gmdate('c',(int)$row['next_run']):null,'estimatedSeconds'=>$remaining];
        }
        $complete=count($rows)>0&&$done===count($rows);$states=array_column($items,'state');
        $state=$complete?'complete':(in_array('retry',$states,true)?'retry':(in_array('incomplete',$states,true)?'incomplete':(in_array('running',$states,true)?'running':'queued')));
        return ['state'=>$state,'percent'=>$complete?100:($known&&$total>0?min(99,(int)floor($pages/$total*100)):null),'estimatedSeconds'=>$complete?0:($known?$eta:null),'providers'=>$items,'completedProviders'=>$done,'providerCount'=>count($rows)];
    }
}
