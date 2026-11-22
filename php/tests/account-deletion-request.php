<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AccountDeletionRequest,Database,MailQueue,CustomerManagement,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',1)");
$config=['mail'=>['enabled'=>true,'from'=>'sender@example.test','key'=>base64_encode(str_repeat('d',32))]];
$requests=new AccountDeletionRequest($db,$config);
try{$requests->create(['id'=>2],['confirmed'=>false]);throw new RuntimeException('Onaysız talep kabul edildi');}catch(HttpError $e){check($e->getCode()===400,'Onay şartı');}
$one=$requests->create(['id'=>2],['confirmed'=>true,'userId'=>1]);$again=$requests->create(['id'=>2],['confirmed'=>true]);
check($one['requestId']===$again['requestId']&&count($db->all('SELECT * FROM mail_outbox'))===1,'Günlük talep tekil');
$job=$db->one('SELECT * FROM mail_outbox');check((int)$job['user_id']===2&&!str_contains($job['payload'],'client@example.test'),'Hesap sunucudan ve içerik şifreli');
(new CustomerManagement($db))->revoke(2);check($db->one('SELECT state FROM mail_outbox')['state']==='pending','Oturum/şifre iptali silme talebini iptal etmez');
$captured=[];$queue=new MailQueue($db,$config);$queue->runOne(function($to,$subject,$body)use(&$captured){$captured=[$to,$subject,$body];return true;});
check($captured[0]==='admin@omnigoglobal.com'&&str_contains($captured[2],'client@example.test')&&!str_contains($captured[2],'admin@example.test'),'Doğru hesap ve sabit yönetici alıcısı');
check($db->one('SELECT state FROM mail_outbox')['state']==='sent','Kuyruk gönderimi');
$requests->create(['id'=>1],['confirmed'=>true]);check(count($db->all('SELECT * FROM records'))===0,'Yönetici talebi müşteri çalışma alanına sızmaz');
check(count($db->all('SELECT * FROM users'))===2,'Talep oluşturmak hesap/veri silmez');
echo "Hesap silme talebi: onay, hesap sınırı, şifreleme, tekrar koruması, kuyruk ve ortak verilerin korunması geçti.\n";
