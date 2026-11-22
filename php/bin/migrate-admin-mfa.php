<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
$config=require(getenv('OMNIGO_CONFIG')?:dirname(__DIR__).'/config.php');
$db=new \Omnigo\Database($config['database']);
\Omnigo\AdminMfaSchema::install($db);
\Omnigo\BackupArchive::checkSchema($db);
if(file_put_contents(dirname(__DIR__).'/storage/admin-mfa.enabled',"1\n",LOCK_EX)===false)throw new RuntimeException('Güvenlik modülü etkinleştirilemedi.');
chmod(dirname(__DIR__).'/storage/admin-mfa.enabled',0600);
echo "Yönetici e-posta doğrulama şeması hazır; hesaplar kendileri etkinleştirene kadar mevcut giriş korunur.\n";
