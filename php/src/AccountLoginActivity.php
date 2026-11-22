<?php
declare(strict_types=1);
namespace Omnigo;

/** Successful authentication is distinct from a device's background connection. */
final class AccountLoginActivity
{
    public const ACTION='Hesaba giriş yapıldı';
    public static function record(Database $db,int $userId):void
    {
        $db->run('INSERT INTO account_audit(actor_id,target_id,action,created) VALUES(?,?,?,?)',[$userId,$userId,self::ACTION,time()]);
    }
}
