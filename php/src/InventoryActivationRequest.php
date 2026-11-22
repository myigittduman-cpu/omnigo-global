<?php
declare(strict_types=1);
namespace Omnigo;

/** Keeps explicit stock activation pending until the first verified connection checks arrive. */
final class InventoryActivationRequest
{
    public const KEY='inventoryActivationRequest';
    public function __construct(private Database $db) {}

    public function request(int $company,int $actor,int $version,array $providers): string
    {
        return TransactionRetry::run($this->db,function()use($company,$actor,$version,$providers){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one('SELECT version,payload FROM company_profiles WHERE company_id=?',[$company]);
            if((int)($row['version']??0)!==$version)Http::fail(409,'Stok ayarları değişti. Son kontrolü yenileyin.');
            $profile=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
            $policy=$profile['workspacePolicy']??[];
            if(!CustomerSetupSelection::catalogEnabled($profile))return 'disabled';
            $settings=new InventorySettings($this->db);
            if(($policy['inventory']['mode']??'marketplace')==='store')return 'manual';
            if($settings->marketplaceEnabled($company))return 'active';
            if($providers!==$this->selectedProviders($company))Http::fail(409,'Mağaza seçimi değişti. Son kontrolü yenileyin.');
            $settings->ensureJob($company);
            $profile[self::KEY]=['version'=>$version,'providers'=>$providers,'actor'=>$actor];
            $this->db->run('UPDATE company_profiles SET payload=? WHERE company_id=?',[
                json_encode($profile,JSON_THROW_ON_ERROR),$company,
            ]);
            return 'scheduled';
        });
    }

    public function process(int $company,InventoryActivation $activation): void
    {
        $row=$this->db->one('SELECT version,payload FROM company_profiles WHERE company_id=?',[$company]);
        $request=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR)[self::KEY]??null;
        if(!$request||$request['version']!==(int)$row['version']||$request['providers']!==$this->selectedProviders($company))return;
        if((new InventorySettings($this->db))->marketplaceEnabled($company)||!$activation->state($company)['canStart'])return;
        $activation->start($company,(int)$request['actor'],$request['version'],$request['providers']);
    }

    public function selectedProviders(int $company): array
    {
        $selected=array_filter((new CustomerMarketplaces($this->db))->listing($company),static fn(array $market):bool=>$market['selected']);
        return array_column($selected,'provider');
    }
}
