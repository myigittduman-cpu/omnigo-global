<?php
declare(strict_types=1);
namespace Omnigo;

/** Prolonged outages and their verified recovery use one email each; no bell or push. */
final class IntegrationAlertMonitor
{
    public function __construct(private Database $db,private MailQueue $mail,private ?\Closure $push=null,private ?IntegrationRecoveryEvidence $recovery=null){}
    public function run(?int $now=null): void
    {
        $now??=time();
        foreach($this->db->all('SELECT DISTINCT company_id FROM jobs UNION SELECT DISTINCT company_id FROM records WHERE kind LIKE ?', ['integration_%']) as $row)$this->check((int)$row['company_id'],$now);
    }
    public function check(int $company,?int $now=null): void
    {
        $now??=time();
        $this->db->transaction(function()use($company,$now){
            $companyRow=$this->db->one('SELECT id,name FROM companies WHERE id=?'.$this->db->lock(),[$company]);if(!$companyRow)return;
            $row=$this->db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='integration_health'",[$company]);$state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
            $snapshots=(new IntegrationHealthSnapshot($this->db))->company($company,$now);
            foreach($state as $provider=>&$incident){
                // Legacy event rows remain archived; they are never replayed or emailed.
                $incident['pendingMails']=[];
                $incident['incidentKey']??=substr(hash('sha256',json_encode([$company,$provider,$incident['eventId']??null,$incident['detectedAt']??null],JSON_THROW_ON_ERROR)),0,32);
                if(!empty($incident['open'])&&!isset($incident['waitingFor'])){
                    $old=$this->db->one('SELECT payload,created FROM order_notifications WHERE id=?',[$incident['eventId']??0]);$detail=$old?json_decode($old['payload'],true):[];
                    $incident['detectedAt']=$old['created']??gmdate('c',$now);$incident['waitingFor']=[];
                    foreach($snapshots[$provider]['jobs']??[] as $job=>$evidence)$incident['waitingFor'][$job]=$detail['lastSuccessAt']??null;
                }
                if(!isset($snapshots[$provider])||in_array($snapshots[$provider]['status'],['inactive','not_configured'],true)){$incident['open']=false;$incident['unhealthySince']=null;}
                if(!empty($incident['waitingFor'])){
                    $incident['waitingFor']=array_filter($incident['waitingFor'],fn($job)=>!PaymentPolicy::automaticJob($job),ARRAY_FILTER_USE_KEY);
                    if(!$incident['waitingFor']){$incident['open']=false;$incident['pendingMails']=[];$incident['closureReason']='manual_payments';}
                }
            }unset($incident);
            foreach($snapshots as $provider=>$snapshot){
                if($snapshot['status']==='error'){
                    if(empty($state[$provider]['open'])){
                        $state[$provider]=['open'=>true,'incidentKey'=>bin2hex(random_bytes(16)),'detectedAt'=>gmdate('c',$now),'waitingFor'=>[],'pendingMails'=>[],'outageMailQueued'=>false];
                    }
                    $state[$provider]['unhealthySince']??=gmdate('c',$now);
                    foreach($snapshot['requiredJobs'] as $job)$state[$provider]['waitingFor'][$job]=$snapshot['jobs'][$job]['successAt']??null;
                    if(empty($state[$provider]['outageMailQueued'])&&(new IntegrationOutageMail($this->db,$this->mail))->enqueue($company,$companyRow['name'],$provider,$snapshot,$state[$provider],$now))$state[$provider]['outageMailQueued']=true;
                }elseif(!empty($state[$provider]['open'])){
                    // A brief successful interval resets the continuous-outage email timer.
                    $state[$provider]['unhealthySince']=null;
                    if(IntegrationHealthSnapshot::recovered($snapshot,$state[$provider])
                        &&($this->recovery??new IntegrationRecoveryEvidence($this->db))->ready($company,$provider,$state[$provider],$now)){
                        $state[$provider]['open']=false;$state[$provider]['recoveredAt']=gmdate('c',$now);
                        $state[$provider]['recoveryMailPending']=in_array(IntegrationRecoveryMail::warningState($this->db,$company,$provider,$state[$provider]['incidentKey']),['sent','running'],true);
                    }
                }
                // Only closures observed by this monitor create new recovery work. Archived
                // closed incidents are never replayed when a new release is deployed.
                if(!empty($state[$provider]['recoveryMailPending'])&&in_array(IntegrationRecoveryMail::warningState($this->db,$company,$provider,$state[$provider]['incidentKey']),['failed','cancelled'],true))$state[$provider]['recoveryMailPending']=false;
                if(!empty($state[$provider]['recoveryMailPending'])&&empty($state[$provider]['open'])
                    &&IntegrationHealthSnapshot::recovered($snapshot,$state[$provider])
                    &&($this->recovery??new IntegrationRecoveryEvidence($this->db))->ready($company,$provider,$state[$provider],$now)
                    &&(new IntegrationRecoveryMail($this->db,$this->mail))->enqueue($company,$companyRow['name'],$provider,$snapshot,$state[$provider],$now)){
                    $state[$provider]['recoveryMailPending']=false;
                }
            }
            if(!$state)return;$json=json_encode($state,JSON_THROW_ON_ERROR);
            if($row&&$json!==$row['payload'])$this->db->run('UPDATE records SET payload=?,version=version+1,updated=? WHERE id=?',[$json,gmdate('c',$now),$row['id']]);
            elseif(!$row)$this->db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'integration_health',?,?)",[$company,$json,gmdate('c',$now)]);
        });
    }
    public function detail(int $company,int $event): array
    {
        $row=$this->db->one("SELECT provider,payload,created FROM order_notifications WHERE id=? AND company_id=? AND event_type='integration'",[$event,$company]);if(!$row)Http::fail(404,'Bağlantı bildirimi bulunamadı.');
        return ['provider'=>$row['provider'],'createdAt'=>$row['created'],...json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR)];
    }
}
