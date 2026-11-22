<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,LoginIdentity,HttpError,AccountSettingsSchema};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountSettingsSchema::install($db);
$password='Only-Local-Combined-1234';$hash=Security::hashPassword($password);
$db->run("INSERT INTO companies VALUES(1,'Örnek')");
$db->run("INSERT INTO users VALUES(1,'first@example.invalid','Birinci',?,'client',1),(2,'second@example.invalid','İkinci',?,'client',1)",[$hash,$hash]);
\Omnigo\AdminMfaSchema::install($db);\Omnigo\AccountDirectorySchema::ensure($db);LoginIdentity::install($db);
LoginIdentity::save($db,1,'ornek.kullanici');LoginIdentity::save($db,2,'ikinci.kullanici');
$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$security=new Security($db,['origin'=>'https://portal.example.invalid','portal_role'=>'client']);$_SERVER['REMOTE_ADDR']='local-combined-login';
foreach([' first@example.invalid ','FIRST@EXAMPLE.INVALID','ornek.kullanici','ORNEK.KULLANICI'] as $identifier){$result=$security->login(['loginType'=>'auto','email'=>$identifier,'password'=>$password]);verify($result['user']['id']===1,'E-posta ve kullanıcı adı aynı hesaba giriş yapar');}
foreach(['email'=>'first@example.invalid','username'=>'ornek.kullanici'] as $type=>$identifier)verify($security->login(['loginType'=>$type,'email'=>$identifier,'password'=>$password])['user']['id']===1,'Eski uygulamaların açık giriş türleri korunur');
try{$security->login(['loginType'=>'auto','email'=>'ornek.kullanici','password'=>'Wrong-Only-1234']);throw new RuntimeException('Yanlış şifre kabul edildi');}catch(HttpError $error){verify($error->getCode()===401,'Şifre kontrolü korunur');}
LoginIdentity::save($db,2,'first@example.invalid');verify(LoginIdentity::find($db,'auto','first@example.invalid')===null,'Başka hesabın e-postasıyla çakışan kullanıcı adı sessizce seçilmez');
verify(LoginIdentity::find($db,'email','first@example.invalid')['id']===1,'Eski açık türle e-posta hesabı değişmez');
echo "$checks birleşik giriş, eski uygulama uyumu, büyük/küçük harf ve hesap karışmasını önleme kontrolü geçti.\n";
