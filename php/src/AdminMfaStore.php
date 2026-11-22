<?php
declare(strict_types=1);
namespace Omnigo;

/** All account changes use the users row lock before accessing this store. */
final class AdminMfaStore
{
    public function __construct(private Database $db,private array $config){}
    public function available(): bool
    {
        // Review data is isolated and must never send codes to real mailboxes.
        return empty($this->config['store_review'])&&($this->config['admin_mfa']??is_file(dirname(__DIR__).'/storage/admin-mfa.enabled'));
    }
    public function enabled(array $user): bool
    {
        if($user['role']!=='admin'||!$this->available())return false;
        return (int)($this->db->one('SELECT enabled FROM admin_mfa_accounts WHERE user_id=?',[$user['id']])['enabled']??0)===1;
    }
    public function assertSession(array $user): void
    {
        if($this->enabled($user)&&!$this->db->one('SELECT token FROM admin_mfa_sessions WHERE token=? AND user_id=? AND expires>?',[$user['token'],$user['id'],time()]))
            Http::fail(401,'İki aşamalı doğrulama için yeniden giriş yapın.');
    }
    public function markSession(string $hash,int $user,int $expires): void
    {
        $this->db->run('DELETE FROM admin_mfa_sessions WHERE expires<=? OR token=?',[time(),$hash]);
        $this->db->run('INSERT INTO admin_mfa_sessions(token,user_id,expires) VALUES(?,?,?)',[$hash,$user,$expires]);
    }
    public static function credentialTag(array $user): string
    {
        return hash('sha256',$user['password'].'|'.$user['email'].'|'.$user['role']);
    }
    public function cancelChallenges(int $user): void
    {
        $this->db->run('DELETE FROM admin_mfa_challenges WHERE user_id=?',[$user]);
        $this->db->run("UPDATE mail_outbox SET state='cancelled',payload=NULL WHERE user_id=? AND id LIKE 'mfa-%' AND state IN ('pending','running')",[$user]);
    }
    public function change(array $user,bool $enabled): void
    {
        $this->db->run('DELETE FROM admin_mfa_accounts WHERE user_id=?',[$user['id']]);
        $this->db->run('INSERT INTO admin_mfa_accounts(user_id,email,enabled,updated) VALUES(?,?,?,?)',[$user['id'],$user['email'],$enabled?1:0,time()]);
        $this->db->run('DELETE FROM admin_mfa_sessions WHERE user_id=?',[$user['id']]);
        $this->cancelChallenges((int)$user['id']);
        // Rotating every session also invalidates pre-enrollment browser sessions.
        $this->db->run('DELETE FROM sessions WHERE user_id=?',[$user['id']]);
        $this->db->run('DELETE FROM password_resets WHERE user_id=?',[$user['id']]);
        $this->db->run('UPDATE notification_devices SET enabled=0 WHERE user_id=?',[$user['id']]);
    }
}
