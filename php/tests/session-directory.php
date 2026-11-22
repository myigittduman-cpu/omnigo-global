<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,SessionDirectory,AccountDirectorySchema,AccountLoginActivity,AccountSettingsSchema,AdminMfaSchema,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountDirectorySchema::ensure($db);AccountSettingsSchema::install($db);AdminMfaSchema::install($db);
$password='Local-Only-Password-1234';$hash=Security::hashPassword($password);$db->run("INSERT INTO companies VALUES(1,'Örnek Marka')");
foreach([1,2,3,4,5] as $id)$db->run('INSERT INTO users VALUES(?,?,?,?,?,?)',[$id,"user$id@example.invalid","Örnek Kullanıcı $id",$hash,$id===1?'admin':'client',$id===1?null:1]);
foreach([1=>4,2=>6] as $user=>$count)for($i=0;$i<$count;$i++)$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[hash('sha256',"$user-$i"),$user,'csrf',time()+3600]);
$db->run('INSERT INTO account_controls(user_id,active) VALUES(4,0)');
AccountLoginActivity::record($db,2);$db->run('UPDATE account_audit SET created=?',[time()-3600]);AccountLoginActivity::record($db,2);$recorded=$db->one("SELECT MAX(created) stamp FROM account_audit WHERE actor_id=2")['stamp'];AccountLoginActivity::record($db,3);
$key=tempnam(sys_get_temp_dir(),'omnigo-session-');file_put_contents($key,random_bytes(32));
try{
 $directory=new SessionDirectory($db,$key);$actor=[...$db->one('SELECT * FROM users WHERE id=1'),'token'=>hash('sha256','1-0')];
 $items=$directory->listing($actor)['items'];check(count($items)===4,'Kullanıcılar tek satırda, pasif hesap hariç listelenmeli');
 check($items[0]['current']&&$items[0]['userId']===1,'Mevcut oturum en başta olmalı');check(count(array_filter($items,fn($i)=>$i['userId']===2))===1,'Çok sayıda oturum kullanıcıyı tekrarlamamalı');
 $byId=array_column($items,null,'userId');check($byId[2]['lastLogin']===gmdate('c',(int)$recorded),'Son başarılı giriş esas alınmalı');check($byId[3]['lastLogin']!==null,'Çıkış yapmış kullanıcının son girişi de görünmeli');check($byId[5]['lastLogin']===null,'Bilinmeyen eski giriş zamanı üretilmemeli');
 check(!str_contains(json_encode($items),'csrf')&&!str_contains(json_encode($items),'"token"'),'Gizli oturum verileri yanıt içinde bulunmamalı');
 $client=[...$db->one('SELECT * FROM users WHERE id=2'),'token'=>hash('sha256','2-0')];$own=$directory->listing($client)['items'];check(count($own)===1&&$own[0]['current']&&$own[0]['userId']===2,'Müşteri yalnız mevcut kendi oturumunu görmeli');
 try{$directory->listing($client,['user'=>3]);throw new RuntimeException('Başka hesaba erişim kabul edildi');}catch(HttpError $e){check($e->getCode()===403,'Hesap izolasyonu korunmalı');}
 check(count($directory->listing($actor,['q'=>'2'])['items'])===1,'Arama kullanıcı bazında çalışmalı');
 $security=new Security($db,['origin'=>'https://portal.example.invalid','portal_role'=>'client']);$_SERVER['REMOTE_ADDR']='local-session-test';
 $before=count($db->all('SELECT * FROM account_audit'));try{$security->login(['email'=>'user2@example.invalid','password'=>'wrong']);}catch(HttpError $e){check($e->getCode()===401,'Hatalı parola reddedilmeli');}check(count($db->all('SELECT * FROM account_audit'))===$before,'Başarısız giriş son giriş zamanını değiştirmemeli');
 $security->login(['email'=>'user2@example.invalid','password'=>$password]);check(count($db->all('SELECT * FROM account_audit'))===$before+1,'Başarılı giriş zamanı kayıt altına alınmalı');
 check(count($directory->listing($actor)['items'])===4,'Yeni oturum kullanıcı listesine tekrar eklenmemeli');
 echo "Oturum özeti: tek kullanıcı satırı, mevcut oturum, gerçek son giriş, bilinmeyen eski tarih, pasif hesaplar, müşteri izolasyonu ve giriş kaydı kontrolleri geçti.\n";
}finally{unlink($key);}
