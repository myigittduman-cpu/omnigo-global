<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,IntegrationVault,DeliveryCipher,DeliveryRepository,ShipmentOrderIngestor,MarketplaceReadClient,PttavmOrderMapper,PttavmLegacyLink,PttavmSyncAdapter,MarketplaceSalesSummary,OrderList,OrderDetails,SyncFailure,SyncQueue};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fixture(string $number='PTT-ONE',string $status='kargo_yapilmasi_bekleniyor'):array{
 return ['siparisNo'=>$number,'islemTarihi'=>date('Y-m-d').'T09:00:00','tedarikciFirmaAdi'=>'Test Mağaza','musteriAdi'=>'Aynı','musteriSoyadi'=>'Müşteri','telefonNo'=>'05000000000','siparisAdresi'=>'Örnek teslimat adresi','siparisIli'=>'Ankara','siparisIlce'=>'Çankaya','eposta'=>'test@example.invalid','faturaTip'=>'Bireysel','faturaMusteriAdi'=>'Fatura','faturaMusteriSoyadi'=>'Adı','faturaAdresi'=>'Fatura sokağı','tckn'=>'11111111111','siparisUrunler'=>[['lineItemId'=>100,'urunBarkod'=>'P-1','urun'=>'Test Model123 Beyaz Eşya Ürünü Açıklaması','toplamIslemAdedi'=>2,'kdvDahilToplamTutar'=>200,'indirimToplam'=>20,'siparisDurumu'=>$status]]];
}
$a=fixture();$a['islemTarihi']=date('Y-m-d').'T00:00:00';$b=fixture('PTT-TWO');$b['islemTarihi']=date('Y-m-d').'T00:00:01';
$a['urunKodu']='00120';
$mapped=PttavmOrderMapper::batch([$a,$a,$b],'Test Mağaza');
check(count($mapped)===2&&$mapped[0]['order']['grossCents']===18000,'Tekrarlanan kaynak aynı kalır; ayrı sipariş ve indirim doğru');
check($mapped[0]['delivery']['details']['products'][0]['sellerStockCode']==='00120'
    &&$mapped[0]['order']['products'][0]['sku']==='P-1'
    &&$mapped[1]['delivery']['shipmentItems'][0]['payload']['sellerStockCode']==='','Stok kodu, barkod ve baştaki sıfırlar ayrı tutulur');
$secondLine=[...$a['siparisUrunler'][0],'lineItemId'=>101];
$separate=[...$a,'urunKodu'=>'00023','siparisUrunler'=>[$secondLine]];
$combined=PttavmOrderMapper::batch([$a,$separate])[0]['delivery']['details']['products'];
check(array_column($combined,'sellerStockCode','lineId')===[100=>'00120',101=>'00023'],'Aynı siparişin ayrı ürün kodları kendi satırlarına yazılır');
$grouped=PttavmOrderMapper::map([...$a,'siparisUrunler'=>[...$a['siparisUrunler'],$secondLine]]);
check(array_column($grouped['delivery']['details']['products'],'sellerStockCode')===['',''],'Birden çok satırda ortak üst alan ürün kodu kabul edilmez');
$catalogOrder=[...$a,'siparisUrunler'=>[[...$a['siparisUrunler'][0],'urunId'=>700],[...$secondLine,'urunId'=>701]]];
$catalogued=PttavmOrderMapper::batch([$catalogOrder],null,fn($id)=>['700'=>'00120','701'=>'00023'][$id]??null)[0];
check(array_column($catalogued['delivery']['details']['products'],'sellerStockCode')===['00120','00023']
    &&$catalogued['order']['grossCents']===36000,'Çok ürünlü siparişte her ürünün katalog kimliği kullanılır ve tutar korunur');
$conflict=PttavmOrderMapper::batch([$a,[...$a,'urunKodu'=>'00023']]);
check($conflict[0]['delivery']['details']['products'][0]['sellerStockCode']==='','Çelişen stok kodları boş bırakılır');
check(!str_contains(json_encode($mapped),'11111111111'),'Gereksiz kimlik numarası saklanmaz');
foreach(['gonderilmis'=>'shipped','gonderilmiş'=>'shipped','tamamlandi'=>'delivered','iptal'=>'cancelled'] as $source=>$expected)check(PttavmOrderMapper::map(fixture('PTT-ONE',$source))['delivery']['details']['products'][0]['shippingState']===$expected,'Durum eşlemesi');
foreach(['havale_onayi_bekleniyor','iade','gondericisine_teslim_edildi'] as $status)check(!PttavmOrderMapper::map(fixture('PTT-ONE',$status))['delivery']['active'],'Ödeme bekleyen/iade sevkiyata girmez');
try{PttavmOrderMapper::batch([$a],'Başka Mağaza');throw new RuntimeException('Yanlış mağaza');}catch(SyncFailure $e){check($e->httpStatus===403,'Mağaza kapsamı');}
$bad=$a;$bad['siparisUrunler'][0]['siparisDurumu']='unknown_state';
try{PttavmOrderMapper::map($bad);throw new RuntimeException('Bilinmeyen durum');}catch(SyncFailure $e){check($e->httpStatus===422,'Durum uydurulmaz');}
$legacyValue=$mapped[0]['delivery']['shipmentItems'][0]['payload'];$legacyValue['invoiceCents']=9000;
$legacy=[['id'=>1,'value'=>$legacyValue,'sourceKey'=>null,'removedAt'=>null]];
check(count(PttavmLegacyLink::plan($legacy,$mapped))===1,'Eski birim fiyatlı satır kesin kaynağa bağlanır');
try{PttavmLegacyLink::plan([...$legacy,['id'=>2,...array_diff_key($legacy[0],['id'=>1])]],$mapped);throw new RuntimeException('Çift eşleşme kabul edildi');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'birden fazla'),'Belirsiz eşleşme engellenir');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Test','x','admin',NULL)");
$key=tempnam(sys_get_temp_dir(),'ptt-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$config=['apiKey'=>'test','accessToken'=>'test','revision'=>'v1','enabled'=>true,'storeName'=>'Test Mağaza','startDate'=>date('Y-m-d').'T00:00:00+03:00','connectedAt'=>time(),'restoreShipments'=>true];
 $vault->save(1,$config,'pttavm');$cipher=new DeliveryCipher($key);$delivery=new DeliveryRepository($db,$cipher);ShipmentOrderIngestor::activate($db,1,time());
 $payloads=[$a,$b];$error=false;$headers=[];
 $reader=new MarketplaceReadClient(function($url,$requestHeaders)use(&$payloads,&$error,&$headers){
  check(str_starts_with($url,'https://integration-api.pttavm.com/api/v1/orders/search?'),'Yalnız sabit HTTPS okuma servisi');
  if(!$headers)usleep(1100000); // A real request duration must not schedule the next call before its cooldown.
  $headers[]=$requestHeaders;if($error)return ['status'=>429,'retryAfter'=>120];return ['status'=>200,'body'=>json_encode($payloads)];
 });
 $adapter=new PttavmSyncAdapter($db,$vault,$delivery,$reader);
 (new SyncQueue($db))->enqueue(1,'PTT AVM');(new SyncQueue($db,['PTT AVM'=>$adapter]))->runOne(null,null,time()+40);
 $scheduled=$db->one("SELECT next_run FROM jobs WHERE provider='PTT AVM'");
 $cadence=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_pttavm'")['payload'],true);
 check((int)$scheduled['next_run']>=$cadence['nextRequestAt'],'Yavaş API cevabı sahte 429 ve bağlantı alarmı oluşturacak erken tekrar planlamaz');
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode(['name'=>'PTT AVM','status'=>'active']),gmdate('c')]);
 (new SyncQueue($db))->enqueue(1,'PTT AVM');
 $source=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_pttavm'")['payload'],true);
 check(count($source['reconciliationReference']['orders'])===2,'Tam API taramasından bağımsız karşılaştırma kaynağı oluşturulur');
 check((new Omnigo\ReconciliationCompare($db,$cipher))->inspect(1,'pttavm',$source['reconciliationReference'])['total']===0,'Sipariş, evrak ve sevkiyat kaynakla eşleşir');
 check((new Omnigo\ConnectionHealth($db))->report(['role'=>'admin'])['items'][0]['status']==='ready','PttAVM yalnız okuma denemesi olarak gösterilmez');
 $recovery=new Omnigo\IntegrationRecoveryEvidence($db,$cipher);$incident=['detectedAt'=>gmdate('c',time()-60)];
 check($recovery->ready(1,'pttavm',$incident,time()),'Tamamlanmış ve eşleşen tarama bağlantı düzelmesini kanıtlar');
 $original=$db->one("SELECT id,payload FROM recent_orders WHERE provider='pttavm'");
 $wrong=json_decode($original['payload'],true);$wrong['grossCents']++;
 $db->run('UPDATE recent_orders SET payload=? WHERE id=?',[json_encode($wrong),$original['id']]);
 check(!$recovery->ready(1,'pttavm',$incident,time()),'Tutar uyumsuzluğu varken bağlantı toparlandı sayılmaz');
 $db->run('UPDATE recent_orders SET payload=? WHERE id=?',[$original['payload'],$original['id']]);
 $partial=$source;$partial['scan']=['cursor'=>'2026-01-01'];
 $db->run("UPDATE records SET payload=? WHERE kind='sync_pttavm'",[json_encode($partial)]);
 check(!$recovery->ready(1,'pttavm',$incident,time()),'Yarım tarama düzelme sayılmaz');
 $db->run("UPDATE records SET payload=? WHERE kind='sync_pttavm'",[json_encode($source)]);
 check($db->one("SELECT COUNT(*) n FROM recent_orders WHERE provider='pttavm'")['n']===2,'İki sipariş indekslendi');
 check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'Tarihsel eksikler bir kez aktarılır');
 $shipmentRows=$db->all('SELECT id,provider FROM shipment_entries ORDER BY id');
 $shown=(new Omnigo\ShipmentDataQuality($db,$cipher))->enrich(1,$shipmentRows);
 check(array_column($shown,'productCode')===['00120',''],'Sevkiyat tablosu doğrulanmış stok kodunu gösterir; barkodla doldurmaz');
 check($db->one('SELECT COUNT(*) n FROM delivery_orders')['n']===2,'Evrak kaynakları aktarıldı');
 check($headers[0]!==$headers[1],'Her isteğin korelasyon kimliği farklı');
 $reset=function()use($db){$s=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_pttavm'")['payload'],true);$s['nextRequestAt']=0;$db->run("UPDATE records SET payload=? WHERE kind='sync_pttavm'",[json_encode($s)]);};
 $reset();$adapter->syncReadOnly(1,time()+40);
 check($db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'Tekrar aktarım mükerrer oluşturmaz');
 $summary=(new MarketplaceSalesSummary($db))->dashboard(1,[['name'=>'PTT AVM']],date('Y-m'));
 check($summary['channels'][0]['available']&&$summary['channels'][0]['periods']['month']['grossCents']===36000,'Satış özeti ve indirimler');
 $list=(new OrderList($db,new OrderDetails($db,$cipher)))->listing(['role'=>'admin'],1,['provider'=>'pttavm','from'=>date('Y-m-d'),'to'=>date('Y-m-d')]);
 check($list['total']===2&&$list['items'][0]['id']<0,'Sipariş listesi ve ayrıntı kapsamı');
 $payloads[0]=fixture('PTT-ONE','iptal');$reset();$adapter->syncReadOnly(1,time()+40);
 check($delivery->listing(1,'Ankara','PTT AVM','all',0)['items'][0]['orderNumber']==='PTT-TWO','İptal yalnız ilgili siparişi evraktan çıkarır');
 $before=$db->all("SELECT * FROM recent_orders");$error=true;$reset();
 $db->run("UPDATE jobs SET next_run=0 WHERE provider='PTT AVM'");(new SyncQueue($db,['PTT AVM'=>$adapter]))->runOne();
 $job=$db->one("SELECT * FROM jobs WHERE provider='PTT AVM'");
 check($job['state']==='pending'&&(int)$job['next_run']>=time()+119,'429 bekleme ve tekrar kuyruğu');
 check($before===$db->all("SELECT * FROM recent_orders"),'Hata son başarılı kayıtları bozmaz');
 check(!str_contains(json_encode($db->all('SELECT payload FROM records')),'Test Model123'),'Kişisel sipariş ayrıntıları records tablosuna yazılmaz');
 echo "PTT AVM: kimlik, indirim, durum, eski satır eşleme, iki sipariş, tekrar, evrak, satış, rol ve 429 kuyruğu testleri geçti.\n";
}finally{unlink($key);}
