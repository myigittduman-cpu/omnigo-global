<?php
declare(strict_types=1);
namespace Omnigo;

final class PortalAccess
{
    public static function configure(array $config, string $host): array
    {
        if (!isset($config['portals'])) return $config;
        foreach ($config['portals'] as $role => $origin) {
            $url = parse_url($origin);
            $expected = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
            if ($host === $expected && in_array($role, ['admin', 'client'], true)) {
                $config['origin'] = $origin;
                $config['portal_role'] = $role;
                return $config;
            }
        }
        Http::fail(403, 'Adres doğrulanamadı.');
    }

    public static function assertRole(array $config, string $role, int $status = 403): void
    {
        if (isset($config['portal_role']) && $config['portal_role'] !== $role) {
            Http::fail($status, 'Bu hesap bu giriş alanına ait değil. Hesabınıza uygun adresi kullanın.');
        }
    }

    public static function cookieName(array $config): string
    {
        // Host-only cookies and role-specific names keep the two sessions independent.
        $prefix = !empty($config['store_review']) ? 'og_review_' : 'og_';
        return isset($config['portal_role']) ? $prefix.$config['portal_role'].'_session' : $prefix.'portal_session';
    }
}
