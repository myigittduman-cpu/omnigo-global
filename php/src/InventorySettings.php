<?php
declare(strict_types=1);
namespace Omnigo;

/** Inventory preferences are tenant-owned and independent of order import settings. */
final class InventorySettings
{
    public function __construct(private Database $db){}
    public static function validate(mixed $value):array
    {
        if($value instanceof \stdClass)$value=(array)$value;
        $mode=is_array($value)?($value['mode']??'marketplace'):'marketplace';
        if(!in_array($mode,['marketplace','store','both','horoz'],true))throw new \InvalidArgumentException('Stok takip şeklini seçin.');
        $settings=['mode'=>$mode];
        if(is_array($value)&&array_key_exists('marketplaceManagement',$value)){
            if(!is_bool($value['marketplaceManagement']))throw new \InvalidArgumentException('Pazaryeri yönetimi seçimini kontrol edin.');
            if($value['marketplaceManagement'])$settings['marketplaceManagement']=true;
        }
        return $settings;
    }
    public function preferences(int $company):array
    {
        $row=$this->db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        $profile=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        return self::validate($profile['workspacePolicy']['inventory']??[]);
    }
    public function storeEnabled(int $company):bool{return in_array($this->preferences($company)['mode'],['store','both','horoz'],true);}
    public function marketplaceEnabled(int $company):bool
    {
        $profile=CustomerSetupSelection::profile($this->db,$company);
        return in_array(self::validate($profile['workspacePolicy']['inventory']??[])['mode'],['marketplace','both','horoz'],true)
            &&(!isset($profile['setupOptions'])||CustomerSetupSelection::catalogEnabled($profile))
            &&(bool)$this->db->one('SELECT company_id FROM inventory_tracking WHERE company_id=?',[$company]);
    }
    public function requireStore(int $company):void
    {
        if(!$this->storeEnabled($company))throw new \DomainException('Mağaza stoğu bu müşteride kapalı. Pazaryeri stok miktarı elle değiştirilemez.');
    }
    /** Called in the same transaction as explicitly saving tenant preferences. */
    public function configure(int $company,array $policy):void
    {
        $profile=CustomerSetupSelection::profile($this->db,$company);
        if(!CustomerSectionAudience::enabled($policy['pages']['inventory']??'hidden')&&!CustomerSetupSelection::catalogEnabled($profile))return;
        if(!in_array(($policy['inventory']['mode']??'marketplace'),['store','both','horoz'],true))return;
        $this->ensureJob($company);
    }
    public function ensureJob(int $company):void
    {
        $job=$this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
        if(!$job)$this->db->run('INSERT INTO inventory_jobs(company_id,enabled) VALUES(?,1)',[$company]);
        else $this->db->run('UPDATE inventory_jobs SET enabled=1 WHERE company_id=?',[$company]);
    }
}
