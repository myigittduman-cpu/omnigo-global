<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryOrderIdentity,PttavmOrderMapper};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$checks=0;
function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$catalog=new InventoryCatalog($db);$catalog->ingest(1,'PTT AVM',['id'=>'100','sku'=>'MODEL-100','title'=>'Örnek ürün','quantity'=>5,'saleActive'=>true]);
$catalog->ingest(2,'PTT AVM',['id'=>'100','sku'=>'OTHER-100','title'=>'Başka mağaza ürünü','quantity'=>9,'saleActive'=>true]);
$identity=new InventoryOrderIdentity($db);$order=['products'=>[['lineId'=>'77','sku'=>'','sourceProductId'=>'100','quantity'=>2,'netQuantity'=>1,'grossCents'=>10000,'cancelCents'=>5000]]];
$before=$db->all('SELECT * FROM inventory_listings ORDER BY company_id,listing_key');
$after=$identity->enrich(1,'PTT AVM',$order);verify($after['products'][0]['sku']==='MODEL-100','Boş barkod aynı mağazanın kesin ürün kimliğinden çözülür');
verify($identity->enrich(2,'PTT AVM',$order)['products'][0]['sku']==='OTHER-100','Aynı uzak kimlik farklı müşterinin kodunu kullanmaz');
verify($identity->enrich(1,'n11',$order)===$order,'Diğer pazaryerleri değiştirilmez');
$packages=[['products'=>[['lineId'=>'77','sku'=>'','quantity'=>2],['lineId'=>'78','sku'=>'','quantity'=>2]]]];
$filled=$identity->packages('PTT AVM',$after,$packages);
verify($filled[0]['products'][0]['sku']==='MODEL-100'&&$filled[0]['products'][1]['sku']==='','Paket yalnız aynı sipariş satırından kod alır');
verify($identity->packages('n11',$after,$packages)===$packages,'Diğer pazaryerlerinin paketleri korunur');
verify($identity->packages('PTT AVM',$after,[['products'=>[['lineId'=>'77','sku'=>'','quantity'=>3]]]])[0]['products'][0]['sku']==='','Çelişkili adetli paket eşleştirilmez');
verify($db->all('SELECT * FROM inventory_listings ORDER BY company_id,listing_key')===$before,'Eşleştirme katalog kaydına yazmaz');
verify($identity->enrich(1,'PTT AVM',['products'=>[['sku'=>'SOURCE-CODE','sourceProductId'=>'100']]])['products'][0]['sku']==='SOURCE-CODE','Kaynağın dolu kodu değiştirilmez');
foreach(['999','100:VARIANT','','-1','100 OR 1=1',null] as $id)verify($identity->enrich(1,'PTT AVM',['products'=>[['sku'=>'','sourceProductId'=>$id]]])['products'][0]['sku']==='','Belirsiz kimlik için kod tahmin edilmez');
$catalog->ingest(1,'PTT AVM',['id'=>'200:variant','sku'=>'VARIANT','title'=>'Varyant','quantity'=>4,'saleActive'=>true]);
verify($identity->enrich(1,'PTT AVM',['products'=>[['sku'=>'','sourceProductId'=>'200']]])['products'][0]['sku']==='','Varyant üst kimliği tek bir varyanta eşlenmez');
verify(array_diff_assoc($after['products'][0],$order['products'][0])===['sku'=>'MODEL-100'],'Adetler, iptal ve satış tutarları aynen korunur');
verify($db->all('SELECT * FROM inventory_listings ORDER BY company_id,listing_key')!==[],'Katalog korunur');
$raw=['siparisNo'=>'PTT-ID-TEST','islemTarihi'=>'2026-10-31T10:00:00','tedarikciFirmaAdi'=>'Örnek','siparisUrunler'=>[['lineItemId'=>1,'urunId'=>100,'urunBarkod'=>null,'urun'=>'Örnek ürün','toplamIslemAdedi'=>2,'kdvDahilToplamTutar'=>100,'indirimToplam'=>0,'siparisDurumu'=>'kargo_yapilmasi_bekleniyor']]];
$mapped=PttavmOrderMapper::map($raw);verify($mapped['order']['products'][0]['sourceProductId']==='100','PTT ürün kimliği API eşlemesinde kaybolmaz');
verify($mapped['order']['products'][0]['sku']===''&&$mapped['order']['grossCents']===10000,'Kaynak barkodu ve mali kayıtlar değiştirilmez');
$raw['urunKodu']='MODEL-100';
$raw['siparisUrunler'][0]['urunBarkod']='4242006295608';
$mapped=PttavmOrderMapper::map($raw);
$package=$mapped['delivery']['details']+['orderNumber'=>$mapped['order']['externalId']];
$returns=new Omnigo\ApprovedReturnIndex('PTT AVM',[]);
$lines=Omnigo\InventoryOrderLines::normalize('PTT AVM',$mapped['order'],[$package],$returns);
$line=$lines[0];
verify($line['lineKey']===hash('sha256','PTT AVM:PTT-ID-TEST:4242006295608')&&$line['ordered']===2,
    'Geçmiş sipariş anahtarı ve miktarı ayrı korunur');
$resolver=new Omnigo\InventoryOrderProduct($db);
verify($line['sellerStockCode']==='MODEL-100'&&$resolver->resolve(1,$line)===$catalog->matchSku(1,'PTT AVM','MODEL-100'),
    'Gerçek satıcı kodu mevcut karta bağlanır');
$noBarcode=$package;
$noBarcode['products'][0]['sku']='';
$missingBarcode=$mapped['order'];
$missingBarcode['products'][0]['sku']='';
$lineWithoutBarcode=Omnigo\InventoryOrderLines::normalize('PTT AVM',$missingBarcode,[$noBarcode],$returns)[0];
verify($resolver->resolve(1,$lineWithoutBarcode)===$catalog->matchSku(1,'PTT AVM','MODEL-100'),
    'Doğrulanmış bir SKU varsa barkod zorunlu değildir');
$package['products'][0]['sellerStockCode']='';
$unknown=Omnigo\InventoryOrderLines::normalize('PTT AVM',$mapped['order'],[$package],$returns)[0];
try{$resolver->resolve(1,$unknown);throw new RuntimeException('Barkodla yeni kart açıldı');}
catch(DomainException){$checks++;}
$barcode='4006381333931';
$pazaramaSku='SKU-PZ-32';
$pazaramaCard=$catalog->ingest(1,'Pazarama',['id'=>$barcode,'sku'=>$pazaramaSku,'title'=>'Ürün','quantity'=>5]);
$catalog->ingest(2,'Pazarama',['id'=>$barcode,'sku'=>'OTHER-PZ','title'=>'Diğer ürün','quantity'=>2]);
verify($catalog->ingest(1,'n11',['id'=>'N11-32','sku'=>'sku-pz-32','title'=>'Farklı başlık','quantity'=>9])===$pazaramaCard,
    'İki pazaryerindeki aynı stok kodu tek karta bağlanır');
$rawPazarama=[
    'orderId'=>'7a153ecb-e9df-41e6-81e1-809cdf7fa944','orderDate'=>'2026-11-27 10:00','currency'=>'TL','orderAmount'=>25,
    'items'=>[['orderItemId'=>'33ddc167-f87e-4daa-a510-7adfd406028b','quantity'=>1,'orderItemStatus'=>12,
        'totalPrice'=>['value'=>25,'currency'=>'TL'],'product'=>['name'=>'Pazarama ürünü','code'=>$barcode]]],
];
$mappedPazarama=Omnigo\PazaramaSalesMapper::order($rawPazarama);
verify($mappedPazarama['products'][0]['sku']===$barcode&&$mappedPazarama['products'][0]['sellerStockCode']==='',
    'Pazarama finans kaynağı ile satıcı stok kodu ayrı kalır');
$pazaramaReturns=new Omnigo\ApprovedReturnIndex('Pazarama',[]);
$normalizePazarama=fn($company,$order)=>Omnigo\InventoryOrderLines::normalize(
    'Pazarama',$identity->enrich($company,'Pazarama',$order),[],$pazaramaReturns
)[0];
$pazaramaLine=$normalizePazarama(1,$mappedPazarama);
verify($pazaramaLine['sellerStockCode']===$pazaramaSku&&$resolver->resolve(1,$pazaramaLine)===$pazaramaCard,
    'Stok kodu olmayan Pazarama satırı aynı mağazanın kesin ilanından mevcut karta bağlanır');
verify($pazaramaLine['lineKey']===hash('sha256','Pazarama:'.$rawPazarama['orderId'].':'.$barcode)&&$pazaramaLine['invoiceCents']===2500,
    'Geçmiş sipariş satır anahtarı ve tutarı korunur');
verify($normalizePazarama(2,$mappedPazarama)['sellerStockCode']==='OTHER-PZ','Pazarama satıcı kodu mağazaya özeldir');

$legacyPazarama=$mappedPazarama;
unset($legacyPazarama['products'][0]['sellerStockCode'],$legacyPazarama['products'][0]['sourceProductId']);
verify($normalizePazarama(1,$legacyPazarama)['sellerStockCode']===$pazaramaSku,'Arşivlenmiş barkod alanı kesin ilanla doğrulanır');
$rawPazarama['items'][0]['product']['code']='5901234123457';
$unknownPazarama=$normalizePazarama(1,Omnigo\PazaramaSalesMapper::order($rawPazarama));
try{$resolver->resolve(1,$unknownPazarama);throw new RuntimeException('Doğrulanmamış Pazarama barkodundan stok kartı açıldı');}
catch(DomainException){$checks++;}
$rawPazarama['items'][0]['product']['stockCode']='001234';
$numericPazarama=$normalizePazarama(1,Omnigo\PazaramaSalesMapper::order($rawPazarama));
verify($numericPazarama['sellerStockCode']==='001234','Açıkça belirtilen sayısal stok kodunun baştaki sıfırları korunur');
$match=Omnigo\OrderSkuLines::match('Pazarama',[['lineId'=>'1','sku'=>$barcode,'sellerStockCode'=>$pazaramaSku]],[]);
verify($match===[0=>$pazaramaSku],'Sipariş görünümü paket olmadan da açıkça belirtilen stok kodunu kullanır');
echo "$checks PTT AVM ve Pazarama sipariş kimliği, stok kodu ve mağaza izolasyonu kontrolü geçti.\n";
