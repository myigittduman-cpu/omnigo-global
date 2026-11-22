<?php
declare(strict_types=1);
namespace Omnigo;

/** One recovery email only for an outage warning already accepted by the mail service. */
final class IntegrationRecoveryMail
{
    public function __construct(private Database $db,private MailQueue $mail){}
    public function enqueue(int $company,string $name,string $provider,array $snapshot,array $incident,int $now):bool
    {
        if(!$this->mail->ready()||!empty($incident['open'])||!self::warningSent($this->db,$company,$provider,$incident['incidentKey']))return false;
        $admin=$this->db->one("SELECT u.id FROM users u LEFT JOIN account_controls a ON a.user_id=u.id WHERE u.role='admin' AND COALESCE(a.active,1)=1 ORDER BY u.id LIMIT 1");
        if(!$admin)return false;
        $payload=['type'=>'integration','mode'=>'recovered','to'=>'admin@omnigoglobal.com','companyId'=>$company,'company'=>$name,'provider'=>$provider,'service'=>$snapshot['service'],'incidentKey'=>$incident['incidentKey'],'detectedAt'=>$incident['detectedAt'],'recoveredAt'=>$incident['recoveredAt']];
        if(!self::permitted($payload))return false;
        $id='recovery-'.substr(hash('sha256',json_encode([$company,$provider,$incident['incidentKey']],JSON_THROW_ON_ERROR)),0,54);
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->db->run("$insert INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",[$id,(int)$admin['id'],$this->mail->encrypt($payload),$now,gmdate('c',$now)]);
        return true;
    }
    public static function warningSent(Database $db,int $company,string $provider,string $incident):bool
    {
        return self::warningState($db,$company,$provider,$incident)==='sent';
    }
    public static function warningState(Database $db,int $company,string $provider,string $incident):?string
    {
        return $db->one('SELECT state FROM mail_outbox WHERE id=?',[IntegrationOutageMail::id($company,$provider,$incident)])['state']??null;
    }
    public static function permitted(array $payload):bool
    {
        $detected=strtotime($payload['detectedAt']??'')?:0;$recovered=strtotime($payload['recoveredAt']??'')?:0;
        return ($payload['mode']??'')==='recovered'&&($payload['to']??'')==='admin@omnigoglobal.com'
            &&is_int($payload['companyId']??null)&&$payload['companyId']>0
            &&isset(IntegrationHealthSnapshot::PROVIDERS[$payload['provider']??''])
            &&($payload['service']??null)===IntegrationHealthSnapshot::PROVIDERS[$payload['provider']]
            &&is_string($payload['incidentKey']??null)&&preg_match('/^[a-f0-9]{32,64}$/D',$payload['incidentKey'])===1
            &&$detected>0&&$recovered>=$detected;
    }
    public static function current(Database $db,array $payload,?int $now=null,?IntegrationRecoveryEvidence $evidence=null):bool
    {
        if(!self::permitted($payload)||!self::warningSent($db,$payload['companyId'],$payload['provider'],$payload['incidentKey']))return false;
        $now??=time();$row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$payload['companyId']]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];$incident=$state[$payload['provider']]??[];
        if(!empty($incident['open'])||($incident['incidentKey']??null)!==$payload['incidentKey']||($incident['recoveredAt']??null)!==$payload['recoveredAt'])return false;
        $snapshot=(new IntegrationHealthSnapshot($db))->company($payload['companyId'],$now)[$payload['provider']]??null;
        return $snapshot!==null&&IntegrationHealthSnapshot::recovered($snapshot,$incident)
            &&($evidence??new IntegrationRecoveryEvidence($db))->ready($payload['companyId'],$payload['provider'],$incident,$now);
    }
    public static function message(array $payload):array
    {
        if(!self::permitted($payload))throw new \InvalidArgumentException('Toparlanma e-postası doğrulanamadı.');
        $local=fn(string $at)=>(new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i:s');
        return ['subject'=>'Omnigo Global — '.$payload['service'].' bağlantısı ve veri aktarımı düzeldi',
            'body'=>"Firma: ".$payload['company']."\nPazaryeri: ".$payload['service']."\nKesinti tespiti: ".$local($payload['detectedAt'])."\nToparlanma doğrulaması: ".$local($payload['recoveredAt'])." (Türkiye saati)\n\nBağlantı yeniden sağlandı. Bekleyen aktarım ve kayıt mutabakatı kontrolleri doğrulandı.\n\nYönetici Portalı → Bağlantı kontrolü:\nhttps://yonetim.omnigoglobal.com/?view=connections\n\nAPI anahtarları ve müşteri sipariş bilgileri bu e-postada paylaşılmaz.\n\nOmnigo Global"];
    }
}
