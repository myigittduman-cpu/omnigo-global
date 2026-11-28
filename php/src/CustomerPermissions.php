<?php
declare(strict_types=1);
namespace Omnigo;

/** Tenant and user access policy. It never creates, stops or edits integration jobs. */
final class CustomerPermissions
{
    private array $profile;
    private int $version;
    private bool $cityProductsAvailable;
    public function __construct(private Database $db,private int $company)
    {
        $row=$db->one('SELECT c.name,p.payload,p.version FROM companies c LEFT JOIN company_profiles p ON p.company_id=c.id WHERE c.id=?',[$company]);
        $this->profile=json_decode($row['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        $storeName=str_replace('İ','i',trim((string)($row['name']??'')));
        $this->cityProductsAvailable=in_array(mb_strtolower($storeName,'UTF-8'),
            ['vizyona','vizyona beyaz eşya','vizyon beyaz eşya','vizyon beyaz eşya mobilya oto. ve kiralama paz. tic. ltd. şti.'],true);
        $this->version=(int)($row['version']??0);
    }
    public function configuration(bool $includeNewRoleDefaults=true): array
    {
        $policy=$this->profile['workspacePolicy']??[];
        $roles=CustomerAccountRoles::roles($this->profile);
        $policy['defaults']['inventory']=CustomerPermissionCatalog::legacyInventoryActions((array)($policy['defaults']['inventory']??[]));
        foreach((array)($policy['users']??[]) as $id=>$grants)$policy['users'][$id]['inventory']=CustomerPermissionCatalog::legacyInventoryActions((array)($grants['inventory']??[]));
        $policy['defaults']['shipments']=CustomerPermissionCatalog::legacyShipmentActions((array)($policy['defaults']['shipments']??[]));
        foreach((array)($policy['users']??[]) as $id=>$grants)$policy['users'][$id]['shipments']=CustomerPermissionCatalog::legacyShipmentActions((array)($grants['shipments']??[]));
        $defaultGrants=array_replace_recursive(CustomerPermissionCatalog::defaults(),$policy['defaults']??[]);
        $defaultGrants=CustomerPermissionCatalog::completePages($defaultGrants,$policy['defaults']??[]);
        if(!isset($policy['defaults']['deliveries']['selectDocuments']))$defaultGrants['deliveries']['selectDocuments']=$defaultGrants['deliveries']['export'];
        $userGrants=[];
        foreach((array)($policy['users']??[]) as $id=>$grants){
            $merged=array_replace_recursive(CustomerPermissionCatalog::defaults(),$grants);
            $userGrants[$id]=CustomerPermissionCatalog::completePages($merged,$grants,$roles[$id]??null);
        }
        foreach((array)($policy['users']??[]) as $id=>$grants)if(!isset($grants['deliveries']['selectDocuments']))$userGrants[$id]['deliveries']['selectDocuments']=$userGrants[$id]['deliveries']['export'];
        $pages=array_fill_keys(array_keys(CustomerPermissionCatalog::pages()),'shown');
        $pages['inventory']='hidden';
        $pages['fileCenter']=$pages['financeProfitability']='admin';
        foreach(CustomerModules::DEFAULTS as $page=>$default)if(($this->profile['modules'][$page]??$default)===false)$pages[$page]='removed';
        $pages=array_replace($pages,array_intersect_key($policy['pages']??[],$pages));
        foreach(CustomerPermissionCatalog::PAGE_PARENTS as $page=>$parent){
            if(!isset($policy['pages'][$page]))$pages[$page]=$pages[$parent];
        }
        if(!$this->cityProductsAvailable)$pages['cityProducts']='removed';
        $selection=CustomerSetupSelection::stored($this->profile);
        $restrictLegacyExport=static function(array &$grants,array $explicit,?string $role)use($pages,$selection): void{
            if(array_key_exists('export',(array)($explicit['cityProducts']??[])))return;
            foreach(['deliveries','cityProducts'] as $page){
                if(!CustomerSectionAudience::allows($page,$pages[$page],'client',$role)
                    || ($selection!==null&&!CustomerSetupSelection::pageEnabled($selection,$page))){
                    $grants['cityProducts']['export']=false;
                }
            }
        };
        $restrictLegacyExport($defaultGrants,$policy['defaults']??[],null);
        foreach($userGrants as $id=>&$grants){
            if(!CustomerWorkspaceStandard::enabled($this->profile)&&($roles[$id]??null)===CustomerAccountRoles::LOGISTICS)$grants=CustomerAccountRoles::limitLogistics($grants,$pages);
            $restrictLegacyExport($grants,$policy['users'][$id],$roles[$id]??null);
        }
        unset($grants);
        return ['version'=>$this->version,'inventory'=>InventorySettings::validate($policy['inventory']??[]),'inventoryManagement'=>(new MarketplaceStockPolicy($this->db))->option($this->company),'notifications'=>array_replace(CustomerNotificationPolicy::DEFAULTS,$policy['notifications']??[]),'cities'=>CustomerCities::fromProfile($this->profile),'pages'=>$pages,'defaults'=>$defaultGrants,'users'=>(object)$userGrants,'roles'=>(object)$roles,'navigationLayouts'=>(object)CustomerNavigationLayout::stored($policy),'roleDefaults'=>CustomerWorkspaceStandard::enabled($this->profile)?array_fill_keys(array_keys(CustomerAccountRoles::LABELS),$defaultGrants):[CustomerAccountRoles::LOGISTICS=>CustomerAccountRoles::logisticsDefaults()],
            ...($includeNewRoleDefaults?$this->newRoleConfiguration($defaultGrants):[]),
            'usesStoreDefaultsForNewAccounts'=>!empty($policy['useStoreDefaultsForNewAccounts']),
            'setupOptions'=>$selection,'cityProductsAvailable'=>$this->cityProductsAvailable];
    }

    private function newRoleConfiguration(array $defaults): array
    {
        if(CustomerWorkspaceStandard::enabled($this->profile))return [
            'newRoleDefaults'=>array_fill_keys(array_keys(CustomerAccountRoles::LABELS),$defaults),
            'newRoleDefaultVersions'=>array_fill_keys(array_keys(CustomerAccountRoles::LABELS),$this->version),
        ];
        $roles=(new CustomerRoleDefaults($this->db))->roles();
        return ['newRoleDefaults'=>array_map(static fn(array $role):array=>$role['grants'],$roles),
            'newRoleDefaultVersions'=>array_map(static fn(array $role):int=>$role['version'],$roles)];
    }
    public static function userDefaults(array $config,int $id):array
    {
        return $config['roleDefaults'][((array)$config['roles'])[$id]??'']??$config['defaults'];
    }
    public function access(array $user): array
    {
        if(($user['role']??'')!=='admin'&&(int)($user['company_id']??0)!==$this->company)Http::fail(403,'Bu müşteriye erişemezsiniz.');
        $config=$this->configuration(false);$overrides=(array)$config['users'];$id=(int)($user['id']??0);
        $storeRole=($user['role']??'')==='client'?(((array)$config['roles'])[$id]??null):null;
        $standard=CustomerWorkspaceStandard::enabled($this->profile);
        $logistics=$storeRole===CustomerAccountRoles::LOGISTICS&&!$standard;
        $grants=$overrides[$id]??self::userDefaults($config,$id);
        if(($user['role']??'')!=='admin')$grants=InventoryModelPermissions::apply($grants,$config['inventory']['mode']);
        if(($user['role']??'')==='admin')foreach(CustomerPermissionCatalog::pages() as $page=>$definition)$grants[$page]=array_fill_keys($definition['actions'],true);
        $pages=$config['pages'];
        foreach($pages as $page=>$audience){
            $pages[$page]=CustomerSectionAudience::allows($page,$audience,$user['role']??'client',$standard?null:$storeRole)?'shown':'hidden';
        }
        foreach($grants as $page=>&$actions)if(($pages[$page]??'hidden')!=='shown')$actions=array_fill_keys(array_keys($actions),false);
        unset($actions);
        // Role boundaries override legacy defaults and per-user grants without rewriting profiles.
        if(($user['role']??'')!=='admin')foreach(CustomerPermissionCatalog::pages() as $page=>$definition){
            if(empty($definition['adminOnly']))continue;
            $pages[$page]='hidden';$grants[$page]=array_fill_keys($definition['actions'],false);
        }
        if($config['setupOptions']!==null)CustomerSetupSelection::restrict($config['setupOptions'],$pages,$grants);
        if($logistics)$grants['inventory']['viewMarketplaceStocks']=false;
        if(($user['role']??'')!=='admin'){
            $returns=CustomerTableVisibility::fromAccess(compact('pages','grants'),'returns');
            if(!$returns['cancellations']&&!$returns['returns']){
                $pages['returns']='hidden';
                $grants['returns']=array_fill_keys(array_keys($grants['returns']),false);
            }
        }
        return ['version'=>$this->version,'pages'=>$pages,'grants'=>$grants,'notifications'=>$config['notifications'],
            ...($config['setupOptions']===null?[]:['setupOptions'=>$config['setupOptions']]),
            ...($user['role']==='client'?[
                'navigationLayout'=>CustomerNavigationLayout::effective($config['navigationLayouts']->{$id}??null,$storeRole),
                'overviewLayout'=>($this->profile['overviewLayout']??null)==='tabs'?'tabs':'separate',
                ...($standard?['interfacePreset'=>CustomerWorkspaceStandard::PRESET]:[]),
            ]:[]),
            ...($storeRole!==null?['storeRole'=>$storeRole]:[]),
            ...($logistics?['overviewSections'=>array_keys(array_filter([
                'summary'=>$grants['overview']['view'],'sales'=>$grants['overviewSales']['view'],
                'top'=>$grants['overviewTop']['view'],'work'=>$grants['overviewWork']['view'],
            ]))]:[])];
    }
    public function archivedDeliveryFlags(array $user): array
    {
        ShipmentAccess::company($user,$this->company);
        $flags=ArchivedShipmentDelivery::flags($this->profile,$user);
        if($flags===null)Http::fail(403,'Bu belgeyi görüntüleme yetkiniz bulunmuyor.');
        return $flags;
    }
    public function allows(array $user,string $page,string $action='view'): bool
    {
        if($page==='shipmentDeliveryDocuments')return false;
        if(($user['role']??'')==='admin'&&$page!=='cityProducts')return true;
        $access=$this->access($user);
        return !empty($access['grants'][$page]['view'])&&!empty($access['grants'][$page][$action]);
    }
    public function require(array $user,string $page,string $action='view'): void
    {
        if(!$this->allows($user,$page,$action))Http::fail(403,'Bu bölüm veya işlem için yetkiniz bulunmuyor. Yöneticinizle görüşün.');
    }
    private function requireCustomerAccount(int|string $id):void
    {
        if(!ctype_digit((string)$id)||!$this->db->one("SELECT id FROM users WHERE id=? AND company_id=? AND role='client'",[(int)$id,$this->company])){
            Http::fail(403,'Kullanıcı seçili müşteriye ait değil.');
        }
    }
    public function save(array $actor,array $data): array
    {
        Security::admin($actor);
        $previous=$this->configuration(false);
        $selection=isset($data['setupOptions'])?CustomerSetupSelection::validate($data['setupOptions'],$previous['setupOptions']):null;
        $pages=(array)($data['pages']??$previous['pages']);
        unset($pages['shipmentDeliveryDocuments']);
        $pages['cityProducts']??=$previous['pages']['cityProducts'];
        $pages+=array_intersect_key($previous['pages'],array_flip(['fileCenter','financeProfitability']));
        if(!array_key_exists('visibility',$pages))$pages['visibility']='shown';
        if(!array_key_exists('inventory',$pages))$pages['inventory']=$previous['pages']['inventory'];
        $pages+=array_intersect_key($previous['pages'],CustomerPermissionCatalog::PAGE_PARENTS);
        CustomerSectionAudience::validatePages($pages);
        $cities=CustomerCities::validate($data['cities']??CustomerCities::fromProfile($this->profile));
        $rawDefaults=$data['defaults']??$previous['defaults'];
        if(is_array($rawDefaults)||$rawDefaults instanceof \stdClass){
            $rawDefaults=(array)$rawDefaults+array_intersect_key($previous['defaults'],CustomerPermissionCatalog::PAGE_PARENTS);
            $rawDefaults=CustomerTableVisibility::preserveOmitted($rawDefaults,$previous['defaults']);
        }
        $defaults=CustomerPermissionCatalog::validateGrants($rawDefaults);
        $currentRoles=CustomerAccountRoles::roles($this->profile);
        $requestedRoles=$data['roleChanges']??[];
        if(!is_array($requestedRoles)&&!($requestedRoles instanceof \stdClass))Http::fail(400,'Kullanıcı rollerini kontrol edin.');
        $roleChanges=[];
        foreach((array)$requestedRoles as $id=>$value){
            $this->requireCustomerAccount($id);
            $role=CustomerAccountRoles::validate($value);
            if(($currentRoles[$id]??null)!==$role)$roleChanges[$id]=$role;
        }
        $roles=array_replace($currentRoles,$roleChanges);
        $navigationLayouts=$this->navigationLayoutChanges($data);
        $roleResets=$this->roleResetIds($data['roleResets']??[]);
        $users=[];
        if(!is_array($data['userChanges']??null)&&!(($data['userChanges']??null) instanceof \stdClass))Http::fail(400,'Kullanıcı yetkilerini kontrol edin.');
        foreach((array)$data['userChanges'] as $id=>$grants){
            $this->requireCustomerAccount($id);
            if(!isset($roleChanges[$id])&&(is_array($grants)||$grants instanceof \stdClass)){
                $known=((array)$previous['users'])[$id]??self::userDefaults($previous,(int)$id);
                $grants=(array)$grants+array_intersect_key($known,CustomerPermissionCatalog::PAGE_PARENTS);
                $grants=CustomerTableVisibility::preserveOmitted($grants,$known);
            }
            $users[$id]=$grants===null?null:CustomerPermissionCatalog::validateGrants($grants,$roles[$id]??null);
            if(!CustomerWorkspaceStandard::enabled($this->profile)&&$users[$id]!==null&&($roles[$id]??null)===CustomerAccountRoles::LOGISTICS){
                $users[$id]=CustomerAccountRoles::limitLogistics($users[$id],$pages);
            }
        }
        $useStoreDefaults=$data['applyDefaultsToNewAccounts']??($previous['usesStoreDefaultsForNewAccounts']||$defaults!=$previous['defaults']);
        if(!is_bool($useStoreDefaults))Http::fail(400,'Yeni hesapların varsayılan yetki seçimini kontrol edin.');
        $newRoleDefaults=CustomerWorkspaceStandard::enabled($this->profile)?array_fill_keys(array_keys(CustomerAccountRoles::LABELS),$defaults):CustomerAccountRoles::newDefaults($defaults,false);
        foreach($roleChanges as $id=>$role){
            if(!array_key_exists($id,$users))$users[$id]=$newRoleDefaults[$role];
        }
        $previousNotifications=$previous['notifications'];
        $requestedNotifications=$data['notifications']??$previousNotifications;
        if(is_array($requestedNotifications)||$requestedNotifications instanceof \stdClass){
            $requestedNotifications=(array)$requestedNotifications+array_intersect_key($previousNotifications,
                CustomerOrderNotifications::DEFAULTS+CustomerNotificationPolicy::DETAIL_DEFAULTS);
        }
        $notifications=CustomerNotificationPolicy::validate($requestedNotifications);
        $policy=['inventory'=>InventorySettings::validate($data['inventory']??$previous['inventory']),'notifications'=>$notifications,'pages'=>$pages,'defaults'=>$defaults,'users'=>(object)$users];
        if($useStoreDefaults)$policy['useStoreDefaultsForNewAccounts']=true;
        (new MarketplaceStockPolicy($this->db))->validate($this->company,$policy['inventory']);
        $policy['defaults']=InventoryModelPermissions::apply($policy['defaults'],$policy['inventory']['mode']);
        foreach($users as $id=>$grants)if($grants!==null)$users[$id]=InventoryModelPermissions::apply($grants,$policy['inventory']['mode']);
        $policy['users']=(object)$users;
        $write=function()use($actor,$data,$policy,$cities,$roles,$previous,$navigationLayouts,$selection,$roleResets){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$this->company]);
            $row=$this->db->one('SELECT payload,version FROM company_profiles WHERE company_id=?',[$this->company]);
            if(($data['version']??null)!==(int)($row['version']??0))Http::fail(409,'Müşteri ayarları değişti. Pencereyi yeniden açıp kontrol edin.');
            $profile=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            $before=$profile['workspacePolicy']??null;
            if(($before['inventory']['mode']??'marketplace')!==$policy['inventory']['mode']&&$this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND state='pending'",[$this->company]))Http::fail(409,'Bekleyen stok işlemleri tamamlandıktan sonra stok modelini değiştirin.');
            $merged=(array)($before['users']??[]);
            foreach((array)$policy['users'] as $id=>$grants){if($grants===null)unset($merged[$id]);else $merged[$id]=$grants;}
            if($roleResets){
                $presets=(new CustomerRoleDefaults($this->db))->roles();
                foreach($roleResets as $id){
                    $this->requireCustomerAccount($id);
                    $role=$roles[$id]??CustomerAccountRoles::MANAGER;
                    $grants=CustomerWorkspaceStandard::enabled($profile)?$policy['defaults']:$presets[$role]['grants'];
                    if(!CustomerWorkspaceStandard::enabled($profile)&&$role===CustomerAccountRoles::LOGISTICS)$grants=CustomerAccountRoles::limitLogistics($grants,$policy['pages']);
                    $merged[$id]=InventoryModelPermissions::apply($grants,$policy['inventory']['mode']);
                }
            }
            $policy['users']=(object)$merged;
            if($roles||isset($before['roles']))$policy['roles']=(object)$roles;
            if($navigationLayouts||isset($before['navigationLayouts'])){
                $savedLayouts=CustomerNavigationLayout::stored($before??[]);
                foreach($navigationLayouts as $id=>$layout){
                    $this->requireCustomerAccount($id);
                    if($layout===CustomerNavigationLayout::STANDARD){
                        unset($savedLayouts[$id]);
                        continue;
                    }
                    // Older settings callers can send the complete map, including dormant choices.
                    if(($savedLayouts[$id]??null)===$layout)continue;
                    if($layout!==CustomerNavigationLayout::forRole($roles[$id]??null)){
                        Http::fail(400,'Bu üst menü düzeni kullanıcının mağaza rolüne uygun değil.');
                    }
                    $savedLayouts[$id]=$layout;
                }
                $policy['navigationLayouts']=(object)$savedLayouts;
            }
            $beforeProfile=$profile;
            if($cities!==$previous['cities'])$profile=CustomerCities::update($profile,$cities);
            ArchivedShipmentDelivery::snapshot($this->db,$this->company,$profile);
            $profile['workspacePolicy']=$policy;
            if($selection!==null&&$selection!==$previous['setupOptions']){
                $profile['setupOptions']=$selection;
                $profile['cityProductsConfigured']=true;
                $profile['modules']=CustomerSetupSelection::operationalModules($selection);
            }
            // Enabling a section is explicit. Hiding/removing its menu never stops existing imports.
            foreach([...array_keys(CustomerModules::DEFAULTS),...array_values(CustomerPermissionCatalog::DOCUMENT_PAGES)] as $page){
                if($selection!==null||isset($profile['setupOptions']))break;
                if($previous['pages'][$page]===$policy['pages'][$page]||!CustomerSectionAudience::enabled($policy['pages'][$page]))continue;
                $module=CustomerPermissionCatalog::PAGE_PARENTS[$page]??$page;
                $profile['modules'][$module]=true;
            }
            $profile['notificationResume']=NotificationResumePolicy::update($this->db,$this->company,$beforeProfile,$profile);
            $payload=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($row)$this->db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$this->company]);
            else $this->db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$this->company,$payload]);
            if($previous['inventory']!==$policy['inventory']||$previous['pages']['inventory']!==$policy['pages']['inventory']
                ||($selection!==null&&$selection!==$previous['setupOptions'])){
                (new InventorySettings($this->db))->configure($this->company,$policy);
            }
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$actor['id'],$this->company,'Müşteri bölümleri ve kullanıcı yetkileri güncellendi',$this->company,json_encode($before,JSON_UNESCAPED_UNICODE),json_encode($policy,JSON_UNESCAPED_UNICODE),gmdate('c')]);
        };
        if($this->db->pdo->inTransaction())$write();else TransactionRetry::run($this->db,$write);
        return (new self($this->db,$this->company))->configuration();
    }

    private function roleResetIds(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values))Http::fail(400,'Varsayılan yetkisine dönecek kullanıcıları kontrol edin.');
        $ids=[];
        foreach($values as $value){
            if((!is_int($value)&&!is_string($value))||(int)$value<1||(string)(int)$value!==(string)$value){
                Http::fail(400,'Varsayılan yetkisine dönecek kullanıcıları kontrol edin.');
            }
            $ids[(int)$value]=(int)$value;
        }
        return $ids;
    }

    private function navigationLayoutChanges(array $data):array
    {
        if(!array_key_exists('navigationLayouts',$data))return [];
        $changes=$data['navigationLayouts'];
        if(!is_array($changes)&&!($changes instanceof \stdClass))Http::fail(400,'Kullanıcı menü düzenlerini kontrol edin.');
        $layouts=[];
        foreach((array)$changes as $id=>$layout){
            if((int)$id<1||(string)(int)$id!==(string)$id)Http::fail(400,'Kullanıcı menü düzenlerini kontrol edin.');
            $layouts[$id]=CustomerNavigationLayout::validate($layout);
        }
        return $layouts;
    }
}
