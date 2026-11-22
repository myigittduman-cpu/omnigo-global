<?php
// Synthetic local preview only. This file is never included in the deployment package.
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$dir=dirname(__DIR__,2).'/data/php-preview';
if (!is_dir($dir)) mkdir($dir,0700,true);
$config=['origin'=>'http://127.0.0.1:8789','database'=>['dsn'=>'sqlite:'.$dir.'/preview.sqlite'],'setup_token_hash'=>''];
file_put_contents($dir.'/config.php',"<?php return ".var_export($config,true).';');
$db=new \Omnigo\Database($config['database']);
if (!$db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")) {
 $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
 $repository=new \Omnigo\Repository($db);$company=$repository->createCompany('Vizyona — ÖRNEK VERİLER');
 $hash=\Omnigo\Security::hashPassword('OMNIGO_DEMO_PASSWORD');
 $db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)',['yonetici@ornek.test','Örnek Yönetici',$hash,'admin',null]);
 $admin=['id'=>1,'role'=>'admin'];
 $db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)',['musteri@ornek.test','Örnek Müşteri',$hash,'client',$company]);
 $repository->save($admin,$company,'sales',['channel'=>'n11','month'=>date('Y-m'),'grossCents'=>1000000,'cancelCents'=>10000,'returnCents'=>5000,'orders'=>20,'source'=>'ÖRNEK VERİ — test amaçlı'],null);
 $repository->save($admin,$company,'tasks',['title'=>'ÖRNEK — İçerik kontrolü','description'=>'Bu kayıt yalnızca yerel test içindir.','channel'=>'n11','date'=>date('Y-m-d'),'status'=>'done','visibility'=>'client','owner'=>'Örnek Ekip'],null);
}
echo "Yerel PHP önizlemesi hazır.\n";
