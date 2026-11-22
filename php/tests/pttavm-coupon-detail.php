<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,RecentOrderSchema,IntegrationVault,MarketplaceReadClient,PttavmOrderMapper,PttavmCouponDetail,PttavmPricingWorker,SyncFailure};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$raw=['siparisNo'=>'PTT-TEST','islemTarihi'=>'2026-10-19T00:12:24','tedarikciFirmaAdi'=>'Test','siparisUrunler'=>[['lineItemId'=>123,'toplamIslemAdedi'=>1,'urun'=>'Ürün','urunBarkod'=>'X','kdvDahilToplamTutar'=>30499,'indirimToplam'=>3454.89,'indirimPttavm'=>3454.89,'indirimTedarikci'=>0,'couponAmount'=>0,'siparisDurumu'=>'tamamlandi']]];
$order=PttavmOrderMapper::map($raw)['order'];
$detail=$raw;$detail['siparisUrunler'][0]['couponAmount']=100;
$detail['siparisUrunler'][0]['indirimToplam']=$detail['siparisUrunler'][0]['indirimPttavm']=3354.89;
$enriched=PttavmCouponDetail::apply($order,$detail);
check($enriched['products'][0]['pricing']['couponCents']===10000&&$enriched['grossCents']===2704411,'Kupon hariç ayrıntı kaynağı toplamı değiştirmeden doğrulanır');
$strip=static function($v){unset($v['pricingDetailVerified']);foreach($v['products'] as &$line)unset($line['pricing']);return $v;};
check($strip($enriched)===$strip($order),'Finans, miktar, iptal ve iade bilgileri değişmez');
$inclusive=$detail;$inclusive['siparisUrunler'][0]['indirimToplam']=$inclusive['siparisUrunler'][0]['indirimPttavm']=3454.89;
check(PttavmCouponDetail::apply($order,$inclusive)===$enriched,'Kupon dahil varyant da doğrulanır');
$fresh=$order;$fresh['state']='İptal';$fresh['cancelCents']=$fresh['grossCents'];
$preserved=PttavmCouponDetail::preserve($fresh,$enriched);
check($preserved['products'][0]['pricing']['couponCents']===10000&&$preserved['cancelCents']===2704411,'Sonraki toplu sıfır kupon gerçek kuponu silmez; güncel iptal korunur');
$changed=$fresh;$changed['grossCents']++;check(PttavmCouponDetail::preserve($changed,$enriched)===$changed,'Tutar değişince eski döküm kullanılmaz');
foreach(['number','line','quantity','amount','seller'] as $bad){$input=$detail;
 if($bad==='number')$input['siparisNo']='OTHER';
 if($bad==='line')$input['siparisUrunler'][0]['lineItemId']=124;
 if($bad==='quantity')$input['siparisUrunler'][0]['toplamIslemAdedi']=2;
 if($bad==='amount')$input['siparisUrunler'][0]['kdvDahilToplamTutar']=30500;
 if($bad==='seller')$input['siparisUrunler'][0]['indirimTedarikci']=1;
 try{PttavmCouponDetail::apply($order,$input);throw new RuntimeException('Yanlış eşleşme kabul edildi.');}catch(SyncFailure){}
}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Dokunulmayacak')");
$key=tempnam(sys_get_temp_dir(),'ptt-coupon');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$config=['enabled'=>true,'revision'=>'r1','apiKey'=>'test','accessToken'=>'test','storeName'=>'Test'];$vault->save(1,$config,'pttavm');
 foreach([1,2] as $company)for($i=1;$i<=3;$i++){$p=$order;$p['externalId']='PTT-'.$i;$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'pttavm',$p['externalId'],json_encode($p),'unchanged']);}
 $other=$db->all('SELECT * FROM recent_orders WHERE company_id=2');$calls=0;
 $reader=new MarketplaceReadClient(function($url)use(&$calls,$detail){$calls++;$reply=$detail;$reply['siparisNo']=basename($url);return ['status'=>200,'body'=>json_encode([$reply])];});
 (new PttavmPricingWorker($db,$vault,$reader))->run(1,$config,time()+15);
 check($calls===2,'Her kuyruk işinde en fazla iki ayrıntı isteği');
 check($other===$db->all('SELECT * FROM recent_orders WHERE company_id=2'),'Diğer firmanın kayıtlarına dokunulmaz');
 $rows=$db->all('SELECT * FROM recent_orders WHERE company_id=1');$verified=0;
 foreach($rows as $row){$p=json_decode($row['payload'],true);$verified+=!empty($p['pricingDetailVerified']);check($p['grossCents']===2704411&&$row['updated']==='unchanged','Finans ve sipariş güncellik saati değiştirilmez');}
 check($verified===2,'İki döküm doğrulandı');
 $rate=new MarketplaceReadClient(fn()=>['status'=>429,'retryAfter'=>600]);
 (new PttavmPricingWorker($db,$vault,$rate))->run(1,$config,time()+10);
 $pending=json_decode($db->one("SELECT payload FROM recent_orders WHERE company_id=1 AND external_id='PTT-1'")['payload'],true);
 check($pending['pricingDetailRetryAt']>=time()+599&&!isset($pending['pricingDetailVerified']),'429 ertelenir; olmayan kupon doğrulanmaz');
 check($pending['pricingDetailError']['httpStatus']===429,'Kişisel bilgi içermeyen hata türü saklanır');
 $before=$db->all('SELECT * FROM recent_orders');(new PttavmPricingWorker($db,$vault,$reader))->run(1,$config,time()+10);
 check($before===$db->all('SELECT * FROM recent_orders')&&$calls===2,'Bekleme süresi bitmeden yeniden istek yapılmaz');
 // A concurrent status update must win over an older detail response.
 unset($pending['pricingDetailRetryAt'],$pending['pricingDetailAttempts']);$db->run("UPDATE recent_orders SET payload=? WHERE company_id=1 AND external_id='PTT-1'",[json_encode($pending)]);
 $concurrent=new MarketplaceReadClient(function($url)use($db,$detail,$pending){$new=$pending;$new['state']='İptal';$new['cancelCents']=2704411;$db->run("UPDATE recent_orders SET payload=? WHERE company_id=1 AND external_id='PTT-1'",[json_encode($new)]);$reply=$detail;$reply['siparisNo']=basename($url);return ['status'=>200,'body'=>json_encode([$reply])];});
 (new PttavmPricingWorker($db,$vault,$concurrent))->run(1,$config,time()+10);
 $latest=json_decode($db->one("SELECT payload FROM recent_orders WHERE company_id=1 AND external_id='PTT-1'")['payload'],true);
 check($latest['cancelCents']===2704411&&!isset($latest['pricingDetailVerified']),'Eşzamanlı iptal eski döküm cevabıyla ezilmez');
 $wrongStore=new MarketplaceReadClient(fn()=>['status'=>200,'body'=>json_encode([[...$detail,'tedarikciFirmaAdi'=>'Diğer']])]);
 try{$wrongStore->pttavmDetail($config,'PTT-TEST',time()+10);throw new RuntimeException('Firma denetimi');}catch(SyncFailure $e){check($e->httpStatus===403,'Yanlış mağaza reddedilir');}
 // A 404/500 must neither erase an order nor stop the next detail in this lease.
 foreach([404,500] as $status){
  $p=$order;$p['externalId']='RETRY-'.$status;
  $db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(1,'pttavm',?,?,'fixed')",[$p['externalId'],json_encode($p)]);
 }
 $failureReader=new MarketplaceReadClient(fn($url)=>['status'=>(int)substr(basename($url),6)]);
 (new PttavmPricingWorker($db,$vault,$failureReader))->run(1,$config,time()+10);
 foreach([404,500] as $status){$row=$db->one('SELECT * FROM recent_orders WHERE external_id=?',['RETRY-'.$status]);$p=json_decode($row['payload'],true);
  check(($p['pricingDetailError']['httpStatus']??null)===$status,'Bir ayrıntı hatası sonraki kaydı durdurmaz');
  check($p['grossCents']===$order['grossCents']&&$p['products']===$order['products']&&$row['updated']==='fixed','404/500 tutarı, stok kaynağını ve tarihi değiştirmez');
  check(empty($p['pricingDetailVerified'])&&$p['pricingDetailRetryAt']>=time()+119,'Hatalı ayrıntı doğrulanmış sayılmaz ve kuyrukta ertelenir');
 }
 $p=json_decode($db->one("SELECT payload FROM recent_orders WHERE external_id='RETRY-404'")['payload'],true);
 $fresh=[...$order,'externalId'=>'RETRY-404'];check(isset(PttavmCouponDetail::preserve($fresh,$p)['pricingDetailError']),'Sonraki toplu aktarım ayrıntı hata geçmişini silmez');
 $reply=[...$detail,'siparisNo'=>'RETRY-404'];$recovered=PttavmCouponDetail::apply($p,$reply);check($recovered['pricingDetailVerified']&&!isset($recovered['pricingDetailError'],$recovered['pricingDetailRetryAt']),'Kaynak döndüğünde gerçek ayrıntı doğrulanır ve hata temizlenir');
}finally{unlink($key);}
echo "PTT kupon: kaynak varyantları, tutar/satır doğrulaması, güncel iptal, firma izolasyonu, 429, iki istek sınırı ve yarış koruması geçti.\n";
