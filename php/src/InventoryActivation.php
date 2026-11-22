<?php
declare(strict_types=1);
namespace Omnigo;

/** Validates configured connections without disclosing or changing credentials. */
final class InventoryActivation
{
    public function __construct(private Database $db,private IntegrationVault $vault){}
    public function state(int $company):array
    {
        $settings=new InventorySettings($this->db);$preferences=$settings->preferences($company);$connections=[];
        foreach((new CustomerMarketplaces($this->db))->listing($company) as $market){
            if(!$market['selected'])continue;
            $provider=$market['provider'];$reason='';$last=null;
            if(!in_array($provider,InventoryCatalogPage::SUPPORTED,true))$reason='Bu pazaryerinde stok kataloğu bağlantısı henüz desteklenmiyor.';
            else{
                $config=$this->vault->get($company,$provider);
                $credential=$this->db->one('SELECT updated FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
                $sync=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
                $value=$sync?json_decode($sync['payload'],true,64,JSON_THROW_ON_ERROR):[];
                $last=$value['recentOrders']['lastSuccessAt']??$value['lastSuccessAt']??$value['watermark']??null;
                $job=$this->db->one('SELECT state,error FROM jobs WHERE company_id=? AND provider=?',[$company,$market['name']]);
                if(!$config||empty($config['enabled']))$reason='Pazaryeri bağlantıları bekleniyor.';
                elseif(!$last||strtotime($last)<strtotime($credential['updated']??'now'))$reason='API kaydedildi; ilk başarılı bağlantı kontrolü bekleniyor.';
                elseif(!$job||in_array($job['state'],['failed','blocked'],true)||!empty($job['error']))$reason='Bağlantı sorununun giderilmesi bekleniyor.';
                elseif(time()-strtotime($last)>3600)$reason='Güncel başarılı bağlantı kontrolü bekleniyor.';
            }
            $connections[]=['provider'=>$provider,'name'=>$market['name'],'ready'=>$reason==='','reason'=>$reason,'lastSuccess'=>$last];
        }
        $active=$settings->marketplaceEnabled($company);
        $profile=$this->db->one('SELECT version FROM company_profiles WHERE company_id=?',[$company]);
        return [...$preferences,'profileVersion'=>(int)($profile['version']??0),'storeEnabled'=>$settings->storeEnabled($company),'marketplaceActive'=>$active,
            'canStart'=>$preferences['mode']!=='store'&&count($connections)>0&&!array_filter($connections,fn($row)=>!$row['ready']),
            'connections'=>$connections,'message'=>$active?'Canlı stok aktarımı açık.':($preferences['mode']==='store'?'Mağaza stoğu manuel takip edilir.': 'Pazaryeri bağlantıları bekleniyor.')];
    }
    public function start(int $company,int $actor,int $version,?array $providers=null):array
    {
        return $this->db->transaction(function()use($company,$actor,$version,$providers){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $profile=$this->db->one('SELECT payload,version FROM company_profiles WHERE company_id=?',[$company]);
            if(!$profile||(int)$profile['version']!==$version)throw new \DomainException('Müşteri ayarları değişti. Önce ayarları kaydedip yeniden deneyin.');
            $data=json_decode($profile['payload'],true,64,JSON_THROW_ON_ERROR);
            $policy=$data['workspacePolicy']??[];
            if(!CustomerSetupSelection::catalogEnabled($data))throw new \DomainException('Önce bu mağazada canlı stok kapsamını açıp kaydedin.');
            $state=$this->state($company);
            if($providers!==null){
                if($state['marketplaceActive'])return $state;
                if($providers!==array_column($state['connections'],'provider'))throw new \DomainException('Pazaryeri seçimi değişti.');
            }
            if(!$state['canStart'])throw new \DomainException('Seçili pazaryerlerinin API bağlantıları ve başarılı bağlantı kontrolleri tamamlanmalı.');
            $settings=new InventorySettings($this->db);$settings->ensureJob($company);
            $first=!$this->db->one('SELECT company_id FROM inventory_tracking WHERE company_id=?',[$company]);
            $progress=new InventoryProgress($this->db);
            foreach($state['connections'] as $connection){
                $progress->ensure($company,$connection['provider']);
                // A manual refresh must not bypass provider backoff or an in-flight lease.
                $this->db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=? AND provider=? AND attempts=0',[$company,$connection['provider']]);
            }
            if($first){
                $this->db->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(?,?)',[$company,gmdate('c')]);
                (new CustomerManagement($this->db))->audit(['id'=>$actor],$company,'Stok Yönetimi canlı aktarımı başlatıldı',$company);
            }
            $this->db->run('UPDATE inventory_jobs SET next_run=0 WHERE company_id=?',[$company]);
            // Previously entered manual products are verified by exact SKU/barcode in fresh catalogues.
            $selection=array_map(fn($c)=>['provider'=>$c['name'],'code'=>''],$state['connections']);
            foreach($first?$this->db->all('SELECT product_key FROM inventory_manual_products WHERE company_id=?',[$company]):[] as $product)
                (new InventoryMatching($this->db))->enqueue($company,$product['product_key'],$selection);
            if(isset($data[InventoryActivationRequest::KEY])){
                unset($data[InventoryActivationRequest::KEY]);
                $this->db->run('UPDATE company_profiles SET payload=? WHERE company_id=?',[json_encode($data,JSON_THROW_ON_ERROR),$company]);
            }
            return $this->state($company);
        });
    }
}
