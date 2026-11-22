<?php
declare(strict_types=1);
namespace Omnigo;

final class StoreReviewPolicy
{
    public static function protect(array $config, string $path, string $method): void
    {
        if (empty($config['store_review'])) return;
        if ($path === '/api/setup' || str_starts_with($path, '/api/password/') ||
            (str_starts_with($path, '/api/integrations/') && $method !== 'GET') ||
            str_starts_with($path, '/api/inbox/') ||
            ($path === '/api/push/register')) {
            Http::fail(409, 'Örnek inceleme alanında dış hizmet bağlantısı kullanılmaz. Gerçek pazaryeri veya iletişim bilgisi girmeyin.');
        }
    }
}
