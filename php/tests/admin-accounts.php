<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AdminAccounts,Security};
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO users(id,email,name,password,role,company_id) VALUES(1,'mevcut@example.test','Mevcut yönetici',?,'admin',NULL)",[Security::hashPassword('Yerel-Test-Sifresi-123!')]);
$before=$db->one('SELECT * FROM users WHERE id=1');
$profiles=[['name'=>'Birinci Yönetici','email'=>'birinci@example.test'],['name'=>'İkinci Yönetici','email'=>'ikinci@example.test']];
$service=new AdminAccounts($db);
$created=$service->ensure($profiles);
check(count($created)===2&&$created[0]['created']&&$created[1]['created'],'İki ayrı hesap açılmalı');
check($created[0]['id']!==$created[1]['id'],'İşlem geçmişi ayrı kimlikler kullanmalı');
$first=$db->one('SELECT * FROM users WHERE id=?',[$created[0]['id']]);
$second=$db->one('SELECT * FROM users WHERE id=?',[$created[1]['id']]);
check($first['role']==='admin'&&$second['role']==='admin'&&$first['company_id']===null,'Yönetici rolü doğru olmalı');
check($first['password']!==$second['password']&&!Security::verifyPassword('',$first['password']),'Hesaplar boş veya ortak şifreyle açılmamalı');
check(!array_filter($service->ensure($profiles),fn($row)=>$row['created']),'Tekrar çalıştırma hesap çoğaltmamalı');
check($first===$db->one('SELECT * FROM users WHERE id=?',[$first['id']]),'Tekrar çalıştırma şifreyi değiştirmemeli');
check($before===$db->one('SELECT * FROM users WHERE id=1'),'Mevcut yönetici değişmemeli');
check(!$db->one('SELECT id FROM mail_outbox'),'İzinsiz e-posta gönderilmemeli');
$db->run('INSERT INTO account_controls(user_id,active) VALUES(?,0)',[$first['id']]);
try{$service->ensure($profiles);throw new LogicException('Pasif hesap etkinleştirildi');}catch(RuntimeException $error){check($error->getMessage()==='Mevcut hesap eşleşmiyor.','Pasif hesap korunmalı');}
try{$service->ensure([['name'=>'Yeni','email'=>'yeni@example.test'],['name'=>'Yanlış isim','email'=>'mevcut@example.test']]);throw new LogicException('Çakışma kabul edildi');}catch(RuntimeException $error){check($error->getMessage()==='Mevcut hesap eşleşmiyor.','Çakışan hesap değiştirilmemeli');}
check(!$db->one("SELECT id FROM users WHERE email='yeni@example.test'"),'Hatalı işlem tamamen geri alınmalı');
echo "Yönetici hesapları: ayrı kimlik, tekrar çalıştırma, mevcut erişim, posta ve geri alma kontrolleri geçti.\n";
