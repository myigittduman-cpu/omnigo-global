<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,NotificationSchema,SupportSchema,ShipmentSchema,DeliverySchema,DeliveryCipher,MailQueue,IntegrationAlertMonitor,IntegrationRecoveryEvidence,IntegrationOutageMail,NotificationMailPolicy,NotificationFeed,OrderNotifications,NotificationAudience};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);SupportSchema::migrate($db);ShipmentSchema::install($db);DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Firma'),(2,'İkinci Firma')");
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Omnigo','hash','admin',NULL),(2,'client@example.invalid','Müşteri','hash','client',1)");
$now=time()-2000;$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
foreach([1,2] as $company){
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'channels',?,?),(?,'integration_idefix','{}',?)",[$company,json_encode(['name'=>'idefix','status'=>'active']),gmdate('c'),$company,gmdate('c')]);
 $db->run("INSERT INTO jobs(company_id,provider,state,next_run,attempts) VALUES(?,'idefix','blocked',?,1)",[$company,$now]);
}
$config=['mail'=>['enabled'=>true,'from'=>'no-reply@example.invalid','transport'=>'mail','key'=>base64_encode(str_repeat('m',32))]];
$mail=new MailQueue($db,$config);$pushes=0;$cipher=DeliveryCipher::withKey(str_repeat('k',32));
$monitor=new IntegrationAlertMonitor($db,$mail,function()use(&$pushes){$pushes++;},new IntegrationRecoveryEvidence($db,$cipher));
$count=fn()=>(int)$db->one('SELECT COUNT(*) n FROM mail_outbox')['n'];
$incident=function(int $company)use($db){return json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$company])['payload'],true)['idefix'];};
$monitor->check(1,$now);$monitor->check(1,$now+899);
verify($count()===0,'15 dakikadan kısa kesinti e-posta üretmez');
verify($pushes===0&&(int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0,'Kesinti zil, ses veya push olayı oluşturmaz');
$monitor->check(1,$now+900);$monitor->check(1,$now+901);
verify($count()===1&&$incident(1)['outageMailQueued'],'Uzun kesinti yalnız bir kalıcı e-posta işi üretir');
$job=$db->one('SELECT * FROM mail_outbox');verify(strlen($job['id'])<=64,'Posta kimliği mevcut şemaya sığar');
verify(!str_contains($job['payload'],'Örnek Firma'),'Posta içeriği şifrelidir');
$sent=[];$mail->runOne(function($to,$subject,$body)use(&$sent){$sent[]=[$to,$subject,$body];return false;});
verify($db->one('SELECT state FROM mail_outbox')['state']==='pending','Posta servisi kesintisinde iş yeniden denenir');
$db->run('UPDATE mail_outbox SET next_run=0');$mail->runOne(function($to,$subject,$body)use(&$sent){$sent[]=[$to,$subject,$body];return true;});
verify(count($sent)===2&&$sent[1][0]==='admin@omnigoglobal.com','Posta yalnız Omnigo adresine gider');
verify(str_contains($sent[1][1],'15 dakika')&&str_contains($sent[1][2],'Örnek Firma')&&!str_contains($sent[1][2],'hash'),'Posta süre, mağaza ve bağlantı durumunu içerir; parola içermez');
verify($db->one('SELECT state FROM mail_outbox')['state']==='sent','Başarılı gönderim tamamlanır');
$monitor->check(1,$now+1500);verify($count()===1,'Aynı devam eden kesinti tekrar e-posta üretmez');
// Another tenant has its own independent timer and idempotency key.
$monitor->check(2,$now);$monitor->check(2,$now+900);verify($count()===2,'Müşterilerin kesinti zamanları birbirine karışmaz');
$heal=function(int $company,int $stamp)use($db){
 $db->run("UPDATE jobs SET state='pending',attempts=0,next_run=? WHERE company_id=?",[$stamp+60,$company]);
 $state=['phase'=>'ready','lastSuccessAt'=>gmdate('c',$stamp),'reconciliationReference'=>['from'=>gmdate('c',$stamp-3600),'asOf'=>gmdate('c',$stamp),'capturedAt'=>gmdate('c',$stamp),'orders'=>[],'deliveries'=>[],'shipments'=>[]]];
 $row=$db->one("SELECT id FROM records WHERE company_id=? AND kind='sync_idefix'",[$company]);
 if($row)$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($state),$row['id']]);else $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_idefix',?,?)",[$company,json_encode($state),gmdate('c')]);
};
$heal(2,$now+901);$monitor->check(2,$now+902);verify(!$incident(2)['open'],'Doğrulanan toparlanma bağlantı durumunu kapatır');
$mail->runOne(fn()=>throw new RuntimeException('Düzelen kesinti e-postası gönderilmemeli'));
verify((int)$db->one("SELECT COUNT(*) n FROM mail_outbox WHERE state='cancelled'")['n']===1,'Gönderimden önce düzelen bağlantının bekleyen postası iptal edilir');
verify((int)$db->one('SELECT COUNT(*) n FROM order_notifications')['n']===0,'Toparlanma da bildirim veya e-posta oluşturmaz');
// Healthy intervals restart the 15-minute timer even while catch-up is being verified.
$heal(1,$now+1501);$monitor->check(1,$now+1502);verify($count()===3,'Gönderilmiş uzun kesinti uyarısının doğrulanmış toparlanması bir e-posta işi üretir');$db->run("UPDATE jobs SET state='blocked',attempts=1 WHERE company_id=1");
$monitor->check(1,$now+1510);$monitor->check(1,$now+1600);verify($count()===3,'Yeni kısa kesinti eski kesintinin süresini devralmaz');
$monitor->check(1,$now+2410);verify($count()===4,'Yeni ve uzun kesinti yeni tek e-posta işi oluşturur');
$db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='channels'",[json_encode(['name'=>'idefix','status'=>'inactive'])]);
$monitor->check(1,$now+2411);verify(!$incident(1)['open'],'Pasife alınan kaynak için kesinti takibi kapanır');
verify(!NotificationMailPolicy::allows(['type'=>'integration','to'=>'admin@omnigoglobal.com']),'Eski anlık bağlantı e-postaları gönderilemez');
$db->run("INSERT INTO order_notifications(company_id,provider,order_key,created,event_type,payload) VALUES(1,'idefix','legacy',?,'integration','{}')",[gmdate('c')]);
verify((new NotificationFeed($db))->listing(1,1,0)['counts']['all']===0,'Eski bağlantı kayıtları listede ve zil sayısında görünmez');
$arrivals=(new OrderNotifications($db))->arrivals(0,true,true,true);
verify($arrivals['items']===[]&&$arrivals['cursor']>0,'Eski masaüstü sürümlerine bağlantı bildirimi gitmez; imleç ilerler');
verify(!NotificationAudience::permits(['role'=>'admin'],['event_type'=>'integration']),'Kuyrukta kalmış bağlantı push bildirimi de teslim edilemez');
echo "$checks uzun kesinti e-postası, süre, tekillik, retry, toparlanma ve tüm bildirim kanalları kontrolü geçti.\n";
