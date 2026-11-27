<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,UserPreferences,HttpError};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->run('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->run('CREATE TABLE user_preferences(user_id INTEGER,profile_key TEXT,payload TEXT,version INTEGER,updated INTEGER,PRIMARY KEY(user_id,profile_key))');
$db->run('INSERT INTO users VALUES(1),(2)');
$service=new UserPreferences($db);
$admin=['id'=>1,'name'=>'Yönetici','role'=>'admin'];
$client=['id'=>2,'name'=>'Aysun','role'=>'client','operatorName'=>'Aysun','company_id'=>1];
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
verify($service->read($client)['preferences']['menuStyle']==='modern','New and legacy accounts default to Modern');
$version=0;
foreach(['modern','inter','gold','prestige'] as $style){
 $saved=$service->save($client,['version'=>$version,'preferences'=>[...UserPreferences::defaults(),'theme'=>'burgundy','menuStyle'=>$style]]);
 $version=$saved['version'];
 verify($service->read($client)['preferences']['menuStyle']===$style,'Each style persists');
 verify($saved['preferences']['theme']==='burgundy','Existing palette is preserved');
}
$legacy=UserPreferences::defaults();unset($legacy['menuStyle']);
$saved=$service->save($client,['version'=>$version,'preferences'=>$legacy]);
verify($saved['preferences']['menuStyle']==='prestige','Older clients do not reset the menu');
verify($service->read($admin)['preferences']['menuStyle']==='modern','Other account is isolated');
verify($service->read([...$client,'operatorName'=>'Başka kişi'])['preferences']['menuStyle']==='modern','Named operator is isolated');
foreach([['version'=>$saved['version'],'preferences'=>[...UserPreferences::defaults(),'menuStyle'=>'bad']],['version'=>0,'preferences'=>UserPreferences::defaults()]] as $input){
 try{$service->save($client,$input);throw new RuntimeException('Invalid or stale save accepted');}
 catch(HttpError $error){verify(in_array($error->getCode(),[400,409],true),'Expected validation failure');}
}
echo "Menü tercihleri: kayıt, eski istemci, renk ve hesap ayrımı başarılı.\n";
