<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountSettingsSchema,CustomerMenuLabels,CustomerMenuLabelScopes,HttpError};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountSettingsSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL),(2,'first@example.test','Birinci','hash','client',1),(3,'second@example.test','İkinci','hash','client',1),(4,'other@example.test','Diğer mağaza','hash','client',2)");
$original=json_encode(['workspacePolicy'=>['pages'=>['inventory'=>'shown'],'users'=>['2'=>['inventory'=>['view'=>true]]]],'other'=>['kept'=>true]]);
foreach([1,2] as $company)$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$original]);
$admin=$db->one('SELECT * FROM users WHERE id=1');$a=$db->one('SELECT * FROM users WHERE id=2');$b=$db->one('SELECT * FROM users WHERE id=3');$other=$db->one('SELECT * FROM users WHERE id=4');
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function rejected(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,'Yanlış ret durumu.');return;}throw new RuntimeException('İstek reddedilmedi.');}
$save=static function(string $scope,int $company,array $labels,int $userId=0)use($db,$admin):array{
 $current=CustomerMenuLabelScopes::read($db,$admin,$company,compact('scope','userId'));
 return CustomerMenuLabelScopes::save($db,$admin,$company,['scope'=>$scope,'userId'=>$userId,'version'=>$current['version'],'labels'=>$labels]);
};
$first=$save('store',1,['inbox'=>'Mağaza soruları']);
check((array)CustomerMenuLabelScopes::effective($db,1,$a)['labels']===['inbox'=>'Mağaza soruları'],'Mağazanın kullanıcılarına ad yansımadı.');
check((array)CustomerMenuLabelScopes::effective($db,2,$other)['labels']===[],'Başka mağazaya özel ad sızdı.');
$personal=$save('user',1,['inbox'=>'Kişisel sorular'],2);
check(((array)CustomerMenuLabelScopes::effective($db,1,$a)['labels'])['inbox']==='Kişisel sorular','Kişisel ad uygulanmadı.');
check(((array)CustomerMenuLabelScopes::effective($db,1,$b)['labels'])['inbox']==='Mağaza soruları','Kişisel ad diğer hesaba yansıdı.');
$global=$save('global',1,['inbox'=>'Genel sorular']);
foreach([[$a,1],[$b,1],[$other,2]] as [$actor,$company])check(((array)CustomerMenuLabelScopes::effective($db,$company,$actor)['labels'])['inbox']==='Genel sorular','Tüm altyapı kapsamı eski özel adları güncellemedi.');
check(CustomerMenuLabelScopes::effective($db,1,$a)['version']>$first['effective']['version'],'Canlı ad sürümü ilerlemedi.');
$save('user',1,['inbox'=>'Son kişisel ad'],2);
$save('global',1,['inbox'=>'Genel sorular','inventory'=>'Ürün stoğu']);
check(((array)CustomerMenuLabelScopes::effective($db,1,$a)['labels'])['inbox']==='Son kişisel ad','İlgisiz başka bir ad değişikliği kişisel adı ezdi.');
$save('user',1,[],2);
check(((array)CustomerMenuLabelScopes::effective($db,1,$a)['labels'])['inbox']==='Genel sorular','Kişisel ad sıfırlandığında üst kapsam dönmedi.');
$save('global',1,[]);
check(!isset(((array)CustomerMenuLabelScopes::effective($db,1,$b)['labels'])['inbox']),'Genel ad sıfırlanınca eski ad geri geldi.');
rejected(fn()=>CustomerMenuLabelScopes::read($db,$admin,1,['scope'=>'user','userId'=>4]),403);
rejected(fn()=>CustomerMenuLabelScopes::save($db,$a,1,['version'=>0,'labels'=>[]]),403);
rejected(fn()=>CustomerMenuLabelScopes::save($db,$admin,1,['scope'=>'store','version'=>0,'labels'=>['inbox'=>'Eski istek']]),409);
rejected(fn()=>CustomerMenuLabelScopes::save($db,$admin,1,['scope'=>'store','version'=>1,'labels'=>['unknown'=>'x']]),400);
$profile=json_decode($db->one('SELECT payload FROM company_profiles WHERE company_id=1')['payload'],true);
check($profile['workspacePolicy']===json_decode($original,true)['workspacePolicy']&&$profile['other']===['kept'=>true],'İzinler veya diğer ayarlar değişti.');
check((int)$db->one('SELECT COUNT(*) n FROM user_preferences WHERE user_id=4')['n']===0,'Diğer mağazanın kullanıcı ayarı değişti.');
echo "Menü kapsamları: kullanıcı/mağaza/genel, son değişiklik, sıfırlama, eşzamanlılık ve yetki koruması geçti.\n";
