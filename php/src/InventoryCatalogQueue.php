<?php
declare(strict_types=1);
namespace Omnigo;

/** One page per job, bounded retries. Independent from the order import queue. */
final class InventoryCatalogQueue
{
    public function __construct(private Database $db,private IntegrationVault $vault){}
    public function tick(int $company,int $deadline):void
    {
        if(!(new InventorySettings($this->db))->marketplaceEnabled($company))return;
        $enrollment=new InventoryCatalogEnrollment($this->db);
        foreach(InventoryCatalogPage::SUPPORTED as $provider){
            try{
                $connection=$this->vault->get($company,$provider);
                $enrollment->ensure($company,$provider,!empty($connection['enabled']));
            }catch(\Throwable $error){error_log('Omnigo '.InventoryCatalogPage::LABELS[$provider].' katalog görevi hazırlığı: '.get_class($error));}
        }
        $progress=new InventoryProgress($this->db);
        $gate=new ProviderRequestGate($this->db);
        $jobs=$this->db->all('SELECT * FROM inventory_catalog_jobs WHERE company_id=? AND next_run<=? ORDER BY next_run,provider',[$company,time()]);
        foreach($jobs as $job){
            if(time()+8>=$deadline)break;
            $provider=$job['provider'];$progress->ensure($company,$provider);
            try{
                $gate->run($company,$provider,$deadline,false,fn()=>$this->read($company,$provider,$deadline,$progress));
            }catch(ProviderRequestDeferred){}
            catch(\Throwable $error){error_log('Omnigo katalog isteği: '.get_class($error));}
        }
        (new InventoryMetadataQueue($this->db,$this->vault))->tick($company,$deadline);
        try{(new IntegrationImportReconciler($this->db))->check($company);}
        catch(\Throwable $noticeError){error_log('Omnigo katalog aktarım raporu: '.get_class($noticeError));}
    }

    private function read(int $company,string $provider,int $deadline,InventoryProgress $progress): void
    {
        $job=$progress->claim($company,$provider);
        if(!$job)return;
        try{
            if(!in_array($provider,InventoryCatalogPage::SUPPORTED,true))throw new \DomainException('Bu pazaryerinde stok kataloğu henüz desteklenmiyor.');
            $access=$this->vault->get($company,$provider);
            if(!$access||empty($access['enabled']))throw new \DomainException('API bağlantısı bekleniyor.');
            $trendyol=null;
            if($provider==='trendyol'){
                $trendyol=new TrendyolCatalogProgress($this->db,$this->vault);
                $prepared=$trendyol->prepare($company,$job,(string)($access['revision']??''));
                if(!empty($prepared['restart']))return;
                $access['_inventoryCursor']=$prepared['cursor'];
            }
            $partition=(int)$job['partition_no'];
            $access['_inventoryPartition']=$partition;
            $pazarama=$provider==='pazarama'?new PazaramaCatalogPass($this->db,$this->vault,$company,$access):null;
            if($pazarama&&$partition===1){
                $page=$pazarama->approvedPage($job,$deadline);
            }else{
                $raw=(new InventoryCatalogClient())->page($provider,$access,(int)$job['page_no'],$deadline);
                $page=InventoryCatalogPage::normalize($provider,$raw,(int)$job['page_no'],$partition);
            }
            if($trendyol){if(!$trendyol->commit($company,$job,$page,(string)($access['revision']??'')))return;}
            else $progress->commit($company,$job,$page,$pazarama?->checkpoint($job,$page));
            try{
                IntegrationImportEvidence::catalogPage($this->db,$company,$job,$page,(string)($access['revision']??''));
                StoreSetupRun::catalogPage($this->db,$company,$job,(string)($access['revision']??''));
            }
            catch(\Throwable $noticeError){error_log('Omnigo katalog kanıtı: '.get_class($noticeError));}
        }catch(\Throwable $error){
            $progress->fail($company,$job,$error);
            if($error instanceof SyncFailure&&$error->httpStatus===429)throw $error;
        }
    }
}
