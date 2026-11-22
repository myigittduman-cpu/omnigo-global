<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,RecentOrderSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,OrderDetails,OrderList,OrderDisplayStatus,PttavmOrderMapper,PttavmPricing,PttavmIdentity,ShipmentRepository,ShipmentOrderIngestor};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$packages=fn($statuses)=>[['details'=>['products'=>array_map(fn($status)=>['status'=>$status],$statuses)]]];
check(OrderDisplayStatus::label('n11',$packages(['Shipped']))==='Kargolandı','n11 sevk durumu');
check(OrderDisplayStatus::label('Pazarama',$packages(['11']))==='Teslim edildi','Pazarama teslim durumu');
check(OrderDisplayStatus::label('Pazarama',$packages(['7','8']))==='İade süreci başlatıldı / İade onaylandı','İade durumları karıştırılmaz');
check(OrderDisplayStatus::label('n11',$packages(['Shipped','Delivered','Other']))==='Kargolandı / Teslim edildi / Durum doğrulanamadı','Karışık ve bilinmeyen satırlar gizlenmez');
check(OrderDisplayStatus::label('n11',[['details'=>['superseded'=>true,'status'=>'Unpacked']],...$packages(['Delivered'])])==='Teslim edildi','Eski paket dışlanır');
check(OrderDisplayStatus::label('Hepsiburada',$packages(['Shipped']))===null,'Diğer pazaryerine durum müdahalesi yok');
$line=['lineItemId'=>1,'urun'=>'Örnek ürün','urunBarkod'=>'SKU','toplamIslemAdedi'=>1,'kdvDahilToplamTutar'=>30499,'indirimToplam'=>3454.89,'indirimPttavm'=>3454.89,'indirimTedarikci'=>0,'couponAmount'=>100,'siparisDurumu'=>'tamamlandi'];
$raw=['siparisNo'=>'PTT-TEST','islemTarihi'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'musteriAdi'=>'Teslimat','musteriSoyadi'=>'Alıcısı','faturaMusteriAdi'=>'Fatura','faturaMusteriSoyadi'=>'Kişisi','faturaTip'=>'Bireysel','siparisAdresi'=>'Teslimat adresi','siparisIli'=>'Ankara','siparisIlce'=>'Merkez','telefonNo'=>'05000000000','siparisUrunler'=>[$line]];
$raw['siparisUrunler'][0]['siparisDurumu']='kargo_yapilmasi_bekleniyor';
$mapped=PttavmOrderMapper::map($raw);$order=$mapped['order'];$pricing=$order['products'][0]['pricing'];
check($order['grossCents']===2704411&&$pricing['couponCents']===10000&&$pricing['platformDiscountCents']-$pricing['couponCents']===335489,'100 TL kupon yalnız bir kez düşülür');
check($order['refundsVerified']===false&&PttavmPricing::returnAmountVerified(['provider'=>'PTT AVM',...$order]),'Sıfır iade tutarı bilinir, banka geri ödeme teyidi uydurulmaz');
$raw['siparisUrunler'][0]['siparisDurumu']='iade';$returned=PttavmOrderMapper::map($raw);
check($returned['order']['returnCents']===2704411&&!$returned['delivery']['active']&&PttavmPricing::returnAmountVerified(['provider'=>'PTT AVM',...$returned['order']]),'İade tutarı ve evraktan dışlama korunur');
$wrong=$order;$wrong['returnCents']=1;check(!PttavmPricing::returnAmountVerified(['provider'=>'PTT AVM',...$wrong]),'Tutarsız iade doğrulanmaz');
check(!PttavmPricing::returnAmountVerified(['provider'=>'PTT AVM','products'=>[],'returnCents'=>0]),'Eksik kaynak sıfır iade sayılmaz');
$incomplete=$line;unset($incomplete['couponAmount']);check(!isset(PttavmPricing::line($incomplete,3049900,345489)['couponCents']),'Eksik kupon uydurulmaz');
$wrong=$line;$wrong['couponAmount']=9000;check(!isset(PttavmPricing::line($wrong,3049900,345489)['couponCents']),'Çelişkili kupon dökümü gösterilmez');
$identity=PttavmIdentity::display($mapped['delivery']);
check($identity['details']['customerName']===''&&$identity['details']['recipientName']==='Teslimat Alıcısı'&&$identity['details']['invoiceName']==='Fatura Kişisi','PTT üyelik / teslimat / fatura kimlikleri ayrılır');
$manual=$mapped['delivery'];$manual['manualCorrections']['buyerName']='Gerçek Sipariş Sahibi';$manual['details']['customerName']='Gerçek Sipariş Sahibi';
check(PttavmIdentity::display($manual)===$manual,'Elle doğrulanan sipariş sahibi korunur');
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Dokunulmayacak firma')");
$cipher=DeliveryCipher::withKey(str_repeat('s',32));$repo=new DeliveryRepository($db,$cipher);$details=new OrderDetails($db,$cipher);
ShipmentOrderIngestor::activate($db,1,time()-86400);ShipmentOrderIngestor::activate($db,2,time()-86400);
foreach([1,2] as $company){
 $db->transaction(fn()=>$repo->ingest($company,$mapped['delivery'],false));
 $db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'pttavm',$order['externalId'],json_encode($order),gmdate('c')]);
}
$before=$db->all('SELECT * FROM recent_orders');$deliveryBefore=$db->all('SELECT * FROM delivery_orders');$shipmentBefore=$db->all('SELECT * FROM shipment_entries');
$list=(new OrderList($db,$details))->listing(['role'=>'admin'],1,['from'=>date('Y-m-d'),'to'=>date('Y-m-d')]);
$item=$list['items'][0];check($list['total']===1&&$item['customerNames']===[]&&$item['recipientNames']===['Teslimat Alıcısı'],'Liste yalnız seçili firma ve doğru kimlik türünü gösterir');
$detail=$details->detail(['role'=>'admin'],1,$item['id']);check($detail['returnAmountVerified']&&$detail['refundsVerified']===false&&$detail['packages'][0]['customerNameUnavailable'],'Ayrıntı iade bilgisi ve kimlik kapsamı');
$shipment=(new ShipmentRepository($db,$cipher))->listing(1,['Ankara','PTT AVM',date('Y-m')])['items'][0];
check($shipment['customerNameSource']==='recipient'&&$shipment['recipientName']==='Teslimat Alıcısı','Her iki panelin ortak sevkiyat verisinde alıcı ayrımı');
check($before===$db->all('SELECT * FROM recent_orders')&&$deliveryBefore===$db->all('SELECT * FROM delivery_orders')&&$shipmentBefore===$db->all('SELECT * FROM shipment_entries'),'Gösterim düzeltmesi hiçbir firmanın kayıt, tutar veya evrak durumunu değiştirmez');
echo "Durum, kupon, iade doğrulaması, alıcı ayrımı ve firma izolasyonu geçti.\n";
