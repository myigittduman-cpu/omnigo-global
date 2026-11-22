<?php
declare(strict_types=1);
namespace Omnigo;

final class ManagementRoutes
{
    public function __construct(private Database $db,private array $config,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        if (!str_starts_with($path,'/api/management/')) return;
        if($path==='/api/management/role-defaults'){
            Security::admin($user);
            $defaults=new CustomerRoleDefaults($this->db);
            if($method==='GET')Http::json($defaults->read());
            if($method==='PUT')Http::json($defaults->save($user,Http::body()));
            Http::fail(405,'İşlem desteklenmiyor.');
        }
        if(in_array($path,['/api/management/permission-template','/api/management/permissions-bulk'],true)){
            Security::admin($user);
            if($path==='/api/management/permission-template'&&$method==='GET')Http::json([
                'configuration'=>CustomerInitialPolicy::template($this->db)+['inventoryManagement'=>MarketplaceStockPolicy::onboardingOption()],
                'catalog'=>CustomerPermissionCatalog::pages(),'actions'=>CustomerPermissionCatalog::ACTIONS,'menuLabels'=>CustomerMenuLabelScopes::effective($this->db,0),
            ]);
            $bulk=new CustomerPermissionBatch($this->db);
            if($path==='/api/management/permissions-bulk'&&$method==='GET')Http::json(['items'=>$bulk->targets($user)]);
            if($path==='/api/management/permissions-bulk'&&$method==='POST')Http::json($bulk->apply($user,Http::body()));
            Http::fail(405,'İşlem desteklenmiyor.');
        }
        $company=$this->security->company($user,$_GET['company']??null);
        $management=new CustomerManagement($this->db);
        if ($path==='/api/management/logo' && $method==='GET') $management->logo($company);
        Security::admin($user);
        if($path==='/api/management/menu-labels'){
            if($method==='GET')Http::json(CustomerMenuLabelScopes::read($this->db,$user,$company,$_GET));
            if($method==='PUT')Http::json(CustomerMenuLabelScopes::save($this->db,$user,$company,Http::body()));
            Http::fail(405,'İşlem desteklenmiyor.');
        }
        if($path==='/api/management/new-records'){
            if(!NewRecordRun::available($this->db))Http::fail(503,'Yeni kayıt aktarımı henüz hazır değil.');
            $run=new NewRecordRun($this->db);
            $vault=new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key');
            if($method==='GET'){
                if(($_GET['statusOnly']??'')==='1')Http::json($run->status($company));
                Http::json($run->status($company)+['tables'=>TableSyncTargets::listing($this->db,$company,NewRecordWorker::sources($this->db,$vault,$company),$vault)]);
            }
            if($method==='POST'){
                $body=(int)($_SERVER['CONTENT_LENGTH']??-1)===0?[]:Http::body();
                $request=$body?TableSyncTargets::request($body):[];
                if(!$request||!$run->remembered($company,$request['requestId']))$this->security->consumeAttempt(hash('sha256','new-record-run:'.$company));
                Http::json($run->start($company,(int)$user['id'],NewRecordWorker::sources($this->db,$vault,$company),$request),202);
            }
            Http::fail(405,'İşlem desteklenmiyor.');
        }
        if($path==='/api/management/initial-password'&&$method==='GET')Http::json(StoreAccountCredentials::suggestion($this->db,$company));
        if($path==='/api/management/permissions'){
            $permissions=new CustomerPermissions($this->db,$company);
            if($method==='GET'){$users=$management->users($company);Http::json(['configuration'=>$permissions->configuration(),'catalog'=>CustomerPermissionCatalog::managementPages(),'actions'=>CustomerPermissionCatalog::ACTIONS,'users'=>$users,'menuLabels'=>CustomerMenuLabelScopes::previews($this->db,$company,$users)]);}
            if($method==='PUT')Http::json(['configuration'=>$permissions->save($user,Http::body()),'message'=>'Müşteri yetkileri kaydedildi.']);
        }
        if($path==='/api/management/marketplaces-bulk'&&$method==='POST')Http::json((new CustomerMarketplaceBatch($this->db))->apply($user,$company,Http::body()));
        if($path==='/api/management/marketplaces'&&$method==='GET')Http::json([
            'items'=>(new CustomerMarketplaces($this->db))->listing($company),
            'setupRun'=>StoreSetupRun::status($this->db,$company),
        ]);
        if($path==='/api/management/marketplaces/start'&&$method==='POST'){
            $this->security->consumeAttempt(hash('sha256','marketplace-transfer-start:'.$company));
            $transfer=new CustomerTransferStart($this->db,dirname(__DIR__).'/storage/backup.key');
            Http::json($transfer->request($user,$company,Http::body()),202);
        }
        if(preg_match('#^/api/management/marketplaces/([a-z0-9]+)$#D',$path,$market)&&$method==='POST'){
            (new CustomerMarketplaces($this->db))->change($user,$company,$market[1],Http::body());
            Http::json(['message'=>'Pazaryeri ayarı kaydedildi.']);
        }
        if ($path==='/api/management/stores' && $method==='GET') Http::json(['items'=>(new CustomerStores($this->db))->list($company)]);
        if (preg_match('#^/api/management/stores/(\d+)$#D',$path,$store) && $method==='PUT') {
            (new CustomerStores($this->db))->save($user,$company,(int)$store[1],Http::body());
            Http::json(['message'=>'Mağaza adı kaydedildi.']);
        }
        if ($path==='/api/management/customer' && $method==='GET') Http::json(['profile'=>$management->profile($company,true),'users'=>$management->users($company),'mailReady'=>(new MailQueue($this->db,$this->config))->ready(),'mail'=>$this->db->all('SELECT m.id,m.state,m.attempts,m.created,u.email FROM mail_outbox m JOIN users u ON u.id=m.user_id WHERE u.company_id=? ORDER BY m.created DESC LIMIT 20',[$company])]);
        if ($path==='/api/management/customer' && $method==='PUT') { $management->saveProfile($user,$company,Http::body()); Http::json(['message'=>'Müşteri bilgileri kaydedildi.']); }
        if ($path==='/api/management/logo' && in_array($method,['POST','DELETE'],true)) {
            if ($method==='POST' && !isset($_FILES['logo'])) Http::fail(400,'Logo dosyası seçin.');
            $management->setLogo($user,$company,$method==='POST'?$_FILES['logo']:null); Http::json(['message'=>'Logo güncellendi.']);
        }
        if (preg_match('#^/api/management/users/(\d+)(?:/(reset|sessions|password))?$#D',$path,$match)) {
            $id=(int)$match[1]; $target=$management->customer($company,$id);
            if($method==='POST'&&($match[2]??'')==='password'){StoreAccountCredentials::set($this->db,$user,$company,$id,Http::body());Http::json(['message'=>'Şifre kaydedildi. Kullanıcı yeni şifresiyle giriş yapabilir.']);}
            if ($method==='PUT' && !isset($match[2])) { $management->updateUser($user,$company,$id,Http::body()); Http::json(['message'=>'Hesap güncellendi.']); }
            if ($method==='POST' && ($match[2]??'')==='reset') { if (!(int)$target['active']) Http::fail(400,'Pasif hesaba yenileme bağlantısı gönderilemez.'); (new PasswordRecovery($this->db,$this->config))->request($target['email'],$user); Http::json(['message'=>PasswordRecovery::MESSAGE]); }
            if ($method==='POST' && ($match[2]??'')==='sessions') {
                $this->db->transaction(function() use($management,$user,$company,$id) { $management->revoke($id); $management->audit($user,$company,'Müşteri oturumları kapatıldı',$id); }); Http::json(['message'=>'Açık oturumlar ve yenileme bağlantıları iptal edildi.']);
            }
        }
        Http::fail(404,'İşlem bulunamadı.');
    }
}
