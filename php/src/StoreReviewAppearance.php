<?php
declare(strict_types=1);
namespace Omnigo;

/** Adds current appearance preferences to the isolated, older review backend only. */
final class StoreReviewAppearance
{
    public static function dispatch(Database $db, array $config, string $path, string $method): void
    {
        if (!in_array($path, ['/api/account/preferences', '/api/session/operator'], true)) return;
        if (($config['store_review'] ?? false) !== true || $db->mysql()) {
            Http::fail(503, 'Bu işlem yalnız örnek inceleme alanında kullanılabilir.');
        }
        Http::protect($config);
        StoreReviewPolicy::protect($config, $path, $method);
        $security = new Security($db, $config);
        $user = $security->user();
        if ($method !== 'GET') $security->csrf($user);
        $allowed = $path === '/api/session/operator' ? ['POST'] : ['GET', 'PUT'];
        if (!in_array($method, $allowed, true)) Http::fail(405, 'Bu işlem yöntemi desteklenmiyor.');

        // The entry point has already verified the exact isolated SQLite path.
        ClientSessionOperator::install($db);
        if ($path === '/api/session/operator') {
            Http::json((new ClientSessionOperator($db))->start($user, Http::body()['name'] ?? null));
        }
        $user = (new ClientSessionOperator($db))->resolve($user, $_SERVER['HTTP_X_OMNIGO_OPERATOR'] ?? null);
        ClientSessionOperator::requireName($user);
        $db->run('CREATE TABLE IF NOT EXISTS user_preferences (user_id BIGINT NOT NULL, profile_key CHAR(64) NOT NULL, payload TEXT NOT NULL, version INT NOT NULL, updated BIGINT NOT NULL, PRIMARY KEY(user_id,profile_key))');
        $preferences = new UserPreferences($db);
        Http::json($method === 'GET' ? $preferences->read($user) : $preferences->save($user, Http::body()));
    }
}
