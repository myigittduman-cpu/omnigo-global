<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,AccountDirectory,AccountDirectorySchema,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));AccountDirectorySchema::ensure($db);
\Omnigo\LoginIdentity::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci Mağaza'),(2,'İkinci Mağaza')");
for($id=1;$id<=11;$id++)$db->run('INSERT INTO users VALUES(?,?,?,?,?,?)',[$id,"u$id@example.test","Kullanıcı $id",'not-a-password',$id===1?'admin':'client',$id===1?null:($id<=8?1:2)]);
$service=new AccountDirectory($db);$admin=['id'=>1,'role'=>'admin'];$filter=['pageSize'=>3,'company'=>1];
$seen=[];for($page=1;$page<=3;$page++){$data=$service->listing($admin,[...$filter,'page'=>$page]);check($data['total']===7&&$data['pageCount']===3,'Mağazanın toplamı ve numaralı sayfalar doğru olmalı');check(count($data['items'])===($page===3?1:3),'Her sayfa en fazla üç kullanıcı olmalı');foreach($data['items'] as $user){check($user['companyId']===1,'Başka mağaza karışmamalı');$seen[]=$user['id'];}check(!str_contains(json_encode($data),'not-a-password'),'Parola listede olmamalı');}
check(count(array_unique($seen))===7,'Sayfalar arasında kayıp ve tekrar olmamalı');
check($service->listing($admin,[...$filter,'page'=>99])['page']===3,'Silme/filtre sonrası son geçerli sayfaya dönmeli');
check($service->listing($admin,[...$filter,'q'=>'u7@example.test'])['total']===1,'Arama ve mağaza filtresi birlikte çalışmalı');
$empty=$service->listing($admin,[...$filter,'role'=>'admin']);check($empty['total']===0&&$empty['page']===1&&$empty['pageCount']===1,'Boş sonuç güvenli sayfalanmalı');
try{$service->listing(['id'=>2,'role'=>'client','company_id'=>1],$filter);throw new RuntimeException('Müşteri erişimi kabul edildi');}catch(HttpError $e){check($e->getCode()===403,'Yönetici sınırı korunmalı');}
echo "Kullanıcı sayfalaması: üç kayıt, filtre, sayfa sınırı, eksik/tekrar, müşteri ayrımı ve parola gizliliği geçti.\n";
