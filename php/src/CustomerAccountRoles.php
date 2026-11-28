<?php
declare(strict_types=1);
namespace Omnigo;

/** Store roles are independent of the Omnigo administrator/client account type. */
final class CustomerAccountRoles
{
    public const MANAGER = 'store_manager';
    public const LOGISTICS = 'accounting_logistics';
    public const STORE_ADMIN = 'store_admin';
    public const LABELS = [self::MANAGER => 'Mağaza Yetkilisi', self::LOGISTICS => 'Muhasebe & Lojistik', self::STORE_ADMIN => 'Yönetici'];
    public const LOGISTICS_SECTIONS = ['summary', 'top'];

    private const LOGISTICS_LEGACY_PAGES = [
        'overview', 'overviewTop', 'overviewWork', 'orders', 'inventory',
        'shipments', 'deliveries', 'cityProducts', 'shipmentInvoices',
    ];

    public static function forCreation(mixed $value): string
    {
        return self::validate($value ?? self::MANAGER);
    }

    public static function validate(mixed $value): string
    {
        if (!is_string($value) || !isset(self::LABELS[$value])) {
            Http::fail(400, 'Geçerli bir mağaza rolü seçin.');
        }
        return $value;
    }

    public static function roles(array $profile): array
    {
        return (array)($profile['workspacePolicy']['roles'] ?? []);
    }

    public static function allowsPage(string $page): bool
    {
        return in_array($page, self::LOGISTICS_LEGACY_PAGES, true);
    }

    public static function logisticsDefaults(): array
    {
        return CustomerStandardPermissions::logistics();
    }

    public static function defaultsFor(?string $role, array $storeDefaults): array
    {
        return $role === self::LOGISTICS ? self::logisticsDefaults() : $storeDefaults;
    }

    public static function newDefaults(array $storeDefaults,bool $useStoreDefaults): array
    {
        return [self::MANAGER=>$useStoreDefaults?$storeDefaults:CustomerStandardPermissions::grants(),
            self::LOGISTICS=>self::logisticsDefaults(),self::STORE_ADMIN=>CustomerStandardPermissions::grants()];
    }

    public static function limitLogistics(array $grants, array $pages): array
    {
        $grants['inventory']['viewMarketplaceStocks']=false;
        foreach (CustomerPermissionCatalog::pages() as $page => $definition) {
            if (!self::allowsPage($page) && !CustomerSectionAudience::allows($page,$pages[$page]??'hidden','client',self::LOGISTICS)) {
                $grants[$page] = array_fill_keys($definition['actions'], false);
            }
        }
        return $grants;
    }

    /** Called under the company's lock together with account creation or assignment. */
    public static function initialize(array $profile, array $config, int $id, ?string $role,bool $newAccount=true): array
    {
        $policy = $profile['workspacePolicy'] ?? [
            'pages' => $config['pages'],
            'defaults' => $config['defaults'],
            'users' => [],
        ];
        $effectiveRole=$role??self::MANAGER;
        $useStore=$effectiveRole===self::MANAGER&&!empty($config['usesStoreDefaultsForNewAccounts'])
            &&empty($config['newRoleDefaultVersions'][$effectiveRole]);
        $roleDefaults=$newAccount?$config['newRoleDefaults']:self::newDefaults($config['defaults'],false);
        $standard=CustomerWorkspaceStandard::enabled($profile);
        $grants = CustomerPermissionCatalog::validateGrants($standard||$role===null||($newAccount&&$useStore)
            ?$config['defaults']:$roleDefaults[$effectiveRole],$effectiveRole);
        // Explicit store opt-in applies only at creation, never to saved personal permissions.
        if(!$standard&&$newAccount&&($profile['overviewLayout']??null)==='tabs'&&($profile['newAccountOverviewTabs']??false)===true){
            foreach(['overview','overviewSales','overviewTop','overviewWork'] as $page)$grants[$page]['view']=true;
        }
        $policy['users'][$id] = InventoryModelPermissions::apply($grants, $config['inventory']['mode']);
        if ($role !== null) $policy['roles'][$id] = $role;
        if ($newAccount) {
            $policy['navigationLayouts'][$id] = CustomerNavigationLayout::forRole($effectiveRole);
        }
        $profile['workspacePolicy'] = $policy;
        return $profile;
    }
}
