<?php
declare(strict_types=1);
namespace Omnigo;

/** Fifth model is selectable for every tenant; writes require an explicit saved opt-in. */
final class MarketplaceStockPolicy
{
    private ?array $configuration;
    public function __construct(private Database $db,?array $configuration=null)
    {
        $this->configuration=$configuration;
    }
    public static function onboardingOption():array
    {
        return ['baseMode'=>'horoz','label'=>'Canlı stok + manuel işlem + pazaryeri yönetimi',
            'description'=>'Canlı stok ve manuel mağaza işlemleri ortak stoğu kullanır. Omnigo yöneticisi, Hepsiburada bağlantısıyla eşleşen ürüne onay vererek stok adedi gönderebilir.'];
    }
    public function option(int $company):?array
    {
        $row=$this->db->one('SELECT name FROM companies WHERE id=?',[$company]);
        if(!$row)return null;
        $entry=$this->configuration===null?self::onboardingOption():$this->configuration['companies'][(string)$company]??null;
        if(!is_array($entry)||!in_array($entry['baseMode']??'', ['horoz','both'],true))return null;
        if(isset($entry['nameHash'])&&!hash_equals((string)$entry['nameHash'],hash('sha256',$row['name'])))return null;
        return [...self::onboardingOption(),'baseMode'=>$entry['baseMode']];
    }
    public function validate(int $company,array $settings):void
    {
        if(empty($settings['marketplaceManagement']))return;
        $option=$this->option($company);
        if(!$option||$settings['mode']!==$option['baseMode'])throw new \DomainException('Pazaryeri yönetimi bu müşterinin mevcut stok modeli için açık değil.');
    }
    public function enabled(int $company):bool
    {
        $settings=(new InventorySettings($this->db))->preferences($company);$option=$this->option($company);
        return $option&&!empty($settings['marketplaceManagement'])&&$settings['mode']===$option['baseMode'];
    }
    public function requireAdmin(int $company,array $user):void
    {
        Security::admin($user);
        if(!$this->enabled($company))throw new \DomainException('Bu müşteri için pazaryeri stok yönetimi açık değil.');
    }
}
