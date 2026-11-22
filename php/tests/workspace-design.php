<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{WorkspaceDesign,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $action,int $code):void{try{$action();}catch(HttpError $error){check($error->getCode()===$code,'Beklenmeyen yanıt');return;}throw new RuntimeException('İşlem reddedilmedi');}
$file=dirname(__DIR__,2).'/verification/balanced-design-20261031/workspace-design-test-'.bin2hex(random_bytes(8)).'.json';$store=new WorkspaceDesign($file);$admin=['id'=>1,'role'=>'admin'];
try{
 check($store->read()===['mode'=>'balanced','version'=>0],'Yeni kurulum ortak Dengeli tasarımla başlamalı');
 denied(fn()=>$store->save(['id'=>2,'role'=>'client'],['mode'=>'classic','version'=>0]),403);
 denied(fn()=>$store->save($admin,['mode'=>'unknown','version'=>0]),400);
 denied(fn()=>$store->save($admin,['mode'=>'classic','version'=>'0']),400);
 $classic=$store->save($admin,['mode'=>'classic','version'=>0]);check($classic===['mode'=>'classic','version'=>1],'Önceki düzene dönüş kalıcı olmalı');
 check((new WorkspaceDesign($file))->read()===$classic,'Yeni istek ve hesaplar aynı ayarı okumalı');
 denied(fn()=>$store->save($admin,['mode'=>'balanced','version'=>0]),409);
 check($store->save($admin,$classic)===$classic,'Aynı seçim gereksiz sürüm üretmemeli');
 check($store->save($admin,['mode'=>'balanced','version'=>1])===['mode'=>'balanced','version'=>2],'Dengeli düzene tekrar geçilebilmeli');
 file_put_contents($file,'{"mode":');check($store->read()===['mode'=>'classic','version'=>0],'Bozuk ayar uygulama açılışını engellememeli');
 check($store->save($admin,['mode'=>'balanced','version'=>0])===['mode'=>'balanced','version'=>1],'Ayar güvenli biçimde onarılabilmeli');
 echo "Ortak tasarım: varsayılan, yönetici yetkisi, önceki düzene dönüş, tüm hesaplarda kalıcılık, sürüm çakışması ve bozuk ayarda güvenli açılış geçti.\n";
}finally{foreach([$file,$file.'.lock'] as $entry)if(is_file($entry))unlink($entry);}
