<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AccountSettingsSchema,Database,HttpError,OperationMailPreference};

function verify(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
AccountSettingsSchema::install($db);
$first=new OperationMailPreference($db);
$second=new OperationMailPreference($db);

verify($first->read()===['enabled'=>true,'version'=>1],'Varsayılan tercih açık ve sürümlü olmalı.');
$saved=$first->save(false,1);
verify($saved===['enabled'=>false,'version'=>2]&&!$second->enabled(),'Aynı veritabanını kullanan süreçler kapalı tercihi görmeli.');
try{$second->save(true,1);throw new RuntimeException('Eski sürüm tercihi ezdi.');}
catch(HttpError $error){verify($error->getCode()===409,'Eski sekme için sürüm çakışması dönmeli.');}
verify($first->save(false,2)===['enabled'=>false,'version'=>2],'Aynı değer sürümü gereksiz artırmamalı.');
verify($second->save(true,2)===['enabled'=>true,'version'=>3]&&$first->enabled(),'Güncel sürüm tercihi yeniden açabilmeli.');
echo "Ortak operasyon e-posta tercihi, sürüm çakışması ve süreçler arası görünürlük geçti.\n";
