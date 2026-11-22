<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountDirectorySchema,AccountPortraitSource,AccountDirectory};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountDirectorySchema::ensure($db);
$db->run('CREATE TABLE user_login_names(user_id INT PRIMARY KEY,username TEXT)');
$db->run("INSERT INTO companies VALUES(1,'Vizyona'),(2,'Terzioğlu Shop'),(3,'Yeni Mağaza')");
$db->run("INSERT INTO users VALUES(1,'a@example.test','Omnigo','hash','admin',NULL),(2,'v@example.test','Vizyona A','hash','client',1),(3,'v2@example.test','Vizyona B','hash','client',1),(4,'t@example.test','Terzioğlu A','hash','client',2),(5,'n@example.test','Yeni Kullanıcı','hash','client',3)");
foreach([1,2,3,4,5] as $id)$db->run('INSERT INTO account_profiles(user_id,avatar,version) VALUES(?,?,1)',[$id,base64_encode('personal-'.$id)]);
$db->run("INSERT INTO company_profiles VALUES(1,'{}',?,2),(2,'{}',?,7)",[base64_encode('vizyona-logo'),base64_encode('terzioglu-logo')]);
$source=new AccountPortraitSource($db);$directory=new AccountDirectory($db);$user=fn(int $id)=>$directory->row($id);
check($source->image($user(1))===base64_encode('personal-1'),'Omnigo kişisel resmi korunur');
check($source->image($user(2))===$source->image($user(3)),'Aynı mağazanın mevcut kullanıcıları aynı logoyu alır');
check($source->image($user(2))!==$source->image($user(4)),'Mağaza görselleri birbirine karışmaz');
check(AccountDirectory::publicRow($user(4))['avatarVersion']===7,'Kullanıcı listesi mağaza logo sürümünü alır');
check($source->metadata($user(5))===null&&$source->image($user(5))===null,'Logosuz mağaza başka kişisel resmi kullanmaz');
$profiles=$db->all('SELECT * FROM account_profiles ORDER BY user_id');
$db->run('UPDATE company_profiles SET logo=?,version=version+1 WHERE company_id=1',[base64_encode('vizyona-new')]);
check($source->metadata($user(2))===3&&$source->image($user(3))===base64_encode('vizyona-new'),'Logo değişikliği bütün mevcut kullanıcılara yansır');
$db->run("INSERT INTO company_profiles VALUES(3,'{}',?,1)",[base64_encode('new-store-logo')]);
check($source->image($user(5))===base64_encode('new-store-logo'),'Kurulumdan sonra yüklenen logo mevcut kullanıcıya yansır');
$db->run("INSERT INTO users VALUES(6,'new@example.test','Sonradan Açılan','hash','client',3)");
check($source->image($user(6))===$source->image($user(5)),'Sonradan açılan kullanıcı da mağaza logosunu alır');
$db->run('UPDATE users SET company_id=2 WHERE id=3');check($source->image($user(3))===base64_encode('terzioglu-logo'),'Mağazası değişen kullanıcının eski mağaza resmi kalmaz');
$db->run('UPDATE company_profiles SET logo=NULL,version=version+1 WHERE company_id=2');check($source->metadata($user(3))===null&&$source->image($user(4))===null,'Logo kaldırıldığında eski görsel kullanılmaz');
check($profiles===$db->all('SELECT * FROM account_profiles ORDER BY user_id'),'Kişisel resimlere kopyalama veya silme yapılmaz');
echo "Mağaza profil resimleri: mevcut/yeni kullanıcı, sonradan logo, değişim, kaldırma, mağaza taşıma ve Omnigo ayrımı geçti.\n";
