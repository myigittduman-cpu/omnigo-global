<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,IntegrationVault,KoctasReturnSync,KoctasReturnMapper,PazaramaAuthClient,PazaramaReadClient,PazaramaSyncAdapter,ReturnRequestList,ReturnRequestMapper,SyncFailure};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$db->run("INSERT INTO companies VALUES(1,'Firma'),(2,'Diğer')");
$key=tempnam(sys_get_temp_dir(),'return-test');file_put_contents($key,random_bytes(32));$vault=new IntegrationVault($db,$key);
$now=gmdate('c');$start=(new DateTimeImmutable('first day of this month 00:00:00',new DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM);
$save=static fn($kind,$value)=>$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(1,?,?,?)',[$kind,json_encode($value),$now]);
$read=static fn($provider)=>json_decode($db->one('SELECT payload FROM records WHERE company_id=1 AND kind=?',['sync_'.$provider])['payload'],true);
$write=static fn($provider,$state)=>$db->run('UPDATE records SET payload=? WHERE company_id=1 AND kind=?',[json_encode($state),'sync_'.$provider]);
$due=static function()use($read,$write){$s=$read('koctas');$s['returnRequestJob']['nextRun']=0;$write('koctas',$s);};
try{
 $vault->save(1,['enabled'=>true,'shopId'=>123,'apiKey'=>'fake-key','startDate'=>$start],'koctas');
 $save('sync_koctas',['watermark'=>$now,'offset'=>7,'nextRequestAt'=>123]);
 $save('order_koctas',['externalId'=>'O1','returnCents'=>0,'products'=>[['lineId'=>'L1','title'=>'Doğru ürün']]]);
 $save('order_koctas',['externalId'=>'LEGACY','returnCents'=>1000,'products'=>[['title'=>'Eski onaylı iade','returnCents'=>1000]]]);
 $financialBefore=$db->all("SELECT * FROM records WHERE kind='order_koctas'");
 $claim=['id'=>'R1','order_id'=>'O1','state'=>'WAITING_ACCEPTANCE','date_created'=>$now,'last_updated'=>$now,'description'=>'PRIVATE','return_address'=>['street1'=>'PRIVATE'],'return_lines'=>[['order_line_id'=>'L1','quantity'=>1,'reason_code'=>'CHANGED_MIND']]];
 $calls=[];$mode='first';
 $request=function($path,$query)use(&$calls,&$mode,$claim){$calls[]=$query;check($path==='/api/returns','Yalnız RT11 çağrılır');
  if($mode==='429')throw new SyncFailure(429,7200);
  if($mode==='bad')return ['data'=>[['id'=>'broken']]];
  if($mode==='empty')return ['data'=>[]];
  if($mode==='first')return ['data'=>[$claim],'next_page_token'=>'page2'];
  if($mode==='second')return ['data'=>[$claim]];
  return ['data'=>[[...$claim,'state'=>'IN_PROGRESS']]];
 };
 $sync=new KoctasReturnSync($db,$vault,$request);check($sync->runDue(1,time()+30),'İlk görev çalışır');$state=$read('koctas');check(!isset($state['returnRequests'])&&isset($state['returnRequestJob']['scan']),'Eksik sayfa yayımlanmaz');check(!$sync->runDue(1,time()+30)&&count($calls)===1,'Beş dakika sınırı korunur');
 $due();$mode='429';$sync->runDue(1,time()+30);$state=$read('koctas');check($state['returnRequestJob']['nextRun']>=time()+7195&&isset($state['returnRequestJob']['scan']),'429 Retry-After ve sayfa korunur');
 $due();$mode='second';$sync->runDue(1,time()+30);$state=$read('koctas');check(count($state['returnRequests']['items'])===1&&!isset($state['returnRequestError']),'Tam tarama tekilleştirilir ve toparlanır');check($calls[2]===['page_token'=>'page2'],'İkinci sayfa kaynağın belirteciyle okunur');
 check($state['offset']===7&&$state['nextRequestAt']===123&&$state['watermark']===$now,'Sipariş imleci değişmez');
 $list=new ReturnRequestList($db);$view=$list->listing(['role'=>'admin'],1,['provider'=>'koctas','status'=>'pending']);check($view['total']===1&&$view['items'][0]['product']==='Doğru ürün'&&$view['items'][0]['amountCents']===null,'Bekleyen kayıt ve doğrulanmış ürün, tutar tahmini yok');check(!str_contains(json_encode($state),'PRIVATE'),'Kişisel alanlar taşınmaz');
 check($list->listing(['role'=>'admin'],1,['provider'=>'koctas'])['total']===2,'Eski onaylı finans kaydı korunur');
 $saved=$state['returnRequests'];$due();$mode='bad';$sync->runDue(1,time()+30);check($read('koctas')['returnRequests']===$saved,'Bozuk yanıtta son liste korunur');check($list->listing(['role'=>'admin'],1,['provider'=>'koctas'])['sources'][0]['state']==='stale','Eski veri güncel gösterilmez');
 $due();$mode='updated';$sync->runDue(1,time()+30);check($read('koctas')['returnRequests']['items'][0]['status']==='IN_PROGRESS','Durum değişikliği yeni satır açmadan yansır');
 $due();$mode='empty';$sync->runDue(1,time()+30);check($read('koctas')['returnRequests']['items']===[],'Yalnız tam boş yanıt eski talebi kaldırır');
 check($financialBefore===$db->all("SELECT * FROM records WHERE kind='order_koctas'"),'İade okuması finans/sipariş kayıtlarını değiştirmez');check(!$sync->runDue(2,time()+30),'Başka firma bağlantısı kullanılmaz');
 $due();$state=$read('koctas');$state['returnRequestJob']['leaseUntil']=time()+60;$write('koctas',$state);check(!$sync->runDue(1,time()+30),'Aktif kiralama ikinci işçiyi engeller');
 // Real Pazarama adapter, complete windows and provider failure between requests.
 $seller='00000000-0000-0000-0000-000000000001';$vault->save(1,['sellerId'=>$seller,'apiKey'=>'fake','apiSecret'=>'fake','enabled'=>true,'revision'=>'rev'],'pazarama');
 $token='x.'.rtrim(strtr(base64_encode(json_encode(['client_seller_id'=>$seller])),'+/','-_'),'=').'.x';
 $auth=new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600])]);
 $returnState=7;$rate=false;
 $raw=['orderId'=>'00000000-0000-0000-0000-000000000010','orderDate'=>(new DateTimeImmutable($start))->format('Y-m-d H:i'),'currency'=>'TL','orderAmount'=>100,'items'=>[['orderItemId'=>'00000000-0000-0000-0000-000000000011','quantity'=>1,'orderItemStatus'=>7,'totalPrice'=>['value'=>100,'valueInt'=>10000,'currency'=>'TL'],'product'=>['name'=>'Örnek ürün','stockCode'=>'SKU']]]];
 $reader=new PazaramaReadClient(function($url,$headers,$body)use(&$returnState,&$rate,$raw){if($rate)return ['status'=>429,'retryAfter'=>7200];$q=json_decode($body,true);$raw['items'][0]['orderItemStatus']=$returnState;return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>substr($q['startDate'],0,10)===substr($raw['orderDate'],0,10)?[$raw]:[]])];});
 $adapter=new PazaramaSyncAdapter($db,$vault,$auth,$reader);
 $cycle=static function()use($adapter,$read){for($i=0;$i<12;$i++){$adapter->syncReadOnly(1,time()+30);if(!isset($read('pazarama')['scan']))return;}throw new RuntimeException('Tam tarama bitmedi');};
 foreach([7=>'pending',8=>'approved',9=>'rejected',10=>'refunded'] as $status=>$group){$returnState=$status;$cycle();$state=$read('pazarama');check(count($state['returnRequests']['items'])===1,'Pazarama satırı tekrarsız');$view=$list->listing(['role'=>'admin'],1,['provider'=>'pazarama','status'=>$group]);check($view['total']===1&&$view['sources'][0]['coverage']==='order_line_returns','Pazarama durum filtresi');check($view['items'][0]['createdAt']===null&&$view['items'][0]['amountCents']===null,'Sipariş tarihi/tutarı talep bilgisi olarak gösterilmez');$order=json_decode($db->one("SELECT payload FROM records WHERE kind='order_pazarama'")['payload'],true);check($order['returnCents']===(in_array($status,[8,10])?10000:0),'Mevcut mali hesap korunur');}
 $saved=$read('pazarama')['returnRequests'];$rate=true;try{$adapter->syncReadOnly(1,time()+30);throw new RuntimeException('429 bekleniyordu');}catch(SyncFailure $error){check($error->httpStatus===429,'429 korunur');}check($read('pazarama')['returnRequests']===$saved,'Pazarama kesintide kayıt kaybetmez');
 $rate=false;$returnState=7;$cycle();check($list->listing(['role'=>'admin'],1,['provider'=>'pazarama','status'=>'pending'])['total']===1,'Pazarama otomatik toparlanma');
 echo "Pazarama/Koçtaş: tam tarama, tekilleştirme, durum, 429, kesinti/toparlanma, kiralama, firma sınırı ve mali hesap koruması geçti.\n";
}finally{unlink($key);}
