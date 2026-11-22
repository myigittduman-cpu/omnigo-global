<?php
declare(strict_types=1);
namespace Omnigo;
final class PushDevices
{
 public function __construct(private Database $db,private DeliveryCipher $cipher){}
 public static function tag(array $user):string{return hash('sha256',$user['password'].':'.$user['role']);}
 public function save(array $user,string $kind,array $data):array{
  if(!in_array($user['role'],['admin','client'],true))Http::fail(403,'Bildirim erişiminiz yok.');
  if(!in_array($kind,['web','desktop'],true)||!is_bool($data['sound']??true))Http::fail(400,'Bildirim tercihi geçersiz.');
  $data['sound']=true;
  $subscription=$kind==='web'?PushSubscriptionValidator::validate((array)($data['subscription']??[])):null;
  $context=$kind==='web'?WebPushBinding::context($data['clientContext']??null):[];
  $key=$kind==='web'?hash('sha256',$subscription['endpoint']):($data['deviceId']??'');
  if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))Http::fail(400,'Cihaz bilgisi geçersiz.');
  $token=$kind==='desktop'?bin2hex(random_bytes(32)):null;
  return $this->db->transaction(function()use($user,$kind,$data,$subscription,$key,$token,$context){
   $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user['id']]);
   $row=$this->db->one('SELECT * FROM notification_devices WHERE device_key=?'.$this->db->lock(),[$key]);
   if(!$row&&(int)$this->db->one('SELECT COUNT(*) AS n FROM notification_devices WHERE user_id=? AND enabled=1',[$user['id']])['n']>=20)Http::fail(429,'Cihaz sınırına ulaşıldı.');
   $previous=$row&&$kind==='web'?$this->cipher->open((int)$row['user_id'],'push:'.$key,$row['payload']):[];
   $unchanged=$row&&$kind==='web'&&WebPushBinding::unchanged($row,$user,$previous,$subscription);
   $payload=$this->cipher->seal((int)$user['id'],'push:'.$key,[...($subscription??[]),'localeProfile'=>NotificationLanguage::profile($user),...($kind==='web'?['clientContext'=>$context?:($previous['clientContext']??[])]:[])]);
   $values=[(int)$user['id'],$kind,$token?hash('sha256',$token):null,$payload,self::tag($user),$user['token'],(int)($data['sound']??true),time()+365*86400,time()];
   if($row){
    // Reopening the same installation must not cancel already queued notifications.
    $increment=$unchanged?0:1;
    $this->db->run('UPDATE notification_devices SET user_id=?,kind=?,token_hash=?,payload=?,credential_tag=?,session_token=?,sound=?,expires=?,last_seen=?,enabled=1,generation=generation+'.$increment.' WHERE id=?',[...$values,$row['id']]);$id=(int)$row['id'];
   }else{
    $this->db->run('INSERT INTO notification_devices(user_id,kind,token_hash,payload,credential_tag,session_token,sound,expires,last_seen,device_key) VALUES(?,?,?,?,?,?,?,?,?,?)',[...$values,$key]);$id=$this->db->insertId();
   }
   return ['id'=>$id,'userId'=>(int)$user['id'],'token'=>$token,'cursor'=>(int)($this->db->one('SELECT MAX(id) AS id FROM order_notifications')['id']??0),'enabled'=>true,'sound'=>(bool)($data['sound']??true)];
  });
 }
 public function usable(array $device):bool{
  $user=$this->db->one('SELECT u.*,COALESCE(a.active,1) AS active FROM users u LEFT JOIN account_controls a ON a.user_id=u.id WHERE u.id=?',[$device['user_id']]);
  if(!$device['enabled']||$device['expires']<=time()||!$user||(int)$user['active']!==1)return false;
  if(in_array($device['kind'],['ios','android'],true))return (new MobilePushDevices($this->db,$this->cipher))->usable($device,$user);
  if(!hash_equals($device['credential_tag'],self::tag($user)))return false;
  if($user['role']==='admin')return true;
  return in_array($device['kind'],['web','desktop'],true)&&$user['role']==='client'&&PushSession::exists($this->db,$device,(int)$user['id']);
 }
 public function poll(string $token,?int $after,bool $includeIntegration=false,bool $includeRecovery=false,bool $includeOperations=false,?string $expectedRole=null,bool $includeChat=false,?int $chatAfter=null,bool $currentNoticeFormat=false):array{
  if(!preg_match('/^[a-f0-9]{64}$/D',$token))Http::fail(401,'Bildirim erişimi sona erdi.');
  $row=$this->db->one("SELECT * FROM notification_devices WHERE token_hash=? AND kind='desktop'",[hash('sha256',$token)]);
  if(!$row||!$this->usable($row))Http::fail(401,'Bildirim erişimi sona erdi. Hesabınızla tekrar giriş yapın.');
  $user=$this->db->one('SELECT id,role,company_id FROM users WHERE id=?',[$row['user_id']]);
  if($expectedRole!==null&&$user['role']!==$expectedRole)Http::fail(401,'Bu panelde bildirim erişiminiz yok.');
  $this->db->run('UPDATE notification_devices SET last_seen=? WHERE id=?',[time(),$row['id']]);
  $chat=$includeChat?['chat'=>(new ChatNotifications($this->db))->desktop($user,$chatAfter)]:[];
  if($user['role']==='client'){
   $feed=(new DesktopClientNotifications($this->db))->arrivals($user,$after);
  }else{
   $feed=(new OrderNotifications($this->db,$this->cipher))->arrivals($after,$includeIntegration,$includeRecovery,$includeOperations,$user);
   $feed['items']=array_filter($feed['items'],MobileNotificationPolicy::allowsArrival(...));
  }
  // Keep the source cursor even when an older receiver cannot display any item on this page.
  $feed['items']=array_values(array_filter($feed['items'],fn(array $item)=>DesktopNoticeFormat::supports($item,$user['role'],$currentNoticeFormat)));
  return [...$this->localizeFeed($feed,$row,$user['role']),'sound'=>true,...$chat];
 }
 public function current(array $user,int $id):array{
  $device=$this->db->one('SELECT * FROM notification_devices WHERE id=? AND user_id=?',[$id,$user['id']]);
  if(!$device)Http::fail(404,'Bildirim cihazı bulunamadı.');
  return $device;
 }
 public function enrichEvent(array $event):array{return (new OrderNotificationOrder($this->db,$this->cipher))->enrich($event);}
 public function change(array $user,int $id,array $data):array{
  $device=$this->current($user,$id);
  if(!is_bool($data['enabled']??null)||!is_bool($data['sound']??null))Http::fail(400,'Bildirim tercihi geçersiz.');
  $data['sound']=true;
  // A disabled binding must be enrolled again, never revived by a stale tab.
  if($data['enabled']&&!$this->usable($device))Http::fail(409,'Bildirimleri yeniden etkinleştirin.');
  $this->db->run('UPDATE notification_devices SET enabled=?,sound=? WHERE id=?',[(int)$data['enabled'],(int)$data['sound'],$id]);
  if(!$data['enabled'])$this->db->run("UPDATE push_deliveries SET state='cancelled' WHERE device_id=? AND state IN ('pending','sending')",[$id]);
  return ['enabled'=>$data['enabled'],'sound'=>$data['sound']];
 }
 public function language(array $device):string{
  $metadata=$device['payload']?$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']):[];
  return NotificationLanguage::read($this->db,$device,$metadata['localeProfile']??null);
 }
 public function attachLanguage(array $user,array $device):void{
  $metadata=$device['payload']?$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']):[];
  $metadata['localeProfile']=NotificationLanguage::profile($user);
  $this->db->run('UPDATE notification_devices SET payload=? WHERE id=? AND user_id=?',[$this->cipher->seal((int)$user['id'],'push:'.$device['device_key'],$metadata),$device['id'],$user['id']]);
 }
 private function localizeFeed(array $feed,array $device,string $role):array{
  $language=$this->language($device);
  foreach($feed['items'] as &$item){
   if($language!=='tr'){
   $event=['company'=>$item['company'],'provider'=>$item['provider'],'event_type'=>$item['type'],'amountCents'=>$item['amountCents']??null,'payload'=>json_encode($item,JSON_THROW_ON_ERROR)];
   $item['presentation']=MobileNotificationPresentation::create($event,$role,$language);
   }
   // Existing Windows clients validate this registered product name.
   if(isset($item['presentation']))$item['presentation']['title']=$role==='admin'?'OG Yönetici Portalı':'OG Müşteri Portalı';
   if($language!=='tr'&&$item['type']==='resolved')$item['url']='/?view=support&orderCompany='.(int)$item['companyId'].'&noticeType=user&noticeId='.(int)$item['id'];
  }unset($item);return $feed;
 }
 public function subscription(array $device):array{return PushSubscriptionValidator::validate($this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']));}
 public function mobileSubscription(array $device):array{return (new MobilePushDevices($this->db,$this->cipher))->subscription($device);}
 public function mobileRecipient(array $device,?array $event):bool{return (new MobilePushDevices($this->db,$this->cipher))->recipient($device,$event);}
 public function test(array $user,int $id):void{
  $device=$this->current($user,$id);if(!$this->usable($device)||!in_array($device['kind'],['web','android','ios'],true))Http::fail(409,'Önce bu cihazda bildirimleri açın.');
  if($this->db->one("SELECT id FROM push_deliveries WHERE device_id=? AND event_id IS NULL AND id NOT LIKE 'chat-%' AND created>?",[$id,time()-60]))Http::fail(429,'Deneme için bir dakika bekleyin.');
  $this->db->run("INSERT INTO push_deliveries(id,device_id,event_id,generation,next_run,created) VALUES(?,?,NULL,?,?,?)",[bin2hex(random_bytes(32)),$id,$device['generation'],time(),time()]);
 }
 public function enqueue(int $event,?int $clientCompany=null):void{
  $notification=$this->db->one('SELECT event_type,payload FROM order_notifications WHERE id=?',[$event]);
  if(!$notification)return;
  $scope=$clientCompany===null?'':" AND user_id IN (SELECT id FROM users WHERE company_id=? AND role='client')";
  $args=$clientCompany===null?[time()]:[time(),$clientCompany];
  foreach($this->db->all("SELECT id,kind,generation FROM notification_devices WHERE kind IN ('web','android','ios') AND enabled=1 AND expires>?$scope",$args) as $device){
   if(!MobileNotificationPolicy::allows($device,$notification))continue;
   $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
   $this->db->run("$insert INTO push_deliveries(id,device_id,event_id,generation,next_run,created) VALUES(?,?,?,?,?,?)",[bin2hex(random_bytes(32)),$device['id'],$event,$device['generation'],time(),time()]);
  }
 }
}

