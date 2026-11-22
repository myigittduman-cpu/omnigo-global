import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const edits={
 'PushDevices.php':[
  ["$payload=$subscription?$this->cipher->seal((int)$user['id'],'push:'.$key,$subscription):null;","$payload=$this->cipher->seal((int)$user['id'],'push:'.$key,[...($subscription??[]),'localeProfile'=>NotificationLanguage::profile($user)]);"],
  ["if($user['role']==='client')return [...(new DesktopClientNotifications($this->db))->arrivals($user,$after),'sound'=>true];","if($user['role']==='client')return [...$this->localizeFeed((new DesktopClientNotifications($this->db))->arrivals($user,$after),$row,$user['role']),'sound'=>true];"],
  ["$feed=(new OrderNotifications($this->db))->arrivals($after,$includeIntegration,$includeRecovery,$includeOperations);","$feed=$this->localizeFeed((new OrderNotifications($this->db))->arrivals($after,$includeIntegration,$includeRecovery,$includeOperations),$row,$user['role']);"],
  [" public function subscription(array $device):array",` public function language(array $device):string{
  $metadata=$device['payload']?$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']):[];
  return NotificationLanguage::read($this->db,$device,$metadata['localeProfile']??null);
 }
 public function attachLanguage(array $user,array $device):void{
  $metadata=$device['payload']?$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']):[];
  $metadata['localeProfile']=NotificationLanguage::profile($user);
  $this->db->run('UPDATE notification_devices SET payload=? WHERE id=? AND user_id=?',[$this->cipher->seal((int)$user['id'],'push:'.$device['device_key'],$metadata),$device['id'],$user['id']]);
 }
 private function localizeFeed(array $feed,array $device,string $role):array{
  $language=$this->language($device);if($language==='tr')return $feed;
  foreach($feed['items'] as &$item){
   $event=['company'=>$item['company'],'provider'=>$item['provider'],'event_type'=>$item['type'],'amountCents'=>$item['amountCents']??null,'payload'=>json_encode($item,JSON_THROW_ON_ERROR)];
   $item['presentation']=MobileNotificationPresentation::create($event,$role,$language);
   if($item['type']==='resolved')$item['url']='/?view=support&orderCompany='.(int)$item['companyId'].'&noticeType=user&noticeId='.(int)$item['id'];
  }unset($item);return $feed;
 }
 public function subscription(array $device):array`]
 ],
 'MobilePushDevices.php':[
  ["$registration=MobilePushRegistration::validate($data,$user);","$registration=[...MobilePushRegistration::validate($data,$user),'localeProfile'=>NotificationLanguage::profile($user)];"],
  ["$registration=MobilePushRegistration::validate([...$registration,'token'=>$data['token']??''],$user);","$metadata=$this->cipher->open((int)$device['user_id'],'push:'.$device['device_key'],$device['payload']);\n            $registration=[...MobilePushRegistration::validate([...$registration,'token'=>$data['token']??''],$user),'localeProfile'=>$metadata['localeProfile']??null];"]
 ],
 'PushRoutes.php':[["$this->db->run('UPDATE notification_devices SET session_token=?","$devices->attachLanguage($user,$device);\n   $this->db->run('UPDATE notification_devices SET session_token=?"]],
 'PushWorker.php':[
  ["$mobile=in_array($device['kind'],['ios','android'],true);","$language=$devices->language($device);\n   $mobile=in_array($device['kind'],['ios','android'],true);"],
  ["$recipient['role']);","$recipient['role'],$language);"],
  ["$recipient['role'])['url']","$recipient['role'],$language)['url']"]
 ],
 'MobilePushMessage.php':[
  ["string $role='admin'): array","string $role='admin',string $language='tr'): array"],
  ["MobileNotificationPresentation::create($event,$role)","MobileNotificationPresentation::create($event,$role,$language)"]
 ],
 'MobileNotificationPresentation.php':[
  ["string $role): array","string $role,string $language='tr'): array"],
  ["        if(!$event)return ['title'=>$title,'body'=>'Bildirim denemesi; mobil bildirim bağlantısı çalışıyor.'];","        $t=fn(string $source,array $values=[])=>NotificationLanguage::text($source,$language,$values);\n        $title=$t($title);\n        if(!$event)return ['title'=>$title,'body'=>$t('Bildirim denemesi; mobil bildirim bağlantısı çalışıyor.')];"],
  ["            $body=$source.' '.$money.'yeni bir siparişiniz var.';",`            $body=$source.' '.$money.'yeni bir siparişiniz var.';
            if(in_array($language,['en','de'],true)){
                $provider=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pttavm'=>'PTT AVM','pazarama'=>'Pazarama','koctas'=>'Koçtaş','teknosa'=>'Teknosa','trendyol'=>'Trendyol','amazon'=>'Amazon'][strtolower($event['provider']??'')]??$t('Pazaryeri');
                $formatted=$money!==''?number_format($amount/100,2,$language==='de'?',':'.',$language==='de'?'.':',').' TRY':'';
                $body=$formatted!==''?$t("{0}'dan {1} tutarında yeni bir siparişiniz var.",[$provider,$formatted]):$t("{0}'dan yeni bir siparişiniz var.",[$provider]);
            }`],
  ["?'Sipariş iptal edildi.':$message['title'].'. '.$message['body'];","?$t('Sipariş iptal edildi.'):$t($message['title']).'. '.$t($message['body']);"],
  ["$body=$message['title'].'. '.$message['body'];","$body=$t($message['title']).'. '.$t($message['body']);"],
  ["$body=SupportResolutionNotifications::presentation()['body'];","$body=$t(SupportResolutionNotifications::presentation()['body']);"],
  ["$body='Yeni bir bildiriminiz var. Ayrıntıları uygulamada görüntüleyebilirsiniz.';","$body=$t('Yeni bir bildiriminiz var. Ayrıntıları uygulamada görüntüleyebilirsiniz.');"]
 ]
};
await fs.mkdir('verification/localization-20261025/before-notifications',{recursive:true});
for(const [file,pairs]of Object.entries(edits)){
 let source=await fs.readFile('php/src/'+file,'utf8'),original=source;
 for(const [before,after]of pairs){assert.ok(source.includes(before),'Kaynak bulunamadı: '+file+' '+before);source=source.replaceAll(before,after);}
 await fs.writeFile('verification/localization-20261025/before-notifications/'+file,original,{flag:'wx'});
 await fs.writeFile('php/src/'+file,source);console.log(file);
}
