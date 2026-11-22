<?php
declare(strict_types=1);
namespace Omnigo;

/** Restore missing derived jobs without changing a tenant's connection or stock preferences. */
final class InventoryCatalogEnrollment
{
    public function __construct(private Database $db){}

    /** The caller derives enabled from the vault or its guarded connection-save callback. */
    public function ensure(int $company,string $provider,bool $connectionEnabled):bool
    {
        if(!$connectionEnabled||!in_array($provider,InventoryCatalogPage::SUPPORTED,true))return false;
        $label=InventoryCatalogPage::LABELS[$provider]??null;
        if($label===null)return false;
        $write=function()use($company,$provider,$label):bool{
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))return false;
            if(!(new InventorySettings($this->db))->marketplaceEnabled($company))return false;
            if(!$this->db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]))return false;
            $selected=false;
            foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='channels'",[$company]) as $row){
                $channel=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                // Hiding a card does not disconnect its selected live transfer.
                if(($channel['name']??null)!==$label)continue;
                $chosen=$provider==='trendyol'? !empty($channel['selected'])
                    : (!array_key_exists('selected',$channel)||!empty($channel['selected']));
                if($chosen&&CustomerMarketplaces::available($channel)){$selected=true;break;}
            }
            if(!$selected)return false;
            $job=$this->db->one('SELECT provider FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$provider]);
            $progress=$this->db->one('SELECT provider FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]);
            if($job&&$progress)return false;
            (new InventoryProgress($this->db))->ensure($company,$provider);
            return true;
        };
        return $this->db->pdo->inTransaction()?$write():TransactionRetry::run($this->db,$write);
    }
}
