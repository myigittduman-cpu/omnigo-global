<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{
    AccountDirectory, AccountDirectorySchema, AccountDirectoryWriter, AccountSettingsSchema, AdminMfaSchema, ClientSessionOperator, CustomerAccountRoles, CustomerDashboardAccess,
    CustomerManagement, CustomerNavigationLayout, CustomerNotificationPolicy, CustomerPermissionBatch, CustomerPermissionRoutes, CustomerPermissions, CustomerSensitiveVisibility, CustomerStandardPermissions,
    CustomerUserProvisioning, Database, DeliveryCipher, DesktopClientNotifications, HttpError, InventorySchema,
    ManagementRoutes, NotificationFeed, NotificationRoutes, NotificationSchema, Security, ShipmentDocuments, ShipmentSchema, ShipmentWriter, SupportSchema, UserPreferences, WorkspaceDesign
};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectDenied(callable $action, int $status = 403): void
{
    try {
        $action();
    } catch (HttpError $error) {
        check($error->getCode() === $status, $error->getMessage());
        return;
    }
    throw new RuntimeException('Yetkisiz işlem kabul edildi.');
}

$db = new Database(['dsn' => 'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
ShipmentSchema::install($db);
NotificationSchema::migrate($db);
SupportSchema::migrate($db);
AccountSettingsSchema::install($db);
AccountDirectorySchema::ensure($db);
AdminMfaSchema::install($db);
ClientSessionOperator::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci Mağaza'),(2,'İkinci Mağaza')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','test','admin',NULL),(2,'eski@example.test','Eski müşteri','test','client',1),(3,'eski-lojistik@example.test','Eski lojistik kullanıcısı','test','client',1)");
$admin = ['id' => 1, 'role' => 'admin'];
$security = new Security($db, ['origin' => 'https://portal.example.test']);
$storePolicy = [
    'pages' => ['inventory' => 'shown', 'support' => 'all_roles'],
    'inventory' => ['mode'=>'horoz'],
    'defaults' => CustomerStandardPermissions::grants(),
    'users' => [],
];
foreach ([1, 2] as $company) {
    $policyForStore = $storePolicy;
    if ($company === 1) {
        $policyForStore['roles'][3] = CustomerAccountRoles::LOGISTICS;
        $policyForStore['navigationLayouts'][3] = CustomerNavigationLayout::LOGISTICS;
        $policyForStore['users'][3]['support'] = ['view'=>true, 'create'=>true, 'reply'=>true, 'status'=>true];
    }
    if ($company === 2) $policyForStore['defaults']['shipments']['upload'] = false;
    $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)', [
        $company, json_encode(['workspacePolicy' => $policyForStore], JSON_THROW_ON_ERROR),
    ]);
}
$legacy = ['id' => 2, 'role' => 'client', 'company_id' => 1];
$legacyAccess = (new CustomerPermissions($db, 1))->access($legacy);
$account = ['name' => 'Örnek kullanıcı', 'password' => 'Test-Omnigo-123456!', 'storeRole' => CustomerAccountRoles::LOGISTICS];
$writer = new AccountDirectoryWriter($db);
$fromDirectory = $writer->save($admin, null, [
    ...$account, 'email' => 'dizin@example.test', 'username' => 'dizin.ornek',
    'role' => 'client', 'companyId' => 1, 'active' => true,
]);
$fromStore = CustomerUserProvisioning::create($db, $admin, 1, [
    ...$account, 'email' => 'magaza@example.test', 'username' => 'magaza.ornek',
]);
$manager = CustomerUserProvisioning::create($db, $admin, 1, [
    ...$account, 'email' => 'yetkili@example.test', 'username' => 'yetkili.ornek', 'storeRole' => CustomerAccountRoles::MANAGER,
]);
$directoryManager = $writer->save($admin, null, [
    'name'=>$account['name'], 'password'=>$account['password'], 'email'=>'dizin-yetkili@example.test',
    'username'=>'dizin.yetkili', 'role'=>'client', 'companyId'=>1, 'active'=>true,
]);
$managerActor = [
    'id'=>$manager, 'role'=>'client', 'company_id'=>1, 'name'=>'Örnek Yetkili', 'operatorName'=>'Örnek Yetkili',
];
expectDenied(fn() => CustomerUserProvisioning::create($db, $admin, 1, [...$account, 'storeRole' => 'admin']), 400);
expectDenied(fn() => $writer->save($legacy, null, $account));

$policy = new CustomerPermissions($db, 1);
$users = array_column((new CustomerManagement($db))->users(1), null, 'id');
$directory = array_column((new AccountDirectory($db))->listing($admin, ['company' => '1'])['items'], null, 'id');
$ids = [$fromDirectory['id'], $fromStore];
foreach ($ids as $id) {
    $user = ['id' => $id, 'role' => 'client', 'company_id' => 1, 'name' => $account['name']];
    check($users[$id]['storeRole'] === CustomerAccountRoles::LOGISTICS && $directory[$id]['storeRole'] === $users[$id]['storeRole'], 'İki kullanıcı listesi aynı rolü göstermeli.');
    $access = $policy->access($user);
    check($access['navigationLayout'] === CustomerNavigationLayout::STANDARD, 'Her iki hesap açma yolu ortak üst menüyü kullanmalı.');
    $visible = array_keys(array_filter($access['grants'], fn(array $actions) => $actions['view']));
    check($visible === ['overviewTop', 'overviewWork', 'inventory', 'shipments', 'deliveries', 'shipmentInvoices' ], 'Muhasebe ve Lojistik rolü şablondaki sayfaları açmalı.');
    check($access['pages']['support'] === 'hidden', 'Yeni Muhasebe ve Lojistik hesabının destek menüsü kapalı olmalı.');
    check($access['overviewSections'] === ['top', 'work'], 'En Çok Satan 10 ve İşlem Bekleyenler açılmalı.');
    check(!$policy->allows($user, 'orders') && $policy->allows($user, 'shipments', 'upload'), 'Belge yükleme sevkiyat ekranında açık olmalı.');
    check($policy->allows($user, 'shipments', 'replaceDocuments') && $policy->allows($user, 'inventory', 'editStock'), 'Onaylı muhasebe şablonunda belge değiştirme ve Horoz stok işlemleri açık olmalı.');
    check(!$policy->allows($user, 'shipments', 'edit')&&!$policy->allows($user, 'shipments', 'delete'), 'Muhasebe rolü sevkiyat kaydını düzenlememeli veya silememeli.');
    $_GET = ['company' => '1'];
    CustomerPermissionRoutes::enforce($db, $security, $user, '/api/sales/top-products', 'GET');
    foreach (['/api/sales/detail','/api/payments/calendar','/api/analytics/report','/api/orders','/api/support/tickets'] as $path) {
        expectDenied(fn() => CustomerPermissionRoutes::enforce($db, $security, $user, $path, 'GET'));
    }
    $_GET = ['company' => '2'];
    expectDenied(fn() => CustomerPermissionRoutes::enforce($db, $security, $user, '/api/shipments', 'GET'));
}
check($policy->allows($managerActor, 'payments'), 'Mağaza Yetkilisi genel mağaza izinlerini almalı.');
check($policy->access($legacy)['grants'] === $legacyAccess['grants'], 'Eski müşteri hesapları korunmalı.');
$legacyLogistics = ['id'=>3,'role'=>'client','company_id'=>1];
$legacyLayoutAccess = $policy->access($legacyLogistics);
$supportActions = ['view'=>false, 'create'=>false, 'reply'=>false, 'status'=>false];
check($legacyLayoutAccess['pages']['support'] === 'hidden'
    && $legacyLayoutAccess['grants']['support'] === $supportActions
    && $policy->configuration()['users']->{3}['support'] === $supportActions
    && CustomerAccountRoles::logisticsDefaults()['support'] === $supportActions,
    'Eski kişisel yetkiler ve tüm rollere açık ayar Muhasebe ve Lojistik rolüne destek açmamalı.');
$_GET = ['company'=>'1'];
foreach ([['GET','/api/support/tickets'], ['GET','/api/support/tickets/1'], ['POST','/api/support/tickets'],
    ['POST','/api/support/tickets/1/reply'], ['POST','/api/support/tickets/1/status'], ['DELETE','/api/support/tickets/1']] as [$method,$path]) {
    expectDenied(fn() => CustomerPermissionRoutes::enforce($db, $security, $legacyLogistics, $path, $method));
}
CustomerPermissionRoutes::enforce($db, $security, $managerActor, '/api/support/tickets', 'GET');
CustomerPermissionRoutes::enforce($db, $security, $managerActor, '/api/support/tickets', 'POST');
check($policy->allows($admin, 'support') && $policy->allows($managerActor, 'support', 'reply'),
    'Omnigo ve Mağaza Yetkilisi destek erişimini korumalı.');
$db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(1,'portal','support:3:1','resolved',?,?)", [
    json_encode(['audience'=>'client','recipientId'=>3,'targetId'=>1], JSON_THROW_ON_ERROR), gmdate('c'),
]);
$noticeId = $db->insertId();
$supportNotice = $db->one('SELECT * FROM order_notifications WHERE id=?', [$noticeId]);
check(!CustomerNotificationPolicy::recipient($db, $legacyLogistics, $supportNotice), 'Eski destek bildirimi lojistik hesabına gönderilmemeli.');
expectDenied(fn() => (new NotificationRoutes($db, $security))->handle('/api/notifications/resolved/'.$noticeId, 'GET', $legacyLogistics));
$supportFeed = (new NotificationFeed($db))->listing(3, 1, 0, 'all', 'resolved');
check($supportFeed['items'] === [] && $supportFeed['counts']['all'] === 0
    && (new DesktopClientNotifications($db))->arrivals($legacyLogistics, 0)['items'] === [],
    'Eski destek bildirimi müşteri zilinde veya masaüstü bildirimlerinde görünmemeli.');
check($legacyLayoutAccess['storeRole'] === CustomerAccountRoles::LOGISTICS
    && $legacyLayoutAccess['navigationLayout'] === CustomerNavigationLayout::STANDARD, 'Eski lojistik tercihi ortak üst menüyü değiştirmemeli.');
$managerAccess = $policy->access($managerActor);
check($managerAccess['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && $policy->access(['id'=>$directoryManager['id'],'role'=>'client','company_id'=>1])['navigationLayout'] === CustomerNavigationLayout::STANDARD,
    'Mağaza Yetkilisi hesapları her iki oluşturma yolunda ortak üst menüyü kullanmalı.');
check($policy->access($legacy)['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && !isset($policy->access($admin)['navigationLayout']), 'Mevcut müşteri ve Omnigo hesaplarının düzeni korunmalı.');

$layoutSave = ['version'=>$policy->configuration()['version'],'userChanges'=>[]];
expectDenied(fn() => $policy->save($legacyLogistics, [...$layoutSave,'navigationLayouts'=>[3=>'logistics']]));
expectDenied(fn() => $policy->save($admin, [...$layoutSave,'navigationLayouts'=>[1=>'logistics']]));
expectDenied(fn() => $policy->save($admin, [...$layoutSave,'navigationLayouts'=>['03'=>'logistics']]),400);
expectDenied(fn() => $policy->save($admin, [...$layoutSave,'navigationLayouts'=>[3=>'unknown']]),400);
expectDenied(fn() => $policy->save($admin, [...$layoutSave,'navigationLayouts'=>null]),400);
$_GET = ['company'=>'1'];
$managementRoutes = new ManagementRoutes($db, [], $security);
foreach ([$managerActor, [...$legacyLogistics,'name'=>'Örnek Lojistik','operatorName'=>'Örnek Lojistik']] as $client) {
    $id = $client['id'];
    expectDenied(fn() => $managementRoutes->handle('/api/management/permissions','PUT',$client));
    expectDenied(fn() => $policy->save($client, [...$layoutSave,'navigationLayouts'=>[$id=>CustomerNavigationLayout::STANDARD]]));
    $preferences = new UserPreferences($db);
    expectDenied(fn() => $preferences->save($client, [
        'version'=>0, 'preferences'=>[...UserPreferences::defaults(),'navigationLayout'=>CustomerNavigationLayout::STANDARD],
    ]),400);
    expectDenied(fn() => WorkspaceDesign::shared()->save($client, ['mode'=>'classic','version'=>0]));
}
$policy->save($admin, [...$layoutSave,'navigationLayouts'=>(object)[3=>CustomerNavigationLayout::MANAGER,$manager=>CustomerNavigationLayout::LOGISTICS]]);
$policy = new CustomerPermissions($db, 1);
$changedLayoutAccess = $policy->access($legacyLogistics);
check($changedLayoutAccess['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && $changedLayoutAccess['grants'] === $legacyLayoutAccess['grants']
    && $changedLayoutAccess['pages'] === $legacyLayoutAccess['pages'], 'Eski istemci tercih gönderse bile etkin menü ve izinler korunmalı.');
$updatedManager = $policy->access($managerActor);
check($updatedManager['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && $updatedManager['grants'] === $managerAccess['grants']
    && $updatedManager['pages'] === $managerAccess['pages'], 'Eski rol şablonu mağaza yetkilisinin izinlerini değiştirmemeli.');

$user = ['id' => $fromStore, 'role' => 'client', 'company_id' => 1, 'name' => $account['name']];
$dashboardSource = [
    'channels' => [['name'=>'n11','status'=>'active','storeName'=>'Özel','health'=>['error'=>'Özel hata']]],
    'marketplaceSales' => ['currentMonthTurnoverCents'=>2500,'channels'=>[['channel'=>'n11','available'=>true,'periods'=>['today'=>['orders'=>1,'salesCents'=>2300]],'days'=>[['date'=>'2026-11-01','turnoverCents'=>2300,'quantity'=>3]]]]],
    'sales' => [['channel'=>'n11','month'=>'2026-11','turnoverCents'=>2300,'grossCents'=>2300,'orders'=>1]],
    'salesToday' => ['channels'=>[['channel'=>'n11','netCents'=>2300,'orders'=>1]]],
    'summary' => ['expectedCents'=>5000,'turnoverCents'=>2300,'salesTurnoverCents'=>2100,'cancelCents'=>200,'netCents'=>2000,'orders'=>1], 'payments' => [['expectedCents'=>5000]],
];
$dashboard = CustomerDashboardAccess::filter($db, $user, 1, $dashboardSource);
check($dashboard['workspaceAccess']['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && !isset($dashboard['workspaceAccess']['navigationLayouts']), 'Müşteri paneli yalnız oturumdaki hesabın düzenini almalı.');
check($dashboard['payments'] === [] && $dashboard['summary']['expectedCents'] === 0, 'Kısıtlı hesap ödeme verisi almamalı.');
check(!isset($dashboard['channels'][0]['health']) && !isset($dashboard['marketplaceSales']['channels'][0]['periods']), 'Özet dışında bağlantı ve dönem ayrıntıları yanıttan çıkarılmalı.');
check($dashboard['sensitiveVisibility']['turnover'] === false && !isset($dashboard['summary']['turnoverCents'],$dashboard['summary']['netCents']), 'Muhasebe özeti ciro ve net satış tutarı içermemeli.');
check(!array_key_exists('currentMonthTurnoverCents',$dashboard['marketplaceSales']), 'Muhasebe hesabına ayın ciro kartı gönderilmemeli.');
check($dashboard['sales'] === [] && $dashboard['marketplaceSales']['channels'] === [], 'Özet ve satış izni kapalı olan hesap satış verisi almamalı.');
$managerDashboard = CustomerDashboardAccess::filter($db, $managerActor, 1, $dashboardSource);
check($managerDashboard['workspaceAccess']['navigationLayout'] === CustomerNavigationLayout::STANDARD
    && !isset($managerDashboard['workspaceAccess']['navigationLayouts']), 'Mağaza Yetkilisi paneli yalnız kendi menü düzenini almalı.');
foreach ([$admin,$managerActor,$legacy] as $allowedUser) {
    $allowed = CustomerDashboardAccess::filter($db, $allowedUser, 1, $dashboardSource);
    check($allowed['summary']['turnoverCents'] === ($allowedUser['role']==='admin'?2300:2100)
        && $allowed['marketplaceSales']['currentMonthTurnoverCents'] === 2500, 'Ciro erişimi olan hesaplar ayın toplamını almalı.');
}
$salesReport = ['products'=>[['sku'=>'Örnek','orderedQuantity'=>4,'cancelledQuantity'=>1,'netQuantity'=>3,'grossCents'=>2300,'netCents'=>2200]],'totals'=>['orders'=>1,'turnoverCents'=>2300,'turnoverAverageCents'=>2300]];
$protected = CustomerSensitiveVisibility::financial($db, $user, 1, $salesReport, salesAmounts:true);
check($protected['sensitiveVisibility']['turnover'] === false && $protected['totals'] === ['orders'=>1] && $protected['products'] === [['sku'=>'Örnek','orderedQuantity'=>3,'netQuantity'=>3,'quantity'=>3]], 'Ürün listesi iptal sonrası adedi taşır, satış raporu tutarları gizlenir.');
check(CustomerSensitiveVisibility::financial($db, $user, 1, ['grossCents'=>1700])['grossCents'] === 1700, 'Siparişin fatura tutarı satış özeti kısıtına girmemeli.');

$scope = ['all', 'n11', '2026-11'];
$cipher = DeliveryCipher::withKey(str_repeat('r', 32));
(new ShipmentWriter($db, $cipher))->create($admin, 1, $scope, [
    'requestId'=>bin2hex(random_bytes(24)),
    'record'=>[
        'orderedAt'=>'2026-11-04T09:00', 'customerName'=>'Örnek', 'recipientName'=>'Alıcı',
        'province'=>'Ankara', 'deliveryAddress'=>'Örnek adres', 'productName'=>'Ürün',
        'quantity'=>'1', 'customerType'=>'Bireysel',
    ],
]);
$shipment = (int)$db->one('SELECT id FROM shipment_entries')['id'];
$folder = sys_get_temp_dir().'/omnigo-role-documents-'.bin2hex(random_bytes(6));
$documents = new ShipmentDocuments($db, $cipher, $folder);
$image = imagecreatetruecolor(12, 12);
ob_start();
imagejpeg($image);
$bytes = ob_get_clean();
imagedestroy($image);
try {
    $_GET = ['company' => '1'];
    CustomerPermissionRoutes::enforce($db,$security,$user,"/api/shipments/$shipment/documents/invoice",'POST');
    $documents->save($user,1,$shipment,'invoice',$scope,1,bin2hex(random_bytes(24)),$bytes);
    check($documents->load($user,1,$shipment,'invoice',$scope)['mime']==='image/jpeg','Fatura kaydedilebilmeli.');
    expectDenied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$user,"/api/shipments/$shipment/documents/delivery",'POST'),410);
    check($documents->save($user, 1, $shipment, 'invoice', $scope, 2, bin2hex(random_bytes(24)), $bytes)['saved'], 'Yeni muhasebe rolü mevcut faturayı değiştirebilmeli.');

    $config = $policy->configuration();
    $grants = ((array)$config['users'])[$fromStore];
    $grants['shipmentInvoices']['upload'] = false;
    $grants['payments']['view'] = true;
    $wire = (array)json_decode(json_encode([...$config, 'userChanges'=>[$fromStore=>$grants]], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
    $config = $policy->save($admin, $wire);
    expectDenied(fn() => CustomerPermissionRoutes::enforce($db, $security, $user, "/api/shipments/$shipment/documents/invoice", 'POST'));
    $policy = new CustomerPermissions($db, 1);
    check(!$policy->allows($user, 'payments'), 'Özel izin rol sınırını aşmamalı.');
    check(!$policy->allows($user,'cityProducts')&&!isset($config['pages']['shipmentDeliveryDocuments']), 'Şehir Dışı Ürünler ayrı yetki gerektirir ve eski menü kaldırılır.');
    check($policy->allows(['id'=>$fromDirectory['id'],'role'=>'client','company_id'=>1], 'shipmentInvoices', 'upload'), 'Değişiklik yalnızca seçili kullanıcıya uygulanmalı.');
    check($config['newRoleDefaults'][CustomerAccountRoles::LOGISTICS]['shipmentInvoices']['upload']
        && $config['newRoleDefaults']===(new CustomerPermissions($db,2))->configuration()['newRoleDefaults'], 'Ortak rol izinleri kullanıcı ve mağaza değişikliklerinden bağımsız olmalı.');
    $newAccount=CustomerUserProvisioning::create($db,$admin,2,[...$account,'email'=>'lojistik-yeni@example.test','username'=>'lojistik.yeni']);
    check((new CustomerPermissions($db,2))->allows(['id'=>$newAccount,'role'=>'client','company_id'=>2], 'shipmentInvoices', 'upload'), 'Başka mağazada açılan hesap ortak rol izinlerini almalı.');
    $config = $policy->save($admin, [...$config, 'userChanges'=>[$fromStore=>null]]);
    check((new CustomerPermissions($db, 1))->allows($user, 'shipmentInvoices', 'upload'), 'Varsayılana dönüş yükleme iznini rolünden almalı.');
    $batch = (array)json_decode(json_encode([
        'configuration'=>$config, 'selectedPages'=>['deliveries'],
        'targets'=>[['companyId'=>1,'version'=>$config['version'],'defaults'=>false,'users'=>[$fromStore]]],
    ], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
    (new CustomerPermissionBatch($db))->apply($admin, $batch);
    check((new CustomerPermissions($db, 1))->allows($user, 'shipments', 'upload'), 'Tek bölümün toplu düzenlemesi rolün diğer izinlerini korumalı.');
} finally {
    foreach (glob($folder.'/*.bin') ?: [] as $file) unlink($file);
    if (is_dir($folder)) rmdir($folder);
}

$entry = (new AccountDirectory($db))->row($fromDirectory['id']);
$moved = $writer->save($admin, $fromDirectory['id'], [
    ...$account, 'email'=>'dizin@example.test', 'username'=>'dizin.ornek',
    'role'=>'client', 'companyId'=>2, 'active'=>true, 'password'=>'',
    'revision'=>AccountDirectory::revision($entry),
]);
check($moved['storeRole'] === CustomerAccountRoles::LOGISTICS && $moved['companyId'] === 2, 'Mağaza değişiminde rol korunmalı.');
check(!isset(((array)(new CustomerPermissions($db, 1))->configuration()['roles'])[$moved['id']]), 'Önceki mağazadaki rol kaydı temizlenmeli.');
check(!isset((new CustomerPermissions($db, 1))->configuration()['navigationLayouts']->{$moved['id']})
    && (new CustomerPermissions($db, 2))->access(['id'=>$moved['id'],'role'=>'client','company_id'=>2])['navigationLayout'] === CustomerNavigationLayout::STANDARD,
    'Mağaza değişiminde yeni mağaza ortak menüyü almalı ve eski mağazada hesap ayarı kalmamalı.');

$policy = new CustomerPermissions($db, 1);
$config = $policy->configuration();
$roleEdit = static fn(array $base, int $id, string $role, array $changes = []) => (array)json_decode(json_encode([
    ...$base, 'roleChanges'=>[$id=>$role], 'userChanges'=>(object)$changes,
], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
expectDenied(fn() => $policy->save($admin, $roleEdit($config, $moved['id'], CustomerAccountRoles::MANAGER)));
expectDenied(fn() => $policy->save($admin, ['version'=>$config['version'],'userChanges'=>[],'navigationLayouts'=>[$moved['id']=>'standard']]));
expectDenied(fn() => $policy->save($admin, $roleEdit($config, $fromStore, 'admin')), 400);
expectDenied(fn() => $policy->save($user, $roleEdit($config, $fromStore, CustomerAccountRoles::MANAGER)));
$next = $policy->save($admin, $roleEdit($config, $fromStore, CustomerAccountRoles::MANAGER));
$activeClient = (new CustomerPermissions($db, 1))->access($user);
check($activeClient['version'] > $config['version'] && $activeClient['storeRole'] === CustomerAccountRoles::MANAGER, 'Aktif oturuma yeni rol ve erişim sürümü gelmeli.');
check($activeClient['navigationLayout'] === CustomerNavigationLayout::STANDARD, 'Rol değişse de üst menü düzeni ortak kalmalı.');
check($activeClient['grants']['payments']['view'] && !isset($activeClient['overviewSections']), 'Mağaza Yetkilisi olarak mağazanın genel ekranları açılmalı.');
expectDenied(fn() => $policy->save($admin, $roleEdit($config, $fromStore, CustomerAccountRoles::LOGISTICS)), 409);
$clientTabs = (new AccountDirectory($db))->listing($admin, ['role'=>'client']);
$adminTabs = (new AccountDirectory($db))->listing($admin, ['role'=>'admin']);
check(array_unique(array_column($clientTabs['items'], 'role')) === ['client'] && array_unique(array_column($adminTabs['items'], 'role')) === ['admin'], 'Her hesap kendi sekmesinde görünmeli.');
$nextRoleGrants = CustomerAccountRoles::logisticsDefaults();
$nextRoleGrants['shipments']['upload'] = false;
$nextRoleGrants['shipmentInvoices']['upload'] = false;
(new CustomerPermissions($db, 1))->save($admin, $roleEdit($next, $fromStore, CustomerAccountRoles::LOGISTICS, [$fromStore=>$nextRoleGrants]));
$activeClient = (new CustomerPermissions($db, 1))->access($user);
check($activeClient['storeRole'] === CustomerAccountRoles::LOGISTICS && $activeClient['overviewSections'] === ['top','work'], 'İkinci rol değişikliği yeni sekmeleri etkinleştirmeli.');
check($activeClient['navigationLayout'] === CustomerNavigationLayout::STANDARD, 'Lojistik rolüne dönüş eski menü düzenini etkinleştirmemeli.');
check(!$activeClient['grants']['shipments']['upload'] && !$activeClient['grants']['payments']['view'], 'Rol ve özel izin aynı işlemde kaydedilmeli.');
check((new CustomerPermissions($db, 1))->access($legacy)['grants'] === $legacyAccess['grants'], 'Başka kullanıcının yetkileri değişmemeli.');
(new CustomerManagement($db))->updateUser($admin, 1, 2, [
    'name'=>'Eski müşteri','email'=>'eski@example.test','active'=>true,'companyId'=>2,
]);
check(!(new CustomerPermissions($db, 2))->allows([...$legacy,'company_id'=>2], 'shipments', 'upload'), 'Eski hesap yeni mağazanın mevcut varsayılanını almalı.');

$expected=[
    'admin'=>[true,false,false],
    'accounting_logistics'=>[false,true,false],
    'store_manager'=>[false,false,true],
    'admin_logistics'=>[true,true,false],
    'admin_manager'=>[true,false,true],
    'store_roles'=>[false,true,true],
    'all_roles'=>[true,true,true],
    'hidden'=>[false,false,false],
];
foreach($expected as $audience=>[$omnigoVisible,$logisticsVisible,$managerVisible]){
    $policy=new CustomerPermissions($db,1);
    $config=$policy->configuration();
    $grants=((array)$config['users'])[$fromStore];
    $grants['payments']['view']=true;
    $config['pages']['payments']=$audience;
    $saved=$policy->save($admin,[...$config,'userChanges'=>[$fromStore=>$grants]]);
    $effective=new CustomerPermissions($db,1);
    check($saved['pages']['payments']===$audience,'Bölüm seçimi kaydedilmeli.');
    check(($effective->access($admin)['pages']['payments']==='shown')===$omnigoVisible,'Omnigo menüsü rol seçimine uymalı.');
    check($effective->allows($user,'payments')===$logisticsVisible,'Muhasebe & Lojistik yalnızca seçilen bölümü açabilmeli.');
    check($effective->allows($managerActor,'payments')===$managerVisible,'Mağaza Yetkilisi yalnızca seçilen bölümü açabilmeli.');
    check(!$effective->allows($user,'returns'),'Omnigo’ya ayrılmış iade alanı müşteriye açılmamalı.');
}
$policy=new CustomerPermissions($db,1);
$config=$policy->configuration();
$config['pages']['payments']='accounting_logistics';
$grants=((array)$config['users'])[$fromStore];
$grants['payments']['view']=false;
$policy->save($admin,[...$config,'userChanges'=>[$fromStore=>$grants]]);
check(!(new CustomerPermissions($db,1))->allows($user,'payments'),'Rol seçimi kişisel görüntüleme reddini geçmemeli.');
$policy = new CustomerPermissions($db, 1);
$config = $policy->configuration();
$grants = ((array)$config['users'])[$fromStore];
foreach (['analytics','channels'] as $page) {
    $config['pages'][$page] = 'store_roles';
    $grants[$page]['view'] = true;
}
$policy->save($admin, [...$config,'userChanges'=>[$fromStore=>$grants]]);
$_GET = ['company'=>'1'];
CustomerPermissionRoutes::enforce($db, $security, $user, '/api/analytics/report', 'GET');
CustomerPermissionRoutes::enforce($db, $security, $user, '/api/sales/detail', 'GET');
$custom = CustomerDashboardAccess::filter($db, $user, 1, $dashboardSource);
check($custom['salesToday']['channels'][0] === ['channel'=>'n11','orders'=>1]
    && $custom['marketplaceSales']['channels'][0]['periods']['today'] === ['orders'=>1], 'Elle açılan satış ekranları tutar yetkisini genişletmemeli.');
check(CustomerSensitiveVisibility::financial($db, $user, 1, $salesReport, salesAmounts:true) === $protected, 'Rapor açıldığında da ciro kısıtı uygulanmalı.');
$policy = new CustomerPermissions($db, 1);
$config = $policy->configuration();
$changedManagerRole = $policy->save($admin, $roleEdit($config, $manager, CustomerAccountRoles::LOGISTICS));
$policy = new CustomerPermissions($db, 1);
check($policy->access($managerActor)['navigationLayout'] === CustomerNavigationLayout::STANDARD, 'Yeni rolün izinleri aynı menü düzeninde gösterilmeli.');
$policy->save($admin, $roleEdit($changedManagerRole, $manager, CustomerAccountRoles::MANAGER));
check((new CustomerPermissions($db, 1))->access($managerActor)['navigationLayout'] === CustomerNavigationLayout::STANDARD,
    'Mağaza Yetkilisi rolüne dönerken de ortak düzen korunmalı.');
$managerEntry = (new AccountDirectory($db))->row($manager);
$writer->save($admin, $manager, [
    'name'=>$managerEntry['name'], 'email'=>$managerEntry['email'], 'username'=>$managerEntry['username'],
    'role'=>'client', 'companyId'=>2, 'active'=>true, 'revision'=>AccountDirectory::revision($managerEntry),
]);
check(!isset((new CustomerPermissions($db, 1))->configuration()['navigationLayouts']->{$manager})
    && (new CustomerPermissions($db, 2))->access([...$managerActor,'company_id'=>2])['navigationLayout'] === CustomerNavigationLayout::STANDARD,
    'Mağaza değişiminde Mağaza Yetkilisi ortak üst menüyü görmeli.');
echo "Hesap oluşturma, Omnigo menü yetkisi, rol değişimi, mağaza ayrımı, belge yükleme ve sekiz görünürlük seçimi doğrulandı.\n";
