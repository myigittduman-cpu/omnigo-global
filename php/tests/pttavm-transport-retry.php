<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{PttavmReadTransport,SyncFailure};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$url='https://integration-api.pttavm.com/api/v1/orders/search?startDate=2026-10-01';
$calls=0;$pauses=[];
$transport=new PttavmReadTransport(function()use(&$calls){return ++$calls<3?['status'=>0,'error'=>35]:['status'=>200,'error'=>0,'body'=>'[]'];},function($delay)use(&$pauses){$pauses[]=$delay;});
check($transport->get($url,[],time()+10)['body']==='[]'&&$calls===3&&count($pauses)===2,'Geçici TLS kesintisi sınırlı tekrar sonrası düzelir');
foreach([['status'=>429,'error'=>0,'retryAfter'=>120],['status'=>503,'error'=>0],['status'=>0,'error'=>60],['status'=>200,'error'=>23]] as $response){
 $calls=0;$transport=new PttavmReadTransport(function()use(&$calls,$response){$calls++;return $response;},fn()=>null);
 try{$result=$transport->get($url,[],time()+10);check($result===$response,'HTTP sonucu kuyruk politikasına bırakılır');}catch(SyncFailure $e){check($response['error']!==0&&$e->httpStatus===503,'TLS sertifikası veya boyut hatası gizlenmez');}
 check($calls===1,'HTTP, sertifika ve yanıt sınırı hataları hemen tekrar edilmez');
}
$calls=0;$transport=new PttavmReadTransport(function()use(&$calls){$calls++;return ['status'=>0,'error'=>35];},fn()=>null);
try{$transport->get($url,[],time()+10);throw new RuntimeException('Sınır aşımı');}catch(SyncFailure){check($calls===3,'En fazla üç deneme');}
try{$transport->get($url,[],time()-1);throw new RuntimeException('Süre aşımı');}catch(SyncFailure){check($calls===3,'Süresi dolan iş bağlantı açmaz');}
try{$transport->get('https://other.invalid/',[],time()+10);throw new RuntimeException('Yanlış sunucu');}catch(SyncFailure $e){check($e->httpStatus===422&&$calls===3,'Sunucu adresi sabit');}
echo "PTT bağlantısı: geçici TLS, deneme/süre sınırı, HTTP bekleme ve sertifika koruması geçti.\n";
