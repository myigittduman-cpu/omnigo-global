<?php
declare(strict_types=1);
namespace Omnigo;

final class Application
{
    private Security $security;
    private Repository $repository;
    public function __construct(private Database $db, private array $config)
    {
        $this->security = new Security($db,$config);
        $this->repository = new Repository($db);
    }
    public function handle(string $path, string $method): never
    {
        Http::protect($this->config);
        StoreReviewPolicy::protect($this->config, $path, $method);
        if ($path==='/api/mobile-push/refresh' && $method==='POST') {
            $token=$_SERVER['HTTP_X_OMNIGO_DEVICE']??'';
            $this->security->consumeAttempt(hash('sha256','mobile-push-refresh:'.$token.':'.($_SERVER['REMOTE_ADDR']??'')));
            (new MobilePushDevices($this->db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->refresh($token,Http::body(),PushRoutes::devices($this->db));
            Http::json(['updated'=>true]);
        }
        if ($path==='/api/push/desktop/poll' && $method==='GET') {
            $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';
            $token=str_starts_with($authorization,'Bearer ')?substr($authorization,7):($_SERVER['HTTP_X_OMNIGO_DEVICE']??'');
            $after=isset($_GET['after'])?filter_var($_GET['after'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2147483647]]):null;
            if($after===false)Http::fail(400,'Bildirim konumu geçersiz.');
            $chat=($_GET['chat']??'')==='1';
            $chatAfter=$chat&&isset($_GET['chatAfter'])?filter_var($_GET['chatAfter'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]):null;
            if($chatAfter===false)Http::fail(400,'Sohbet bildirim konumu geçersiz.');
            $currentNoticeFormat=($_GET['noticeSchema']??'')==='2';
            Http::json(PushRoutes::devices($this->db)->poll($token,$after,($_GET['events']??'')==='all',($_GET['recovery']??'')==='1',($_GET['operations']??'')==='1',$this->config['portal_role']??null,$chat,$chatAfter,$currentNoticeFormat));
        }
        if($path==='/api/login/profiles'&&$method==='GET'){PortalAccess::assertRole($this->config,'admin');Http::json(['items'=>AdminLoginProfiles::listing($this->db)]);}
        if ($path==='/api/status' && $method==='GET') Http::json(['needsSetup'=>($this->config['portal_role']??'admin')==='admin' && !$this->db->one('SELECT id FROM users LIMIT 1'),'demo'=>false,'portalRole'=>$this->config['portal_role']??null,'reviewEnvironment'=>!empty($this->config['store_review']),'workspaceDesign'=>WorkspaceDesign::shared()->read()]);
        if ($path==='/api/setup' && $method==='POST') {
            PortalAccess::assertRole($this->config, 'admin');
            $data = Http::body();
            $expected = $this->config['setup_token_hash'] ?? '';
            if (!$expected || !is_string($data['setupCode']??null) || !hash_equals($expected,hash('sha256',$data['setupCode']))) Http::fail(403,'Kurulum kodunu kontrol edin.');
            $account = Validation::account($data);
            $hash = Security::hashPassword($account['password']);
            $this->db->transaction(function() use($account,$hash) {
                $lock = $this->db->one('SELECT id FROM install_state WHERE id=1'.$this->db->lock());
                if (!$lock) Http::fail(503,'Kurulum hazırlığı tamamlanmadı.');
                if ($this->db->one('SELECT id FROM users LIMIT 1')) Http::fail(409,'Kurulum zaten tamamlandı.');
                $this->repository->createCompany('Vizyona');
                $this->db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,NULL)',[$account['email'],$account['name'],$hash,'admin']);
            });
            Http::json(['message'=>'Yönetici hesabı oluşturuldu. Giriş yapabilirsiniz.'],201);
        }
        if ($path==='/api/login' && $method==='POST') Http::json([...$this->security->login(Http::body()),'reviewEnvironment'=>!empty($this->config['store_review'])]);
        if($path==='/api/login/mfa/verify'&&$method==='POST')Http::json($this->security->confirmMfa(Http::body()));
        if($path==='/api/login/mfa/resend'&&$method==='POST'){
            PortalAccess::assertRole($this->config,'admin');Http::json((new AdminEmailMfa($this->db,$this->config))->resend(Http::body()));
        }
        if ($path==='/api/password/forgot' && $method==='POST') {
            $data=Http::body(); $email=mb_strtolower(Validation::text($data['email']??null,'E-posta',200));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) Http::fail(400,'Geçerli bir e-posta yazın.');
            (new PasswordRecovery($this->db,$this->config))->request($email);
            Http::json(['message'=>PasswordRecovery::MESSAGE]);
        }
        if ($path==='/api/password/reset' && $method==='POST') {
            $this->security->consumeAttempt(hash('sha256','reset-complete:'.($_SERVER['REMOTE_ADDR']??'local')));
            (new PasswordRecovery($this->db,$this->config))->complete(Http::body());
            Http::json(['message'=>'Şifreniz yenilendi. Yeni şifrenizle giriş yapabilirsiniz.']);
        }
        $user = $this->security->user();
        if ($method!=='GET') $this->security->csrf($user);
        if(!empty($user['mustChangePassword'])&&!in_array($path,['/api/me','/api/logout','/api/account/initial-password'],true))Http::fail(403,'Devam etmek için geçici şifrenizi değiştirin.');
        if($path==='/api/account/initial-password'&&$method==='POST'){
            $this->security->consumeAttempt(hash('sha256','initial-password:'.$user['id']));CustomerInitialPassword::complete($this->db,$user,Http::body());$this->security->logout($user);Http::json(['changed'=>true]);
        }
        CustomerPermissionRoutes::enforce($this->db,$this->security,$user,$path,$method);
        (new MarketplaceGateRoutes($this->db,$this->security))->handle($path,$method,$user);
        if(MarketplaceGate::protects($path,$method)){
            Security::admin($user);$lockedCompany=MarketplaceGate::globalScope($path)?0:$this->security->company($user,$path==='/api/users'?(Http::body()['companyId']??null):($_GET['company']??null));
            (new MarketplaceGate($this->db))->require($user,$lockedCompany,$_SERVER['HTTP_X_OMNIGO_MANAGEMENT_GRANT']??null);
        }
        (new OperationRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new AccountDirectoryRoutes($this->db))->handle($path,$method,$user);
        (new AccountActivityRoutes($this->db))->handle($path,$method,$user);
        if($path==='/api/session/operator'&&$method==='POST')Http::json((new ClientSessionOperator($this->db))->start($user,Http::body()['name']??null));
        (new AccountSettingsRoutes($this->db,$this->config))->handle($path,$method,$user);
        (new AdminMfaRoutes($this->db,$this->config,$this->security))->handle($path,$method,$user);
        if($path==='/api/account/deletion-request'&&$method==='POST'){
            $this->security->consumeAttempt(hash('sha256','privacy-delete:'.$user['id']));
            Http::json((new AccountDeletionRequest($this->db,$this->config))->create($user,Http::body()),202);
        }
        (new MobilePushRoutes($this->db))->handle($path,$method,$user);
        (new PushRoutes($this->db))->handle($path,$method,$user);
        (new ShipmentRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new OrderDocumentRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new OrderRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new MarketplaceStockRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new ActiveMarketplaceListingRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new InventoryRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new ReturnRequestRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new DeliveryRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new FileCenterRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new ReportingRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new NotificationRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new SupportRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new ChatRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new IntegrationRoutes($this->db,$this->config,$this->security))->handle($path,$method,$user);
        (new PazaramaRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new N11Routes($this->db,$this->security))->handle($path,$method,$user);
        (new MarketplaceConnectionRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new HepsiburadaRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new InboxRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new CustomerSetupRoutes($this->db,$this->security))->handle($path,$method,$user);
        (new ManagementRoutes($this->db,$this->config,$this->security))->handle($path,$method,$user);
        if ($path==='/api/me' && $method==='GET') Http::json(['user'=>Security::publicUser($user),'csrf'=>$user['csrf'],'demo'=>false,'keepSession'=>SessionLifetime::persistent((int)$user['session_expires']),'reviewEnvironment'=>!empty($this->config['store_review'])]);
        if ($path==='/api/logout' && $method==='POST') { $this->security->logout($user); Http::json((object)[]); }
        if ($path==='/api/companies' && $method==='GET') {
            $rows = $user['role']==='admin' ? $this->db->all('SELECT * FROM companies ORDER BY id') : $this->db->all('SELECT * FROM companies WHERE id=?',[$user['company_id']]);
            $pilot=$user['role']==='admin'?MarketplaceStockPilot::load():null;
            Http::json(array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],...(new CustomerManagement($this->db))->profile((int)$r['id'],false),
                ...($pilot&&MarketplaceStockJob::allowed($this->db,$pilot,(int)$r['id'])?['marketplaceStockPilot'=>true]:[])],$rows));
        }
        if($path==='/api/management/permission-template'&&$method==='GET'){Security::admin($user);Http::json(['configuration'=>CustomerInitialPolicy::template($this->db),'catalog'=>CustomerPermissionCatalog::pages(),'actions'=>CustomerPermissionCatalog::ACTIONS]);}
        if ($path==='/api/companies' && $method==='POST') {
            Security::admin($user); $data=Http::body(); $name=Validation::text($data['name']??null,'Firma adı',150);
            Http::json(['id'=>CustomerOnboarding::create($this->db,$user,$name,$data['shipmentStartDate']??null,
                $data['modules']??null,$data['marketplaces']??null,$data['workspacePages']??null,
                $data['initialPolicy']??null,$data['shippingScope']??null,$data['setupOptions']??null,$data['profile']??null)],201);
        }
        if ($path==='/api/users' && $method==='POST') {
            Security::admin($user); $data=Http::body(); $account=StoreAccountCredentials::account($data);$account['username']=LoginIdentity::validate($data['username']??null);
            $account['storeRole']=$data['storeRole']??null;
            $company=$this->security->company($user,$data['companyId']??null);
            if ($this->db->one('SELECT id FROM users WHERE email=?',[$account['email']])) Http::fail(409,'Bu e-posta zaten kayıtlı.');
            try { $id=CustomerUserProvisioning::create($this->db,$user,$company,$account); }
            catch (\PDOException $error) { if ((string)$error->getCode()==='23000') Http::fail(409,'Bu e-posta zaten kayıtlı.'); throw $error; }
            Http::json(['id'=>$id],201);
        }
        $company=$this->security->company($user,$_GET['company']??null);
        if ($path==='/api/workspace/revision' && $method==='GET') Http::json(['revision'=>ShipmentRevision::read($this->db,$company),'company'=>CustomerBranding::read($this->db,$company,$user),'workspaceDesign'=>WorkspaceDesign::shared()->read()]);
        if ($path==='/api/dashboard' && $method==='GET') Http::json($this->repository->dashboard($user,$company,Validation::month($_GET['month']??null),isset($_GET['from'],$_GET['to'])?['from'=>(string)$_GET['from'],'to'=>(string)$_GET['to']]:null));
        if ($path==='/api/sales/detail' && $method==='GET') {
            MarketplaceVisibility::requireVisible((string)($_GET['channel']??''));
            $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT);
            if ($page===false) Http::fail(400,'Sayfa numarası geçersiz.');
            Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,(new SalesAnalytics($this->db))->report($user,$company,Validation::choice($_GET['channel']??null,Repository::CHANNELS),Validation::date($_GET['from']??null),Validation::date($_GET['to']??null),$page),salesAmounts:true,page:'overviewSales'));
        }
        if($path==='/api/sales/top-products'&&$method==='GET')Http::json(CustomerSensitiveVisibility::financial($this->db,$user,$company,(new SalesTopProducts($this->db))->report($user,$company,Validation::date($_GET['from']??null),Validation::date($_GET['to']??null),(string)($_GET['channel']??'all')),salesAmounts:true,page:'overviewTop'));
        if($path==='/api/sales/product-image'&&$method==='GET'){
            try{$bytes=(new SalesProductImages($this->db))->read($company,(string)($_GET['productKey']??''));}
            catch(\OutOfBoundsException){Http::fail(404,'Ürün görseli bulunamadı.');}
            header('Content-Type: image/png');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $bytes;exit;
        }
        if ($path==='/api/audit' && $method==='GET') {
            Security::admin($user);
            Http::json($this->db->all('SELECT a.id,a.action,a.record_id,a.created,u.name FROM audit a LEFT JOIN users u ON u.id=a.user_id WHERE a.company_id=? ORDER BY a.id DESC LIMIT 100',[$company]));
        }
        if($method==='POST'&&preg_match('#^/api/records/payments/(\d+)/approve$#D',$path,$match))Http::json((new PaymentApproval($this->db))->approve($user,$company,(int)$match[1],Http::body()));
        if($method==='POST'&&$path==='/api/records/payments/selection/delete'){
            Security::admin($user);
            Http::json((new PaymentDeletion($this->db))->deleteSelection($user,$company,Http::body(262144)));
        }
        if($method==='DELETE'&&preg_match('#^/api/records/payments/(\d+)$#D',$path,$match))Http::json((new PaymentDeletion($this->db))->delete($user,$company,(int)$match[1],Http::body()));
        if (preg_match('#^/api/records/(channels|sales|payments|tasks|reports)(?:/(\d+))?$#D',$path,$match) && in_array($method,['POST','PUT'],true)) {
            if(!in_array($match[1],['tasks','reports'],true))Security::admin($user);
            ClientSessionOperator::requireName($user); $id=isset($match[2])?(int)$match[2]:null;
            if (($method==='PUT' && !$id)||($method==='POST' && $id)) Http::fail(400,'İşlem geçersiz.');
            Http::json($this->repository->save($user,$company,$match[1],Http::body(),$id),$id?200:201);
        }
        Http::fail(404,'İşlem bulunamadı.');
    }
}
