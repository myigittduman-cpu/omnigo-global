<?php
declare(strict_types=1);
namespace Omnigo;

final class MailQueue
{
    public function __construct(private Database $db,private array $config) {}
    public function ready(): bool
    {
        return ($this->config['mail']['enabled']??false)===true
            && filter_var($this->config['mail']['from']??'',FILTER_VALIDATE_EMAIL)!==false
            && strlen(base64_decode($this->config['mail']['key']??'',true)?:'')===32
            && (new MailTransport($this->config['mail']))->ready();
    }
    private function key(): string { return base64_decode($this->config['mail']['key'],true); }
    public function encrypt(array $payload): string
    {
        $iv=random_bytes(12); $tag='';
        $encrypted=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag);
        if ($encrypted===false) throw new \RuntimeException('Posta şifrelenemedi.');
        return base64_encode($iv.$tag.$encrypted);
    }
    public function enqueueIntegrationAlert(int $event,string $company): bool
    {
        // Acknowledge the monitor's backlog without removing permanent inbox events.
        return true;
    }
    public function enqueueOperation(string $key,array $detail): bool
    {
        if(!$this->ready())return false;
        if(!preg_match('/^operation-[1-9][0-9]*$/D',$key))throw new \InvalidArgumentException('Bildirim kimliği geçersiz.');
        $admin=$this->db->one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");if(!$admin)return false;
        return $this->insertActivity($key,$detail,(int)$admin['id']);
    }
    public function enqueueUserActivity(string $key,array $detail): bool
    {
        if(!$this->ready())return false;
        if(!preg_match('/^user-notification-[1-9][0-9]*$/D',$key))throw new \InvalidArgumentException('Bildirim kimliği geçersiz.');
        $admin=$this->db->one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
        return $admin?$this->insertActivity($key,$detail,(int)$admin['id']):false;
    }
    private function insertActivity(string $key,array $detail,int $admin): bool
    {
        $envelope=['type'=>'operation','to'=>'admin@omnigoglobal.com','detail'=>$detail];
        $allowed=NotificationMailPolicy::allows($envelope,$this->db);
        if($allowed)OperationalMailMessage::format($detail);
        $payload=$allowed?$this->encrypt($envelope):null;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->db->run("$insert INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,?,?,?)",[$key,$admin,$payload,$allowed?'pending':'cancelled',time(),gmdate('c')]);return true;
    }
    public function runOne(?callable $transport=null): bool
    {
        if (!$this->ready()) return false;
        $job=$this->db->transaction(function() {
            $row=$this->db->one("SELECT * FROM mail_outbox WHERE (state='pending' AND next_run<=?) OR (state='running' AND lease_until<?) ORDER BY next_run LIMIT 1".$this->db->lock(),[time(),time()]);
            if (!$row) return null;
            $row['lease_token']=bin2hex(random_bytes(32));
            $this->db->run("UPDATE mail_outbox SET state='running',attempts=attempts+1,lease_until=?,lease_token=? WHERE id=?",[time()+120,$row['lease_token'],$row['id']]);
            return $row;
        });
        if (!$job) return false;
        try {
            $raw=base64_decode($job['payload'],true);
            $json=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
            if ($json===false) throw new \RuntimeException('Posta çözülemedi.');
            $payload=json_decode($json,true,16,JSON_THROW_ON_ERROR);
            if(!NotificationMailPolicy::allows($payload,$this->db)||!NotificationResumePolicy::mailAllowed($this->db,(int)$job['user_id'],(string)$job['id'],$payload)
                ||(($payload['type']??'')==='integration'&&!(($payload['mode']??'')==='recovered'?IntegrationRecoveryMail::current($this->db,$payload):IntegrationOutageMail::current($this->db,$payload)))){$this->finish($job,'cancelled');return true;}
            if(($payload['type']??'')==='admin-mfa'){
                $message=(new AdminEmailMfa($this->db,$this->config))->mailMessage($payload);
                if(!$message){$this->finish($job,'cancelled');return true;}
                $subject=$message['subject'];$body=$message['body'];
            }elseif(($payload['type']??'')==='password-change'){
                $message=(new PasswordChange($this->db,$this->config))->mailMessage($payload);
                if(!$message){$this->finish($job,'cancelled');return true;}
                $subject=$message['subject'];$body=$message['body'];
            }elseif(($payload['type']??'')==='privacy-deletion'){
                $message=AccountDeletionRequest::message($payload);$subject=$message['subject'];$body=$message['body'];
            }elseif(($payload['type']??'')==='operation'){
                if(($payload['to']??'')!=='admin@omnigoglobal.com')throw new \RuntimeException('Posta alıcısı doğrulanamadı.');
                $message=OperationalMailMessage::format($payload['detail']);$subject=$message['subject'];$body=$message['body'];
            }elseif(($payload['type']??'')==='integration'){
                if(($payload['to']??'')!=='admin@omnigoglobal.com')throw new \RuntimeException('Posta alıcısı doğrulanamadı.');
                if(($payload['mode']??'')==='recovered'){$message=IntegrationRecoveryMail::message($payload);$subject=$message['subject'];$body=$message['body'];}
                else{
                $subject='Omnigo Global — '.$payload['service'].' bağlantı / aktarım sorunu 15 dakikayı aştı';
                $date=(new \DateTimeImmutable($payload['detectedAt']))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i:s');
                $body="Firma: ".$payload['company']."\nPazaryeri: ".$payload['service']."\nTespit zamanı: ".$date." (Türkiye saati)\n\n".$payload['message']."\n\nYönetici Portalı → Bağlantı kontrolü bölümünü inceleyin:\nhttps://yonetim.omnigoglobal.com/?view=connections\n\nAPI anahtarları ve müşteri sipariş bilgileri bu e-postada paylaşılmaz.\n\nOmnigo Global";
                }
            }else{
            if (!$this->db->one('SELECT token_hash FROM password_resets WHERE token_hash=? AND expires>?',[$payload['tokenHash'],time()])) {
                $this->finish($job,'cancelled'); return true;
            }
            $subject='Omnigo Global — Şifre yenileme';
            $body="Şifrenizi yenilemek için aşağıdaki bağlantıyı açın:\n\n".$payload['link']."\n\nBağlantı 30 dakika geçerlidir ve yalnızca bir kez kullanılabilir. Bu işlemi siz istemediyseniz e-postayı dikkate almayın.\n\nOmnigo Global";
            }
            $sent=$transport ? $transport($payload['to'],$subject,$body) : (new MailTransport($this->config['mail']))->send($payload['to'],$subject,$body);
            if (!$sent) throw new \RuntimeException('Posta servisi kabul etmedi.');
            $this->finish($job,'sent');
        } catch (\Throwable $error) {
            $attempt=(int)$job['attempts']+1;
            if ($attempt>=5) $this->finish($job,'failed');
            else $this->db->run("UPDATE mail_outbox SET state='pending',next_run=?,lease_until=0 WHERE id=? AND lease_token=? AND state='running'",[time()+min(600,30*(2**$attempt)),$job['id'],$job['lease_token']]);
            error_log('Omnigo posta kuyruğu: gönderim denenemedi; hassas ayrıntılar kaydedilmedi.');
        }
        return true;
    }
    private function finish(array $job,string $state): void
    {
        $this->db->run("UPDATE mail_outbox SET state=?,payload=NULL,lease_until=0 WHERE id=? AND lease_token=? AND state='running'",[$state,$job['id'],$job['lease_token']]);
    }
}
