<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{HepsiburadaReadClient,HepsiburadaCredentials,HepsiburadaEnvironment,SyncFailure};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function rejected(callable $call,int $expected):void{try{$call();}catch(SyncFailure $error){check($error->httpStatus===$expected,'Hata kodu');return;}throw new RuntimeException('Hata bekleniyordu.');}
$credentials=HepsiburadaCredentials::fromArray(['merchantId'=>'12345678-1234-1234-1234-123456789012','username'=>'test','serviceKey'=>'synthetic-secret','userAgent'=>'synthetic_dev']);
$calls=[];$response=['status'=>200,'body'=>'{"items":null,"totalCount":0}'];
$client=new HepsiburadaReadClient(function($url,$headers)use(&$calls,&$response){$calls[]=$url;return $response;});
check($client->openOrders($credentials,time()+10)['items']===[],'Açıkça boş yanıt sıfır kalemdir.');
foreach(['open','packages','cancelled','shipped','delivered','undelivered'] as $feed){$client->page($credentials,$feed,10,time()+10);$url=end($calls);check(str_starts_with($url,'https://oms-external.hepsiburada.com/'),'Yalnız canlı OMS');check(str_ends_with($url,'offset=10&limit=10'),'Paket API sınırı korunur');}
$response=['status'=>200,'body'=>json_encode(array_fill(0,10,['orderNumber'=>'123']))];
check($client->page($credentials,'packages',0,time()+10)['hasMore'],'Başlık olmadan tam sayfadan sonra devam edilir');
// The real API omits older ready packages unless an explicit timespan is sent.
$olderPackage=new HepsiburadaReadClient(function($url){parse_str(parse_url($url,PHP_URL_QUERY),$query);return ['status'=>200,'body'=>json_encode((int)($query['timespan']??24)>=48?[['orderNumber'=>'9912345678']]:[])];});
check(count($olderPackage->page($credentials,'packages',0,time()+10)['items'])===1,'24 saati geçen hazır sipariş ilk aktarımda kaybolmaz');
$client->page($credentials,'shipped',0,time()+10);check(!str_contains(end($calls),'timespan='),'Kargodaki sipariş akışının dönemi değiştirilmez');
$response=['status'=>200,'body'=>'[]'];check(!$client->page($credentials,'packages',10,time()+10)['hasMore'],'Boş sayfa taramayı tamamlar');
$response=['status'=>200,'body'=>'{"items":[],"totalCount":100}'];rejected(fn()=>$client->page($credentials,'open',0,time()+10),502);
$response=['status'=>200,'body'=>json_encode(array_fill(0,11,[]))];rejected(fn()=>$client->page($credentials,'packages',0,time()+10),422);
$before=count($calls);rejected(fn()=>$client->page($credentials,'stock',0,time()+10),422);rejected(fn()=>$client->page($credentials,'open',-1,time()+10),422);rejected(fn()=>$client->order($credentials,'../escape',time()+10),422);check(count($calls)===$before,'Geçersiz yol ağa gitmez');
$response=['status'=>429,'retryAfter'=>123];try{$client->order($credentials,'1234567890',time()+10);}catch(SyncFailure $e){check($e->retryAfter===123,'Kuyruk bekleme süresi korunur');}
$response=['status'=>200,'body'=>'[]'];$client->order($credentials,'1234567890',time()+10);check(str_ends_with(end($calls),'/ordernumber/1234567890'),'Resmi sipariş detay yolu');
$sit=new HepsiburadaReadClient(function($url){check(str_starts_with($url,'https://oms-external-sit.hepsiburada.com/'),'Test ve canlı ayrımı');return ['status'=>200,'body'=>'[]'];},HepsiburadaEnvironment::Sandbox);$sit->page($credentials,'delivered',0,time()+10);
$response=['status'=>200,'body'=>'[]'];$client->claims($credentials,'Refunded',0,'2026-10-01 00:00','2026-10-17 16:00',time()+10);
check(str_contains(end($calls),'/status/Refunded?offset=0&limit=10&beginDate=2026-10-01+00%3A00&endDate='),'Talep tarihi ve statü açıkça sınırlanır');
rejected(fn()=>$client->claims($credentials,'../accept',0,'2026-10-01 00:00','2026-10-17 16:00',time()+10),422);
$response=['status'=>200,'body'=>'{}'];rejected(fn()=>$client->claims($credentials,'Refunded',0,'2026-10-01 00:00','2026-10-17 16:00',time()+10),422);
echo "Hepsiburada okuma: altı sipariş akışı, talep tarihleri, sayfalama, boş yanıt, sınır, hata, güvenli yollar ve ortam ayrımı geçti.\n";
