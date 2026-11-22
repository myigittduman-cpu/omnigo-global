<?php
declare(strict_types=1);
namespace Omnigo\Operations;
use Omnigo\{Database,Security};

/** Remove login identities while retaining the actors referenced by business history. */
final class CustomerAccountRetirement
{
    public static function retire(Database $db,array $ids,int $actor):array
    {
        if(!$db->pdo->inTransaction())throw new \LogicException('İşlem yedekli transaction içinde yapılmalı.');
        $result=[];
        foreach($ids as $id){
            $user=$db->one("SELECT * FROM users WHERE id=? AND role='client' AND company_id IS NOT NULL".$db->lock(),[$id]);
            if(!$user)throw new \RuntimeException('Beklenen müşteri hesabı değişmiş.');
            $tokens=array_column($db->all('SELECT token FROM sessions WHERE user_id=?',[$id]),'token');
            foreach($tokens as $token)$db->run('DELETE FROM client_session_operators WHERE session_token=?',[$token]);
            $db->run('DELETE FROM push_deliveries WHERE device_id IN (SELECT id FROM notification_devices WHERE user_id=?)',[$id]);
            foreach(['sessions','password_resets','password_change_challenges','mail_outbox','notification_states','notification_devices','user_login_names','user_login_history','user_preferences'] as $table)$db->run("DELETE FROM `$table` WHERE user_id=?",[$id]);
            $db->run('DELETE FROM account_controls WHERE user_id=?',[$id]);$db->run('INSERT INTO account_controls(user_id,active) VALUES(?,0)',[$id]);
            // Original name stays solely for historical audit/support authorship.
            $db->run('UPDATE users SET email=?,password=? WHERE id=? AND role=\'client\'',['removed-'.$id.'-'.bin2hex(random_bytes(8)).'@accounts.invalid',Security::hashPassword(bin2hex(random_bytes(48))),$id]);
            $db->run('DELETE FROM account_profiles WHERE user_id=?',[$id]);$db->run('INSERT INTO account_profiles(user_id,deleted_at) VALUES(?,?)',[$id,time()]);
            $company=(int)$user['company_id'];$row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
            if($row){$profile=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);unset($profile['workspacePolicy']['users'][(string)$id]);$profile['initialPasswordSequence']=0;$db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$company]);}
            $db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$actor,$company,'Müşteri giriş hesabı kaldırıldı; geçmiş işlem kimliği korundu',$id,gmdate('c')]);
            $result[]=['id'=>$id,'companyId'=>$company,'sessionsRemaining'=>(int)$db->one('SELECT COUNT(*) AS n FROM sessions WHERE user_id=?',[$id])['n']];
        }
        return $result;
    }
}
