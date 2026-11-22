<?php
declare(strict_types=1);
namespace Omnigo;

/** One durable email after a continuous outage; no bell, sound or push event. */
final class IntegrationOutageMail
{
    public const DELAY_SECONDS=900;
    public function __construct(private Database $db,private MailQueue $mail){}
    public function enqueue(int $company,string $name,string $provider,array $snapshot,array $incident,int $now): bool
    {
        $since=strtotime($incident['unhealthySince']??'')?:0;
        if(!$since||$now-$since<self::DELAY_SECONDS||!$this->mail->ready())return false;
        $admin=$this->db->one("SELECT u.id FROM users u LEFT JOIN account_controls a ON a.user_id=u.id WHERE u.role='admin' AND COALESCE(a.active,1)=1 ORDER BY u.id LIMIT 1");
        if(!$admin)return false;
        $id=self::id($company,$provider,$incident['incidentKey']);
        $payload=['type'=>'integration','mode'=>'prolonged_outage','to'=>'admin@omnigoglobal.com','companyId'=>$company,'company'=>$name,'provider'=>$provider,'service'=>$snapshot['service'],'incidentKey'=>$incident['incidentKey'],'detectedAt'=>gmdate('c',$since),'observedAt'=>gmdate('c',$now),'message'=>$snapshot['message']];
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $this->db->run("$insert INTO mail_outbox(id,user_id,payload,state,next_run,created) VALUES(?,?,?,'pending',?,?)",[$id,(int)$admin['id'],$this->mail->encrypt($payload),$now,gmdate('c',$now)]);
        return true;
    }
    public static function id(int $company,string $provider,string $incident):string
    {
        return 'outage-'.substr(hash('sha256',json_encode([$company,$provider,$incident],JSON_THROW_ON_ERROR)),0,56);
    }
    public static function permitted(array $payload): bool
    {
        $since=strtotime($payload['detectedAt']??'')?:0;$observed=strtotime($payload['observedAt']??'')?:0;
        return ($payload['mode']??'')==='prolonged_outage'&&($payload['to']??'')==='admin@omnigoglobal.com'
            &&is_int($payload['companyId']??null)&&$payload['companyId']>0
            &&isset(IntegrationHealthSnapshot::PROVIDERS[$payload['provider']??''])
            &&is_string($payload['incidentKey']??null)&&preg_match('/^[a-f0-9]{32,64}$/D',$payload['incidentKey'])===1
            &&$since>0&&$observed-$since>=self::DELAY_SECONDS;
    }
    public static function current(Database $db,array $payload,?int $now=null): bool
    {
        if(!self::permitted($payload))return false;$now??=time();
        $row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$payload['companyId']]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];$incident=$state[$payload['provider']]??[];
        if(empty($incident['open'])||($incident['incidentKey']??null)!==$payload['incidentKey']||($incident['unhealthySince']??null)!==$payload['detectedAt'])return false;
        if($now-(strtotime($incident['unhealthySince'])?:$now)<self::DELAY_SECONDS)return false;
        return ((new IntegrationHealthSnapshot($db))->company($payload['companyId'],$now)[$payload['provider']]['status']??null)==='error';
    }
}
