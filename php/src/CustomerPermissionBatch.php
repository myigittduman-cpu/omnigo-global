<?php
declare(strict_types=1);
namespace Omnigo;

/** Admin-only, explicitly selected tenants/users; one conflict rolls back the entire batch. */
final class CustomerPermissionBatch
{
    public function __construct(private Database $db){}
    public function targets(array $actor):array
    {
        Security::admin($actor);$result=[];
        foreach($this->db->all('SELECT id,name FROM companies ORDER BY id') as $company){
            $company['id']=(int)$company['id'];$company['version']=(new CustomerPermissions($this->db,$company['id']))->configuration()['version'];
            $company['users']=(new CustomerManagement($this->db))->users($company['id']);$result[]=$company;
        }return $result;
    }
    public function apply(array $actor,array $data):array
    {
        Security::admin($actor);$configuration=$data['configuration']??null;
        if($configuration instanceof \stdClass)$configuration=(array)$configuration;
        $source=CustomerInitialPolicy::validate($configuration);
        $pages=$data['selectedPages']??null;$targets=$data['targets']??null;
        if(!is_array($pages)||!array_is_list($pages)||!$pages||array_diff($pages,array_keys(CustomerPermissionCatalog::pages())))Http::fail(400,'Uygulanacak bölümleri seçin.');
        if(!is_array($targets)||!array_is_list($targets)||!$targets||count($targets)>500)Http::fail(400,'Uygulanacak hesapları seçin.');
        $settings=($data['includeSettings']??false)===true;$cities=$settings?CustomerCities::validate($configuration['cities']??null):[];
        $ids=[];foreach($targets as $target){if($target instanceof \stdClass)$target=(array)$target;if(!is_array($target)||!is_int($target['companyId']??null)||!is_int($target['version']??null)||!is_bool($target['defaults']??null)||!is_array($target['users']??null)||!array_is_list($target['users']))Http::fail(400,'Hedef hesapları kontrol edin.');
            $id=$target['companyId'];if(isset($ids[$id])||(!$target['defaults']&&!$target['users'])||($settings&&!$target['defaults']))Http::fail(400,'Müşteri geneli ayarlar için müşterinin varsayılanlarını da seçin.');$ids[$id]=$target;
        }ksort($ids);
        return TransactionRetry::run($this->db,function()use($actor,$ids,$source,$pages,$settings,$cities){
            foreach($ids as $id=>$target)if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$id]))Http::fail(404,'Müşteri bulunamadı.');
            $changed=0;
            foreach($ids as $id=>$target){
                $policy=new CustomerPermissions($this->db,$id);$config=$policy->configuration();if($config['version']!==$target['version'])Http::fail(409,'Hedef müşterilerden birinin ayarları değişti. Listeyi yenileyip tekrar seçin.');
                $overrides=(array)$config['users'];unset($config['users']);$config['userChanges']=[];
                foreach($target['users'] as $userId){
                    if(!is_int($userId)||!$this->db->one("SELECT id FROM users WHERE id=? AND company_id=? AND role='client'",[$userId,$id]))Http::fail(403,'Kullanıcı seçili müşteriye ait değil.');
                    $grants=$overrides[$userId]??CustomerPermissions::userDefaults($config,$userId);foreach($pages as $page)$grants[$page]=$source['defaults'][$page];$config['userChanges'][$userId]=$grants;
                }
                if($target['defaults'])foreach($pages as $page)$config['defaults'][$page]=$source['defaults'][$page];
                if($settings){foreach($pages as $page)$config['pages'][$page]=$source['pages'][$page];$config['notifications']=$source['notifications'];$config['cities']=$cities;}
                $policy->save($actor,$config);$changed++;
            }return ['customers'=>$changed,'message'=>'Seçilen hesapların ayarları kaydedildi.'];
        });
    }
}
