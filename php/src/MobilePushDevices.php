<?php
declare(strict_types=1);
namespace Omnigo;

final class MobilePushDevices
{
    public function __construct(private Database $db,private DeliveryCipher $cipher) {}
    public function save(array $user,array $data): array
    {
        $registration=[...MobilePushRegistration::validate($data,$user),'localeProfile'=>NotificationLanguage::profile($user)];
        $key=hash('sha256',$registration['kind'].':'.$registration['appId'].':'.$registration['installationId']);
        return $this->db->transaction(function() use($user,$registration,$key) {
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
            $row=$this->db->one('SELECT * FROM notification_devices WHERE device_key=?'.$this->db->lock(),[$key]);
            if (!$row && (int)$this->db->one('SELECT COUNT(*) AS n FROM notification_devices WHERE user_id=? AND enabled=1',[$user['id']])['n']>=20) Http::fail(429,'Bildirim cihazı sınırına ulaşıldı.');
            $payload=$this->cipher->seal((int)$user['id'],'push:'.$key,$registration);
            // A same-token refresh preserves pending jobs; owner/token/session changes invalidate old jobs.
            $changed=true;
            if ($row && (int)$row['user_id']===(int)$user['id'] && $row['enabled'] && $row['session_token']===$user['token']) {
                $previous=$this->cipher->open((int)$user['id'],'push:'.$key,$row['payload']);
                $changed=$previous['token']!==$registration['token'] || $previous['environment']!==$registration['environment']
                    || !hash_equals($row['credential_tag'],MobilePushRegistration::tag($user));
            }
            $refreshToken=$changed?bin2hex(random_bytes(32)):null;
            $refreshHash=$refreshToken?hash('sha256',$refreshToken):$row['token_hash'];
            $values=[$user['id'],$registration['kind'],$payload,MobilePushRegistration::tag($user),$user['token'],(int)$registration['sound'],time()+90*86400,time()];
            if ($row) {
                $this->db->run('UPDATE notification_devices SET user_id=?,kind=?,payload=?,credential_tag=?,session_token=?,sound=?,expires=?,last_seen=?,enabled=1,generation=generation+?,token_hash=? WHERE id=?',[...$values,(int)$changed,$refreshHash,$row['id']]);
                $id=(int)$row['id'];
            } else {
                $this->db->run('INSERT INTO notification_devices(user_id,kind,payload,credential_tag,session_token,sound,expires,last_seen,device_key,token_hash) VALUES(?,?,?,?,?,?,?,?,?,?)',[...$values,$key,$refreshHash]);
                $id=$this->db->insertId();
            }
            return ['id'=>$id,'enabled'=>true,'sound'=>$registration['sound'],'refreshToken'=>$refreshToken];
        });
    }
    public function usable(array $device,array $user): bool
    {
        return in_array($user['role'],['admin','client'],true)
            && hash_equals($device['credential_tag'],MobilePushRegistration::tag($user))
            && PushSession::exists($this->db,$device,(int)$user['id']);
    }
    public function recipient(array $device,?array $event): bool
    {
        $user=$this->db->one('SELECT id,role,company_id FROM users WHERE id=?',[$device['user_id']]);
        return $user&&NotificationAudience::permits($user,$event)&&CustomerNotificationPolicy::recipient($this->db,$user,$event);
    }
    public function subscription(array $device): array
    {
        $user=$this->db->one('SELECT * FROM users WHERE id=?',[$device['user_id']]);
        if (!$user) Http::fail(401,'Bildirim hesabı bulunamadı.');
        return MobilePushRegistration::validate($this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']),$user);
    }
    public function refresh(string $secret,array $data,PushDevices $devices): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret))Http::fail(401,'Cihaz erişimi sona erdi.');
        $this->db->transaction(function()use($secret,$data,$devices){
            $device=$this->db->one("SELECT * FROM notification_devices WHERE token_hash=? AND kind='android'".$this->db->lock(),[hash('sha256',$secret)]);
            if(!$device||!$devices->usable($device))Http::fail(401,'Cihaz erişimi sona erdi.');
            $registration=$this->subscription($device);
            $user=$this->db->one('SELECT * FROM users WHERE id=?',[$device['user_id']]);
            $metadata=$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']);
            $registration=[...MobilePushRegistration::validate([...$registration,'token'=>$data['token']??''],$user),'localeProfile'=>$metadata['localeProfile']??null];
            $this->db->run('UPDATE notification_devices SET payload=?,last_seen=? WHERE id=?',[
                $this->cipher->seal((int)$device['user_id'],'push:'.$device['device_key'],$registration),time(),$device['id']]);
        });
    }
}
