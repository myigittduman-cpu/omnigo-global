<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountDirectory,AccountDirectoryWriter,AccountSettingsSchema,AccountDirectorySchema,AdminMfaSchema,
    ApplicationAdditiveSchema,ClientSessionOperator,StoreAccountCredentials,CustomerInitialPassword,CustomerUserProvisioning,HttpError,
    InventorySchema,NotificationSchema,Security};
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejected(callable $run,int $code):void {
    try{$run();}catch(HttpError $e){verify($e->getCode()===$code,$e->getMessage());return;}
    throw new RuntimeException('İstek beklenmedik şekilde kabul edildi.');
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
AccountSettingsSchema::install($db);InventorySchema::install($db);NotificationSchema::migrate($db);
ClientSessionOperator::install($db);AdminMfaSchema::install($db);ApplicationAdditiveSchema::ensure($db);AccountDirectorySchema::ensure($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'root@example.invalid','Yönetici','fixture','admin',NULL)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];
$writer=new AccountDirectoryWriter($db);$directory=new AccountDirectory($db);
$account=['name'=>'Örnek','email'=>'member@example.invalid','username'=>'test.member','role'=>'client','companyId'=>1,'active'=>true,'password'=>'0123'];
$entry=$writer->save($admin,null,$account);$id=$entry['id'];
$raw=fn()=>$db->one('SELECT * FROM users WHERE id=?',[$id]);$updated=fn()=>AccountDirectory::revision($directory->row($id));
verify(!array_key_exists('password',$entry)&&!in_array('0123',$entry,true)&&!CustomerInitialPassword::required($db,$raw()),'Dizin parola göstermez ve ilk giriş zorunluluğu eklemez');
verify(Security::verifyPassword('0123',$raw()['password'])&&!Security::verifyPassword('123',$raw()['password']),'Baştaki sıfır korunur');
$old=$raw()['password'];$writer->save($admin,$id,[...$account,'password'=>'','revision'=>$updated()]);
verify($raw()['password']===$old,'Şifresiz bilgi düzenlemesi mevcut parolayı korur');
foreach(['', ' ', str_repeat('ç',65),"a\0b"] as $pw)rejected(fn()=>StoreAccountCredentials::set($db,$admin,1,$id,['password'=>$pw]),400);
rejected(fn()=>StoreAccountCredentials::set($db,$client,1,$id,['password'=>'8901']),403);
rejected(fn()=>StoreAccountCredentials::set($db,$admin,2,$id,['password'=>'8901']),404);
rejected(fn()=>$writer->save($admin,$id,[...$account,'revision'=>$updated(),'role'=>'admin','password'=>'']),400);
rejected(fn()=>$writer->save($admin,$id,[...$account,'revision'=>$updated(),'role'=>'admin','password'=>'8901']),400);
verify($raw()['password']===$old,'Başarısız istekler şifreyi değiştirmez');
$db->run('INSERT INTO sessions VALUES(?,?,?,?)',['session-test',$id,'csrf',time()+3600]);
StoreAccountCredentials::set($db,$admin,1,$id,['password'=>' 8901 ']);
verify(Security::verifyPassword(' 8901 ',$raw()['password'])&&!CustomerInitialPassword::required($db,$raw()),'Mağaza ayarlarından şifre kalıcı ve tam kaydedilir');
verify(!$db->one('SELECT token FROM sessions WHERE user_id=?',[$id]),'Açık oturum kapatılır');
$writer->save($admin,$id,[...$account,'revision'=>$updated(),'password'=>'0000']);
verify(Security::verifyPassword('0000',$raw()['password'])&&!CustomerInitialPassword::required($db,$raw()),'Merkezi düzenleme de kalıcıdır');
$sec=new Security($db,['origin'=>'http://localhost']);
$session=$sec->login(['email'=>$account['email'],'password'=>'0000','loginType'=>'email']);
verify($session['user']['mustChangePassword']===false&&$session['user']['companyId']===1&&!isset($session['user']['password']),'Normal girişte doğru mağaza açılır, parola yanıtlanmaz');
rejected(fn()=>$sec->login(['email'=>$account['email'],'password'=>'0123']),401);
echo "Mağaza ve dizin şifreleri, yetkiler, ilk giriş, oturum iptali ve sınırlar doğrulandı.\n";
