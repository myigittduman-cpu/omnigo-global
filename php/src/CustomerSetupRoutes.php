<?php
declare(strict_types=1);
namespace Omnigo;

/** The setup guide can prepare a store, while its existing credentials stay in Marketplace Settings. */
final class CustomerSetupRoutes
{
    public function __construct(private Database $db,private Security $security){}

    public function handle(string $path,string $method,array $user):void
    {
        if(!str_starts_with($path,'/api/management/setup/'))return;
        Security::admin($user);
        $company=$this->security->company($user,$_GET['company']??null);
        $part=substr($path,strlen('/api/management/setup/'));
        $management=new CustomerManagement($this->db);
        if($part==='customer'&&$method==='GET'){
            $profile=array_intersect_key($management->profile($company,true),array_flip(
                ['contactName','phone','email','address','notes','shippingScope','version','hasLogo']));
            $users=array_map(static fn($account)=>array_intersect_key($account,array_flip(['name','email','active'])),$management->users($company));
            $settings=(new CustomerPermissions($this->db,$company))->configuration();
            Http::json(['profile'=>$profile,'users'=>$users,'configuration'=>array_intersect_key($settings,array_flip(['version','inventory']))]);
        }
        if($part==='customer'&&$method==='PUT'){
            $management->saveProfile($user,$company,Http::body());
            Http::json(['message'=>'Müşteri bilgileri kaydedildi.']);
        }
        if($part==='logo'&&$method==='POST'){
            if(!isset($_FILES['logo']))Http::fail(400,'Logo dosyası seçin.');
            $management->setLogo($user,$company,$_FILES['logo']);
            Http::json(['message'=>'Logo güncellendi.']);
        }
        $marketplaces=new CustomerMarketplaces($this->db);
        if($part==='marketplaces'&&$method==='GET'){
            Http::json(['items'=>$marketplaces->listing($company),'setupRun'=>StoreSetupRun::status($this->db,$company)]);
        }
        if($method==='POST'&&preg_match('#^marketplaces/([a-z0-9]+)$#D',$part,$match)){
            $marketplaces->change($user,$company,$match[1],Http::body(),initialOnly:true);
            Http::json(['message'=>'Pazaryeri ayarı kaydedildi.']);
        }
        Http::fail(404,'Kurulum işlemi bulunamadı.');
    }
}
