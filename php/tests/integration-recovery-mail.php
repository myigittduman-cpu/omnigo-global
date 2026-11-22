<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,ShipmentSchema,DeliverySchema,DeliveryCipher,MailQueue,IntegrationAlertMonitor,IntegrationRecoveryEvidence,IntegrationRecoveryMail,IntegrationOutageMail,NotificationMailPolicy};
$checks=0;function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function fixture(int $companies=1,bool $enabled=true):array{
 $db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
 NotificationSchema::migrate($db);ShipmentSchema::install($db);DeliverySchema::install($db);
 $db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Omnigo','hash','admin',NULL)");$now=time();
 for($company=1;$company<=$companies;$company++){
  $db->run('INSERT INTO companies VALUES(?,?)',[$company,'Test Firma '.$company]);
  $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'channels',?,?),(?,'integration_idefix','{}',?)",[$company,json_encode(['name'=>'idefix','status'=>'active']),gmdate('c'),$company,gmdate('c')]);
  $db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(?,'idefix','blocked',?,1)",[$company,$now-950]);
 }
 $config=['mail'=>['enabled'=>$enabled,'from'=>'no-reply@example.invalid','transport'=>'mail','key'=>base64_encode(str_repeat('m',32))]];
 $mail=new MailQueue($db,$config);$evidence=new IntegrationRecoveryEvidence($db,DeliveryCipher::withKey(str_repeat('k',32)));
 return [$db,$mail,new IntegrationAlertMonitor($db,$mail,null,$evidence),$now,$config,$evidence];
}
function incident(Database $db,int $company=1):array{return json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$company])['payload'],true)['idefix'];}
function heal(Database $db,int $company,int $now,array $extra=[]):void{
 $db->run("UPDATE jobs SET state='pending',attempts=0,next_run=? WHERE company_id=?",[$now+60,$company]);
 $state=[...['phase'=>'ready','lastSuccessAt'=>gmdate('c',$now),'reconciliationReference'=>['from'=>gmdate('c',$now-3600),'asOf'=>gmdate('c',$now),'capturedAt'=>gmdate('c',$now),'orders'=>[],'deliveries'=>[],'shipments'=>[]]],...$extra];
 $row=$db->one("SELECT id FROM records WHERE company_id=? AND kind='sync_idefix'",[$company]);
 if($row)$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($state),$row['id']]);else $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_idefix',?,?)",[$company,json_encode($state),gmdate('c')]);
}
function openWarning(Database $db,IntegrationAlertMonitor $monitor,int $now,int $company=1):void{$monitor->check($company,$now-950);$monitor->check($company,$now-1);}
function decoded(MailQueue $mail,Database $db,string $prefix):array{
 $job=$db->one('SELECT payload FROM mail_outbox WHERE id LIKE ?',[$prefix.'%']);
 $raw=base64_decode($job['payload'],true);$key=base64_decode(base64_encode(str_repeat('m',32)),true);
 return json_decode(openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)),true,16,JSON_THROW_ON_ERROR);
}

[$db,$mail,$monitor,$now,$config,$evidence]=fixture();openWarning($db,$monitor,$now);
$delivered=[];$mail->runOne(function($to,$subject,$body)use(&$delivered){$delivered[]=[$to,$subject,$body];return true;});
verify($db->one("SELECT state FROM mail_outbox WHERE id LIKE 'outage-%'")['state']==='sent','Ön koşul: kesinti uyarısı posta servisi tarafından kabul edildi');
// A working API without complete reconciliation still cannot produce a recovery email.
heal($db,1,$now,['pendingOrders'=>['not-ready']]);$monitor->check(1,$now);
verify(incident($db)['open']&&(int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===0,'Eksik sipariş aktarımı toparlanma e-postasını engeller');
heal($db,1,$now,['recentOrders'=>['scan'=>['page'=>2]]]);$monitor->check(1,$now);
verify(incident($db)['open'],'Yarım sayfa aktarımı toparlanmayı engeller');
heal($db,1,$now);$monitor->check(1,$now);$monitor->check(1,$now+1);
$job=$db->one("SELECT * FROM mail_outbox WHERE id LIKE 'recovery-%'");
verify(is_array($job)&&strlen($job['id'])<=64&&(int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===1,'Aynı incident için yalnız bir kalıcı toparlanma işi oluşur');
verify(!str_contains($job['payload'],'Test Firma')&&!incident($db)['open'],'Toparlanma şifrelidir ve doğrulanmış olay kapalıdır');
$payload=decoded($mail,$db,'recovery-');verify(NotificationMailPolicy::allows($payload)&&IntegrationRecoveryMail::current($db,$payload,$now,$evidence),'Gönderim anında aynı olay, sağlık ve mutabakat kanıtı doğrulanır');
$mail->runOne(function($to,$subject,$body){return false;});verify($db->one("SELECT state FROM mail_outbox WHERE id=?",[$job['id']])['state']==='pending','Posta hatasında aynı iş yeniden denenir');
$db->run('UPDATE mail_outbox SET next_run=0 WHERE id=?',[$job['id']]);$mail->runOne(function($to,$subject,$body)use(&$delivered){$delivered[]=[$to,$subject,$body];return true;});
verify($db->one("SELECT state FROM mail_outbox WHERE id=?",[$job['id']])['state']==='sent'&&count($delivered)===2,'Tek başarılı kurtarma gönderimi aynı outbox işiyle tamamlanır');
verify($delivered[1][0]==='admin@omnigoglobal.com'&&str_contains($delivered[1][1],'düzeldi')&&str_contains($delivered[1][2],'mutabakatı')&&!str_contains($delivered[1][2],'hash'),'Kurtarma mesajı yalnız Omnigo adresine ve hassas veri olmadan gider');
$monitor->check(1,$now+2);verify((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===2,'Tekrarlanan sağlıklı kontrol ilave e-posta üretmez');
verify((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0,'Kesinti/toparlanma zil veya push kaydı oluşturmaz');
$wrong=$payload;$wrong['companyId']=2;verify(!IntegrationRecoveryMail::current($db,$wrong,$now,$evidence),'Başka müşterinin sent uyarısı kullanılamaz');
$wrong=$payload;$wrong['to']='other@example.invalid';verify(!NotificationMailPolicy::allows($wrong),'Alıcı sabittir');

foreach(['pending','failed','cancelled'] as $warningState){
 [$db,$mail,$monitor,$now]=fixture();openWarning($db,$monitor,$now);$db->run('UPDATE mail_outbox SET state=?',[$warningState]);heal($db,1,$now);$monitor->check(1,$now);
 verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===0,'Gönderilmemiş '.$warningState.' kesinti uyarısına kurtarma e-postası yok');
}
[$db,$mail,$monitor,$now]=fixture();$monitor->check(1,$now-899);heal($db,1,$now);$monitor->check(1,$now);verify((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===0,'Kısa kesinti ne uyarı ne kurtarma e-postası üretir');
// An archived closed incident from an earlier release is not replayed.
[$db,$mail,$monitor,$now]=fixture();openWarning($db,$monitor,$now);$mail->runOne(fn()=>true);$state=json_decode($db->one("SELECT payload FROM records WHERE kind='integration_health'")['payload'],true);$state['idefix']['open']=false;$state['idefix']['recoveredAt']=gmdate('c',$now);unset($state['idefix']['recoveryMailPending']);$db->run("UPDATE records SET payload=? WHERE kind='integration_health'",[json_encode($state)]);heal($db,1,$now);$monitor->check(1,$now);
verify((int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n']===1,'Eski kapalı olaylar yeni sürümde tekrar e-postalanmaz');
// A new outage makes a previously queued recovery message stale before it is sent.
[$db,$mail,$monitor,$now]=fixture();openWarning($db,$monitor,$now);$mail->runOne(fn()=>true);heal($db,1,$now);$monitor->check(1,$now);$db->run("UPDATE jobs SET state='blocked',attempts=1");$monitor->check(1,$now+1);
$mail->runOne(fn()=>throw new RuntimeException('Yeni kesintide eski kurtarma gönderilmemeli'));
verify($db->one("SELECT state FROM mail_outbox WHERE id LIKE 'recovery-%'")['state']==='cancelled','Yeni kesinti eski toparlanma e-postasını gönderimden önce iptal eder');
// An explicitly disabled mail transport must not lose a newly verified recovery.
[$db,$mail,$monitor,$now,$config,$evidence]=fixture();openWarning($db,$monitor,$now);$mail->runOne(fn()=>true);$offlineConfig=$config;$offlineConfig['mail']['enabled']=false;
$offlineMonitor=new IntegrationAlertMonitor($db,new MailQueue($db,$offlineConfig),null,$evidence);heal($db,1,$now);$offlineMonitor->check(1,$now);
verify(!incident($db)['open']&&incident($db)['recoveryMailPending'],'Posta kapalıyken doğrulanmış toparlanma sonradan kuyruğa alınmak üzere tutulur');
$monitor->check(1,$now+1);verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===1,'Posta açılınca aynı kurtarma yalnız bir kez kuyruğa alınır');
// A warning already in the sender when recovery happens qualifies only after it is sent.
[$db,$mail,$monitor,$now]=fixture();openWarning($db,$monitor,$now);$db->run("UPDATE mail_outbox SET state='running'");heal($db,1,$now);$monitor->check(1,$now);
verify(incident($db)['recoveryMailPending']&&(int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===0,'Gönderimi süren kesinti uyarısı henüz kurtarma e-postası üretmez');
$db->run("UPDATE mail_outbox SET state='sent',payload=NULL");$monitor->check(1,$now+1);verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===1,'Eşzamanlı kesinti uyarısı kabul edildiğinde kurtarma kaybolmadan bir kez oluşur');
[$db,$mail,$monitor,$now]=fixture(2);foreach([1,2] as $company)openWarning($db,$monitor,$now,$company);$mail->runOne(fn()=>true);$mail->runOne(fn()=>true);heal($db,1,$now);$monitor->check(1,$now);
verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===1&&incident($db,2)['open'],'Bir müşterinin toparlanması diğer müşterinin kesintisini kapatmaz');
heal($db,2,$now);$monitor->check(2,$now);verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE id LIKE 'recovery-%'")['n']===2,'Müşterilerin kurtarma anahtarları bağımsızdır');
[$db,$mail,$monitor,$now]=fixture();openWarning($db,$monitor,$now);$mail->runOne(fn()=>true);heal($db,1,$now);$monitor->check(1,$now);heal($db,1,$now,['scan'=>['page'=>2]]);
$mail->runOne(fn()=>throw new RuntimeException('Gönderim anında yarım aktarım varsa kurtarma gönderilmemeli'));
verify($db->one("SELECT state FROM mail_outbox WHERE id LIKE 'recovery-%'")['state']==='cancelled','Gönderim anında yeni eksik aktarım kanıtı kurtarma e-postasını engeller');
echo "$checks toparlanma e-postası tekillik, gönderilmiş uyarı, tenant, mutabakat, retry ve eski olay koruması kontrolü geçti.\n";
