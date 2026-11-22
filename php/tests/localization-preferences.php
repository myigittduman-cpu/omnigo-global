<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountSettingsSchema,UserPreferences,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $action,int $status):void{try{$action();}catch(HttpError $e){check($e->getCode()===$status,'Beklenmeyen hata');return;}throw new RuntimeException('İşlem reddedilmedi');}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountSettingsSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','A','test-only','admin',NULL),(2,'b@example.test','B','test-only','client',1),(3,'c@example.test','C','test-only','client',2),(4,'aysun@example.test','Aysun Yurdakul','test-only','admin',NULL)");
$user=[...$db->one('SELECT * FROM users WHERE id=2'),'operatorName'=>'Örnek Kişi'];
$other=[...$db->one('SELECT * FROM users WHERE id=3'),'operatorName'=>'Örnek Kişi'];
$service=new UserPreferences($db);$initial=$service->read($user);
check($initial['preferences']['language']==='tr','Varsayılan Türkçe');
$en=$service->save($user,['preferences'=>[...$initial['preferences'],'language'=>'en'],'version'=>0]);
check($service->read($user)===$en,'İngilizce tercih yeniden okunur');
$legacy=$en['preferences'];unset($legacy['language']);$legacy['theme']='petrol';
$saved=$service->save($user,['preferences'=>$legacy,'version'=>1]);
check($saved['preferences']['language']==='en'&&$saved['preferences']['theme']==='parliament','Eski uygulama dil seçimini sıfırlamaz');
denied(fn()=>$service->save($user,['preferences'=>[...$saved['preferences'],'language'=>'fr'],'version'=>2]),400);
denied(fn()=>$service->save($user,['preferences'=>[...$saved['preferences'],'language'=>'de'],'version'=>1]),409);
$de=$service->save($user,['preferences'=>[...$saved['preferences'],'language'=>'de'],'version'=>2]);
check($de['preferences']['language']==='de','Almanca kaydedilir');
$white=$service->save($user,['preferences'=>[...$de['preferences'],'theme'=>'light'],'version'=>3]);
check($white['preferences']['theme']==='light'&&$service->read($user)==$white,'Beyaz tema hesapta kalıcıdır');
check($service->read($other)['preferences']['theme']==='parliament','Diğer müşterinin varsayılanı Parliament');
$classic=$service->save($user,['preferences'=>[...$white['preferences'],'theme'=>'classic-navy'],'version'=>4]);
check($service->read($user)==$classic&&$classic['preferences']['theme']==='classic-navy','Klasik laciverte geri dönülebilir');
$current=$classic;
foreach(['burgundy','petrol-smoke','graphite-copper'] as $theme){
    $current=$service->save($user,['preferences'=>[...$current['preferences'],'theme'=>$theme],'version'=>$current['version']]);
    $reloaded=$service->read($user);
    check($current['preferences']['theme']===$theme&&$reloaded['preferences']['theme']===$theme&&$reloaded['version']===$current['version'],'Tema hesapta kalıcıdır: '.$theme);
}
check($service->read($other)['version']===0,'Diğer müşteri değişmez');
check($service->read([...$user,'operatorName'=>'Başka Kişi'])['version']===0,'Aynı müşteri içindeki diğer işlemci değişmez');
check($service->read($db->one('SELECT * FROM users WHERE id=1'))['version']===0,'Yönetici tercihi değişmez');
check((int)$db->one('SELECT COUNT(*) n FROM companies')['n']===2,'Müşteri kayıtları değişmez');
$aysun=$db->one('SELECT * FROM users WHERE id=4');
check($service->read($aysun)['preferences']['theme']==='classic-navy','Aysun Yurdakul klasik temada kalır');
$otherLight=$service->save($other,['preferences'=>[...$initial['preferences'],'theme'=>'light'],'version'=>0]);
$admin=$db->one('SELECT * FROM users WHERE id=1');
$adminTheme=$service->save($admin,['preferences'=>[...$initial['preferences'],'theme'=>'burgundy'],'version'=>0]);
check($service->read($other)===$otherLight&&$otherLight['preferences']['theme']==='light','Yönetici seçimi diğer hesabın açık temasını değiştirmez');
check($service->read($admin)===$adminTheme&&$adminTheme['preferences']['theme']==='burgundy','Yönetici teması kendi hesabında kalıcıdır');
foreach(['petrol','graphite'] as $legacyTheme){
    $legacyPayload=json_encode([...$initial['preferences'],'theme'=>$legacyTheme],JSON_THROW_ON_ERROR);
    $db->run('UPDATE user_preferences SET payload=? WHERE user_id=?',[$legacyPayload,$admin['id']]);
    check($service->read($admin)['preferences']['theme']==='parliament','Eski kayıt yeni varsayılana güvenle döner: '.$legacyTheme);
    check($db->one('SELECT payload FROM user_preferences WHERE user_id=?',[$admin['id']])['payload']===$legacyPayload,'Tercihi okumak eski kaydı değiştirmez');
}
echo "Dil ve tema tercihleri: kalıcılık, eski uygulama uyumluluğu, geçersiz seçim, sürüm çakışması ve müşteri/işlemci izolasyonu geçti.\n";
