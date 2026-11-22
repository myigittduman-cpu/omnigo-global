<?php
declare(strict_types=1);
namespace Omnigo;

/** A push binding may outlive interactive login, but never explicit session revocation. */
final class PushSession
{
    public static function exists(Database $db,array $device,int $user): bool
    {
        return (bool)$db->one('SELECT token FROM sessions WHERE token=? AND user_id=?',[$device['session_token'],$user]);
    }

    public static function prune(Database $db): void
    {
        $now=time();
        // Keep only the revocation anchor of an enabled, unexpired notification device.
        // Security::user still rejects its expired interactive access and CSRF token.
        $db->run('DELETE FROM sessions WHERE expires<=? AND NOT EXISTS (
            SELECT 1 FROM notification_devices d WHERE d.session_token=sessions.token
            AND d.user_id=sessions.user_id AND d.enabled=1 AND d.expires>?
        )',[$now,$now]);
    }
}
