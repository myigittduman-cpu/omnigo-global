<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,IntegrationVault,IntegrationCredentialView,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function denied(callable $fn,int $code):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$code,'Beklenen yetki kodu');return;}throw new RuntimeException('İşlem reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$key=tempnam(sys_get_temp_dir(),'omnigo-credential-test-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$security=new Security($db,['origin'=>'https://example.test']);$view=new IntegrationCredentialView($db,$security,$vault);
 $admin=['role'=>'admin','id'=>1];$client=['role'=>'client','id'=>2,'company_id'=>1];
 $providers=['hepsiburada'=>['merchantId','username','serviceKey','userAgent'],'n11'=>['apiKey','apiSecret'],'pazarama'=>['sellerId','apiKey','apiSecret'],'koctas'=>['shopId','apiKey'],'pttavm'=>['apiKey','accessToken'],'idefix'=>['vendorId','apiKey','apiSecret'],'trendyol'=>['supplierId','apiKey','apiSecret']];
 foreach($providers as $provider=>$fields){
  $values=array_combine($fields,array_map(fn($f)=>'test-a-'.$f,$fields));$values['internalToken']='must-not-leave';
  $vault->save(1,$values,$provider);$vault->save(2,array_map(fn($v)=>'other-'.$v,$values),$provider);
  $before=$db->all('SELECT * FROM records ORDER BY id');$jobs=$db->all('SELECT * FROM jobs');
  denied(fn()=>$view->read($client,1,$provider),403);denied(fn()=>$view->read($client,2,$provider),403);
  $result=$view->read($admin,1,$provider);
  check($result['company']===1&&$result['provider']===$provider,'Mağaza kapsamı');
  check(array_keys($result['fields'])===$fields&&!isset($result['fields']['internalToken']),'Yalnız izinli alanlar');
  check($result['fields'][$fields[0]]==='test-a-'.$fields[0],'Başka müşteri bilgisi karışmamalı');
  check($before===$db->all('SELECT * FROM records ORDER BY id')&&$jobs===$db->all('SELECT * FROM jobs'),'Okuma API ayarını ve aktarım kuyruğunu değiştirmemeli');
 }
 denied(fn()=>$view->read($admin,999,'n11'),404);denied(fn()=>$view->read($admin,1,'invalid'),404);
 echo "API görünümü: yedi pazaryeri, yönetici yetkisi, müşteri ayrımı, alan sınırı ve salt okunur işlem geçti.\n";
}finally{unlink($key);}
