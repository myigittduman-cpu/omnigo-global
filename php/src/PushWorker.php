<?php
declare(strict_types=1);
namespace Omnigo;
final class PushWorker
{
 private ?MobilePushSender $mobileSender=null;
 public function __construct(private Database $db,private ?PushDevices $devices=null,private mixed $sender=null){}
 public function runOne():bool{
  $job=$this->db->transaction(function(){
   $now=time();$this->db->run("UPDATE push_deliveries SET state='pending',lease_token=NULL WHERE state='sending' AND lease_until<?",[$now]);
   $job=$this->db->one("SELECT * FROM push_deliveries WHERE state='pending' AND next_run<=? ORDER BY next_run,created LIMIT 1".$this->db->lock(),[$now]);
   if(!$job)return null;$job['lease_token']=bin2hex(random_bytes(32));$job['attempts']++;
   $this->db->run("UPDATE push_deliveries SET state='sending',attempts=?,lease_token=?,lease_until=? WHERE id=?",[$job['attempts'],$job['lease_token'],$now+120,$job['id']]);return $job;
  });if(!$job)return false;
  $devices=$this->devices??PushRoutes::devices($this->db);
  try{
   $device=$this->db->one('SELECT * FROM notification_devices WHERE id=?',[$job['device_id']]);
   if(!$device||!$devices->usable($device)||$device['generation']!=$job['generation']||$job['created']<time()-86400){$this->finish($job,'cancelled');return true;}
   if(str_starts_with($job['id'],'chat-')){
    $payload=(new ChatNotifications($this->db))->delivery($device,$job['id']);
    if(!$payload){$this->finish($job,'cancelled');return true;}
    $mobile=in_array($device['kind'],['ios','android'],true);
    $subscription=$mobile?$devices->mobileSubscription($device):$devices->subscription($device);
    $result=$this->sender?($this->sender)($subscription,$payload)
     :($mobile?($this->mobileSender??=new MobilePushSender())->send($subscription,$payload):(new PushSender())->send($subscription,$payload));
    $this->completeDelivery($job,$device,$result,$mobile);
    return true;
   }
   $event=$job['event_id']?$this->db->one('SELECT n.*,c.name AS company FROM order_notifications n JOIN companies c ON c.id=n.company_id WHERE n.id=?',[$job['event_id']]):null;
   if($job['event_id']&&!$event){$this->finish($job,'cancelled');return true;}
   if($event)$event=$devices->enrichEvent($event);
   // Recheck at delivery time so previously queued connection alerts stay quiet too.
   if(!MobileNotificationPolicy::allows($device,$event)){$this->finish($job,'cancelled');return true;}
   $recipient=$this->db->one('SELECT id,role,company_id FROM users WHERE id=?',[$device['user_id']]);
   if(!$recipient||!NotificationAudience::permits($recipient,$event)||!CustomerNotificationPolicy::recipient($this->db,$recipient,$event)){$this->finish($job,'cancelled');return true;}
   $names=['koctas'=>'Koçtaş','pazarama'=>'Pazarama','n11'=>'n11','hepsiburada'=>'Hepsiburada','idefix'=>'İdefix','pttavm'=>'PttAvm','teknosa'=>'Teknosa','trendyol'=>'Trendyol'];
   $payload=['title'=>$event?($names[$event['provider']]??'Pazaryeri').' · Yeni sipariş':'OmniGoGlobal · Bildirim denemesi',
    'body'=>$event?$event['company'].' · Yeni sipariş geldi.':'Uygulama kapalıyken bildirim almaya hazırsınız.',
    'tag'=>'omnigo-'.$job['id'],'sound'=>true,'url'=>$event?'/?orderCompany='.(int)$event['company_id']:'/',
    'sentAt'=>time()];
   $health=($event['event_type']??'')==='integration';
   if($health){$detail=json_decode($event['payload'],true,16,JSON_THROW_ON_ERROR);$payload['title']=$detail['service'].(($detail['status']??'')==='recovered'?' · Bağlantı düzeldi':' · Bağlantı / aktarım sorunu');$payload['body']=$event['company'].' · '.$detail['message'];$payload['url']='/?view=connections&orderCompany='.(int)$event['company_id'];}
   if(in_array($event['event_type']??'',['operation','shipment'],true)){
    $detail=json_decode($event['payload'],true,16,JSON_THROW_ON_ERROR);
    $message=OperationalNotifications::presentation($event['event_type'],$detail);
    $payload['title']=($names[$event['provider']]??'Pazaryeri').' · '.$message['title'];
    $payload['body']=$event['company'].' · '.$message['body'];
    $payload['url']='/?orderCompany='.(int)$event['company_id'].'&view='.$message['view'].'&noticeType='.$event['event_type'].'&noticeId='.($event['event_type']==='shipment'?(int)$detail['auditId']:(int)$event['id']);
   }
   if(in_array($event['event_type']??'',['support','user'],true)){
    $detail=json_decode($event['payload'],true,16,JSON_THROW_ON_ERROR);$message=UserChangeNotifications::presentation($detail);
    $payload['title']='Omnigo Global · '.$message['title'];$payload['body']=$message['body'];
    $payload['url']='/?view=support&orderCompany='.(int)$event['company_id'].'&noticeType='.$event['event_type'].'&noticeId='.($event['event_type']==='support'?(int)$detail['targetId']:(int)$event['id']);
   }
   if(($event['event_type']??'')==='resolved')$payload=[...$payload,...SupportResolutionNotifications::presentation(json_decode($event['payload'],true)),'url'=>SupportResolutionNotifications::destination($event)];
   if($event)$payload['category']=NotificationCategory::forEvent($event['event_type'],json_decode($event['payload']??'{}',true,32,JSON_THROW_ON_ERROR));
   $language=$devices->language($device);
   $mobile=in_array($device['kind'],['ios','android'],true);
   if($mobile){
    if(!$devices->mobileRecipient($device,$event)){$this->finish($job,'cancelled');return true;}
    $subscription=$devices->mobileSubscription($device);
    $payload=MobilePushMessage::create($device,$event,$job['id'],$recipient['role'],$language);
    $result=$this->sender?($this->sender)($subscription,$payload):($this->mobileSender??=new MobilePushSender())->send($subscription,$payload,$language);
   }else {
    $payload['presentation']=MobileNotificationPresentation::create($event,$recipient['role'],$language);
    $payload['title']=$payload['presentation']['title'];$payload['body']=$payload['presentation']['body'];
    if(in_array($event['event_type']??'',['order','operation'],true))$payload['url']=MobilePushMessage::create($device,$event,$job['id'],$recipient['role'],$language)['url'];
    $result=$this->sender?($this->sender)($devices->subscription($device),$payload):(new PushSender())->send($devices->subscription($device),$payload);
   }
   $this->completeDelivery($job,$device,$result,$mobile);
  }catch(\Throwable){$this->retry($job,60);}
  return true;
 }
 private function completeDelivery(array $job,array $device,array $result,bool $mobile):void{
  if($result['success'])$this->finish($job,'delivered');
  elseif(($result['expired']??false)||(!$mobile&&in_array($result['status'],[404,410],true))){
   $this->db->run('UPDATE notification_devices SET enabled=0 WHERE id=? AND generation=?',[$device['id'],$job['generation']]);$this->finish($job,'expired');
  }elseif($result['permanent']??false)$this->finish($job,'failed',0,'Bildirim hizmetinin yapılandırması kontrol edilmeli.');
  else $this->retry($job,(int)($result['retryAfter']??0));
 }
 private function retry(array $job,int $wait):void{
  $delay=max($wait,min(3600,30*2**min(7,$job['attempts']-1))+random_int(0,15));
  $this->finish($job,$job['attempts']>=8?'failed':'pending',time()+$delay,'Bildirim gönderilemedi; yeniden denenecek.');
 }
 private function finish(array $job,string $state,int $next=0,?string $error=null):void{
  $updated=$this->db->run('UPDATE push_deliveries SET state=?,next_run=?,lease_token=NULL,lease_until=0,error=? WHERE id=? AND lease_token=?',[$state,$next,$error,$job['id'],$job['lease_token']]);
  if($updated->rowCount()===1&&$state!=='pending'&&str_starts_with($job['id'],'chat-'))$this->db->run('DELETE FROM chat_push_refs WHERE job_id=?',[$job['id']]);
 }
}

