<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,IntegrationVault,MarketplaceConnection,MarketplaceReadClient,MarketplaceProbeAdapter,SyncFailure};
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$db->run("INSERT INTO companies VALUES(1,'Test')");$key=tempnam(sys_get_temp_dir(),'api-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new MarketplaceConnection($db,$vault,'idefix');$connection->save(1,['version'=>0,'vendorId'=>'123','apiKey'=>'test-key','apiSecret'=>'test-secret']);
 check(!str_contains(json_encode($db->all('SELECT payload FROM records')),'test-secret'),'Şifreli kayıt');check(!str_contains(json_encode($connection->status(1)),'test-key'),'Anahtar tarayıcıya dönmez');
 $reader=new MarketplaceReadClient(function($url,$headers){check(str_starts_with($url,'https://merchantapi.idefix.com/oms/123/list?'),'Sabit HTTPS adresi');check(in_array('X-API-KEY: '.base64_encode('test-key:test-secret'),$headers,true),'Resmi yetkilendirme');return ['status'=>200,'body'=>json_encode(['items'=>[['vendorId'=>123]],'pageCount'=>1,'currentPage'=>1])];});
 (new MarketplaceProbeAdapter($db,$vault,'idefix',$reader))->syncReadOnly(1,time()+30);check($connection->status(1)['sync']['phase']==='verified','Kuyruk doğrulama sonucu');
 $config=['vendorId'=>'123','apiKey'=>'a','apiSecret'=>'b'];$bad=new MarketplaceReadClient(fn()=>['status'=>200,'body'=>json_encode(['items'=>[['vendorId'=>456]],'pageCount'=>1,'currentPage'=>1])]);
 try{$bad->orders('idefix',$config,'2026-10-01','2026-10-02',1,time()+30);throw new RuntimeException('Yanlış mağaza kabul edildi');}catch(SyncFailure $error){check($error->httpStatus===422,'Mağaza doğrulama');}
 $rate=new MarketplaceReadClient(fn()=>['status'=>429,'retryAfter'=>120]);try{$rate->orders('pttavm',['apiKey'=>'a','accessToken'=>'b'],'2026-10-01','2026-10-02',1,time()+30);throw new RuntimeException('429 kabul edildi');}catch(SyncFailure $error){check($error->retryAfter===120,'İstek sınırı korunur');}
 $ptt=new MarketplaceConnection($db,$vault,'pttavm');$ptt->save(1,['version'=>0,'apiKey'=>'ptt-test-key','accessToken'=>'ptt-test-token']);
 check(!str_contains(json_encode($db->all('SELECT payload FROM records')),'ptt-test-token'),'PTT token şifreli saklanmalı');
 check(!str_contains(json_encode($ptt->status(1)),'ptt-test-key'),'PTT anahtarı durum yanıtına çıkmamalı');
 $pttReader=new MarketplaceReadClient(function($url,$headers){
  check(str_starts_with($url,'https://integration-api.pttavm.com/api/v1/orders/search?'),'PTT sabit HTTPS adresi');
  check(in_array('Api-Key: ptt-test-key',$headers,true)&&in_array('Access-Token: ptt-test-token',$headers,true),'PTT yetki başlıkları');
  return ['status'=>200,'body'=>'[]'];
 });
 (new MarketplaceProbeAdapter($db,$vault,'pttavm',$pttReader))->syncReadOnly(1,time()+30);
 check($ptt->status(1)['sync']['phase']==='verified'&&$ptt->status(1)['sync']['sampleOnly']===true,'Erişim doğrulaması tamamlanmış aktarım gibi gösterilmemeli');
 check($vault->get(1,'idefix')['apiSecret']==='test-secret','PTT kaydı diğer pazaryerini değiştirmemeli');
 echo "PTT AVM / idefix: şifreli kayıt, yetkilendirme, mağaza kontrolü, kuyruk ve 429 kontrolleri geçti.\n";
}finally{unlink($key);}
