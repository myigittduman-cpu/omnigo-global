<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AdminAccounts,Security};
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$service=new AdminAccounts($db);
$created=$service->ensure([['name'=>'Birinci','email'=>'first@old.test'],['name'=>'İkinci','email'=>'second@old.test']]);
$profiles=[];$before=[];
foreach($created as $i=>$user){
 $profiles[]=['id'=>$user['id'],'name'=>$user['name'],'previousEmail'=>$user['email'],'email'=>'new'.$i.'@example.test'];
 $before[$user['id']]=$db->one('SELECT * FROM users WHERE id=?',[$user['id']]);
 $db->run('INSERT INTO password_resets(token_hash,user_id,expires) VALUES(?,?,?)',['old'.$i,$user['id'],time()+1800]);
 $db->run('INSERT INTO sessions(token,user_id,csrf,expires) VALUES(?,?,?,?)',['session'.$i,$user['id'],'csrf'.$i,time()+2592000]);
}
$service->changeEmails($profiles);
foreach($profiles as $profile){
 $expected=$before[$profile['id']];$expected['email']=$profile['email'];
 check($expected===$db->one('SELECT * FROM users WHERE id=?',[$profile['id']]),'Kimlik, şifre, yetki ve diğer alanlar korunmalı');
 check(!$db->one('SELECT * FROM password_resets WHERE user_id=?',[$profile['id']]),'Eski e-posta sıfırlama bağlantısı geçersiz olmalı');
 check((bool)$db->one('SELECT * FROM sessions WHERE user_id=?',[$profile['id']]),'Mevcut oturum korunmalı');
}
check($service->changeEmails($profiles)===$service->changeEmails($profiles),'Tekrar güvenli olmalı');
$conflict=$profiles;foreach($conflict as &$entry){$entry['previousEmail']=$entry['email'];$entry['email']='third@example.test';}unset($entry);
try{$service->changeEmails($conflict);throw new LogicException('Çakışma kabul edildi');}catch(RuntimeException $e){check($e->getMessage()==='E-posta başka bir hesapta kullanılıyor.','Çakışma reddedilmeli');}
check($db->one('SELECT email FROM users WHERE id=?',[$profiles[0]['id']])['email']===$profiles[0]['email'],'İkinci hesap hatasında ilk değişiklik geri alınmalı');
check(!$db->one('SELECT id FROM mail_outbox'),'E-posta kendiliğinden gönderilmemeli');
echo "E-posta değişikliği: aynı hesaplar, şifreler, oturumlar, eski bağlantı iptali ve geri alma doğrulandı.\n";
