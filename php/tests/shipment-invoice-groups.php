<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,DeliveryMapper,DeliveryRepository,DeliverySchema,InventoryCatalog,InventorySchema,
    NotificationSchema,ShipmentInvoicePage,ShipmentInvoiceProducts,ShipmentOrderIngestor,ShipmentRepository,ShipmentSchema,ShipmentSourceProduct};

function check(bool $ok,string $reason):void
{
    if(!$ok)throw new RuntimeException($reason);
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);DeliverySchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek mağaza'),(2,'Diğer mağaza')");
$cipher=DeliveryCipher::withKey(str_repeat('r',32));
$shipments=new ShipmentRepository($db,$cipher);$deliveries=new DeliveryRepository($db,$cipher);
$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul'));
$stamp=$now->modify('-2 minutes')->format(DATE_ATOM);
$groupOrder='1234567890';

function importLine(Database $db,DeliveryRepository $deliveries,int $company,string $stamp,string $provider,string $number,string $id,string $code,string $status='pending',string $invoiceName='Fatura alıcısı',array $sourceProduct=[]):int
{
    $cancelled=$status==='cancelled';
    $line=['lineId'=>$id,'title'=>'Örnek '.$code,'sku'=>$code,'sellerStockCode'=>$code,'quantity'=>1,'cancelledQuantity'=>$cancelled?1:0,'shippingState'=>$status];
    if($sourceProduct)$line['sourceProduct']=$sourceProduct;
    $buyer=$number==='1234567890'?'Örnek FaturaTesti':'Diğer Alıcı';
    $delivery=DeliveryMapper::finish([
        'provider'=>$provider,'externalId'=>$number.':'.$id,'orderNumber'=>$number,'buyerName'=>$buyer,'phone'=>'05000000000','address'=>'Örnek',
        'city'=>'Ankara','district'=>'Çankaya','createdAt'=>$stamp,'status'=>'Sevk bekliyor','shippingState'=>$status,'active'=>!$cancelled,
        'products'=>[$line],'details'=>['products'=>[$line],...($provider==='Hepsiburada'&&$id==='first'?
            ['labelPackageId'=>'PK1','shippingBarcode'=>'001234567890','cargoCompany'=>'Örnek Kargo']:[])],'cancellation'=>[],
        'shipmentItems'=>[['key'=>hash('sha256',json_encode([$number,$id],JSON_THROW_ON_ERROR)),
            'payload'=>['orderedAt'=>$stamp,'province'=>'Ankara','customerName'=>$buyer,'invoiceName'=>$invoiceName,'productName'=>$line['title'],
                'quantity'=>1,'invoiceCents'=>2350,'marketplaceShipping'=>$status,
                'marketplaceCancellation'=>['quantity'=>1,'cancelledQuantity'=>$cancelled?1:0,'partial'=>false]]]],
    ]);
    $db->transaction(fn()=>$deliveries->ingest($company,$delivery,false));
    return (int)$db->one('SELECT MAX(id) AS id FROM shipment_entries')['id'];
}

$month=substr($stamp,0,7);
ShipmentOrderIngestor::activate($db,1,time()-172800);ShipmentOrderIngestor::activate($db,2,time()-172800);
$first=importLine($db,$deliveries,1,$stamp,'Hepsiburada',$groupOrder,'first','00123');
for($i=1;$i<=12;$i++)importLine($db,$deliveries,1,$stamp,'Hepsiburada','20000000'.$i,'x'.$i,'X-'.$i);
$second=importLine($db,$deliveries,1,$stamp,'Hepsiburada',$groupOrder,'second','00123');
$third=importLine($db,$deliveries,1,$stamp,'Hepsiburada',$groupOrder,'third','REF-2');
$cancelled=importLine($db,$deliveries,1,$stamp,'Hepsiburada',$groupOrder,'cancelled','00123','cancelled');
$otherProvider=importLine($db,$deliveries,1,$stamp,'n11',$groupOrder,'other','00123');
$otherInvoice=importLine($db,$deliveries,1,$stamp,'Hepsiburada',$groupOrder,'other-invoice','00123','pending','Başka fatura alıcısı');
$otherCompany=importLine($db,$deliveries,2,$stamp,'Hepsiburada',$groupOrder,'other-company','00123');

$scope=['all','all',$month];
$page=$shipments->listing(1,$scope,0,'',[],true);
$groups=$page['invoiceGroups'];
check(count($groups)===10&&$page['total']===16&&$page['totalRecords']===18,'Sayfalama sipariş grubu sayısını kullanır. '.json_encode([$groups,$page['total'],$page['totalRecords']]));
check(in_array([$third,$second,$first],$groups,true),'Bir siparişin tüm satırları ham sayfa sınırının ötesinden alınır.');
foreach([$cancelled,$otherProvider,$otherInvoice] as $id)check(in_array([$id],$groups,true),'İptal, farklı pazaryeri ve fatura kimliği ayrıdır.');
check(!in_array($otherCompany,array_column($page['items'],'id'),true),'Başka mağazanın satırı gelmez.');
$items=array_column($page['items'],null,'id');
check(!isset($items[$first]['shippingLabels']),'Standart liste paket barkodunu döndürmez.');
$withLabels=array_column($shipments->listing(1,$scope,0,'',[],true,includeShippingLabels:true)['items'],null,'id');
check($withLabels[$first]['shippingLabels']===[['packageId'=>'PK1','value'=>'001234567890','format'=>'CODE128','carrier'=>'Örnek Kargo']],
    'Kargo fişi seçeneği barkodu kesin sipariş satırı bağlantısıyla ve baştaki sıfırlarla döndürür.');
check(($withLabels[$second]['shippingLabels'][0]['value']??null)===null,'Bir paketin barkodu diğer pakete taşınmaz.');
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Test yönetici','hash','admin',NULL)");
$actor=['id'=>1,'role'=>'admin'];
$invoiceRows=array_map(fn($id)=>(object)['id'=>$id,'version'=>$items[$id]['version']],[$first,$second,$third]);
$invalid=[...$invoiceRows];
$invalid[2]=(object)['id'=>$third,'version'=>$items[$third]['version']+1];
try{
    $shipments->confirmInvoices($actor,1,$first,$scope,$invalid,true);
    throw new RuntimeException('Eski sürüm kabul edildi.');
}catch(\Omnigo\HttpError $error){check($error->getCode()===409,'Eski sürüm çakışması.');}
$unchanged=array_column($shipments->listing(1,$scope,0,'',[],true)['items'],null,'id');
check(!$unchanged[$first]['invoiceWorkDone'],'Hatalı bir kalem tüm grup güncellemesini geri alır.');
$marked=$shipments->confirmInvoices($actor,1,$first,$scope,$invoiceRows,true)['items'];
$reloaded=array_column($shipments->listing(1,$scope,0,'',[],true)['items'],null,'id');
foreach([$first,$second,$third] as $id)check($reloaded[$id]['invoiceWorkDone']&&!$reloaded[$id]['invoiced'],'Onay kalıcıdır ve belge durumundan bağımsızdır.');
check(!$reloaded[$otherProvider]['invoiceWorkDone'],'Diğer pazaryerinin siparişi etkilenmez.');
$shipments->confirmInvoices($actor,1,$first,$scope,array_map(fn($row)=>(object)$row,$marked),false);
$reloaded=array_column($shipments->listing(1,$scope,0,'',[],true)['items'],null,'id');
foreach([$first,$second,$third] as $id)check(!$reloaded[$id]['invoiceWorkDone'],'Geri alma tüm kalemlere uygulanır.');
check($items[$second]['productCode']==='00123'&&$items[$third]['productCode']==='REF-2'&&$items[$first]['invoiceCents']===2350,'Kaynak kodu ve fatura tutarı korunur.');
$last=$shipments->listing(1,$scope,40,'',[],true);
check($last['page']===1&&count($last['invoiceGroups'])===6,'Aralık dışı sayfa son geçerli gruba döner.');
check(!in_array($first,array_column($last['items'],'id'),true),'Bir sipariş ikinci sayfada tekrarlanmaz.');
$filtered=$shipments->listing(1,$scope,0,'FaturaTesti',['cancelled'=>'no'],true);
check($filtered['total']===3&&!in_array($cancelled,array_column($filtered['items'],'id'),true),'Arama ve durum filtresi gruplamayla birlikte çalışır. '.json_encode($filtered['invoiceGroups']));
$productSearch=$shipments->listing(1,$scope,0,'REF-2',[],true);
check($productSearch['invoiceGroups']===[[$third,$second,$first]],'Tek ürünü aramak siparişin diğer kalemlerini eksiltmez.');
$classic=$shipments->listing(1,$scope);
check($classic['total']===18&&count($classic['items'])===10&&!isset($classic['invoiceGroups']),'Klasik görünüm satır bazlı kalır.');
$exportIds=array_column($shipments->export(1,$scope)['items'],'id');
check(count($exportIds)===17&&!in_array($cancelled,$exportIds,true),'Dışa aktarım özgün etkin satırları korur.');

$legacyRow=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$first]);
$legacyPayload=$cipher->open(1,'shipment:'.$first,$legacyRow['payload']);
unset($legacyPayload['province']);
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$first,$legacyPayload),$first]);
$legacyBefore=$db->one('SELECT payload,version FROM shipment_entries WHERE id=?',[$first]);
$invoiceWithLegacy=$shipments->listing(1,$scope,0,'',[],true);
$legacyInvoiceItem=array_column($invoiceWithLegacy['items'],null,'id')[$first];
check($legacyInvoiceItem['province']==='Ankara','Fatura düzeninde eski kaydın kayıtlı teslimat ili korunur.');
foreach([false,true] as $customerView){
    $direct=new ShipmentRepository($db,$cipher,$customerView);
    $classicWithLegacy=$direct->listing(1,$scope,999);
    $legacyClassicItem=array_column($classicWithLegacy['items'],null,'id')[$first];
    check($legacyClassicItem['province']==='Ankara','Klasik düzende eski kaydın kayıtlı teslimat ili korunur.');
}
check($db->one('SELECT payload,version FROM shipment_entries WHERE id=?',[$first])===$legacyBefore,'İl gösterimi eski kayıtları değiştirmez.');

InventorySchema::install($db);$catalog=new InventoryCatalog($db);
$firstListing=['id'=>'offer-SS1161','sku'=>'SS1161','title'=>'Profilo SS1161 Su Sebili','barcode'=>'4242006295608',
    'invoiceModel'=>'SS1161','quantity'=>5];
$secondListing=[...$firstListing,'id'=>'offer-SS1162','title'=>'Profilo SS1162 Su Sebili','barcode'=>'4242006295615','invoiceModel'=>'SS1162'];
$catalog->ingest(1,'Hepsiburada',$firstListing);$catalog->ingest(1,'Hepsiburada',$secondListing);
$catalog->ingest(2,'Hepsiburada',[...$secondListing,'id'=>$firstListing['id']]);
$source=['listingId'=>$firstListing['id']];
$wrong=['listingId'=>$firstListing['id'],'barcode'=>$secondListing['barcode']];
$sameA=importLine($db,$deliveries,1,$stamp,'Hepsiburada','SKU-CASE','A','SS1161',sourceProduct:$source);
$sameB=importLine($db,$deliveries,1,$stamp,'Hepsiburada','SKU-CASE','B','SS1161',sourceProduct:$source);
$other=importLine($db,$deliveries,1,$stamp,'Hepsiburada','SKU-CASE','C','SS1161',sourceProduct:['listingId'=>$secondListing['id']]);
$unknown=importLine($db,$deliveries,1,$stamp,'Hepsiburada','SKU-CASE','D','SS1161');
$conflict=importLine($db,$deliveries,1,$stamp,'Hepsiburada','SKU-CASE','E','SS1161',sourceProduct:$wrong);
$view=$shipments->listing(1,$scope,0,'',[],true);
$groups=$view['invoiceGroups'];$items=array_column($view['items'],null,'id');
check(in_array([$conflict,$unknown,$other,$sameB,$sameA],$groups,true),'Aynı siparişte bütün kaynak kalemleri erişilebilir.');
$productKeys=array_column(array_intersect_key($items,array_flip([$sameA,$sameB,$other,$unknown,$conflict])),'invoiceProductIdentity');
check(count($productKeys)===5&&count(array_unique($productKeys))===1,'Aynı satıcı SKU’sunun tüm kaynak kalemleri tek üründe gösterilir.');
check(array_unique(array_column(array_intersect_key($items,array_flip([$sameA,$sameB,$other,$unknown,$conflict])),'invoiceCents'))===[2350],
    'Ürün kimliği doğrulaması kaynak tutarını değiştirmez.');
$probe=[1=>[['Hepsiburada',['title'=>'Örnek SS1161','sourceProduct'=>$source]]]];
check(ShipmentInvoiceProducts::identities($probe)===[],'Katalog kimliği tek başına satıcı SKU’su yerine geçmez.');
$probe[1][0][1]['sellerStockCode']=' SS1161 ';
check(ShipmentInvoiceProducts::identities($probe)[1]===$items[$sameA]['invoiceProductIdentity'],'Satıcı stok kodu kaynak başlığından bağımsızdır.');

$gtin='04242006295608';
$sources=[
    ['Hepsiburada',['sku'=>'HB000123','merchantSKU'=>'SS1161','productBarcode'=>'4242006295608'],'catalogId','HB000123'],
    ['n11',['productId'=>167,'barcode'=>'4242006295608'],'listingId','167'],
    ['Koçtaş',['offer_id'=>168,'product_sku'=>'KT-11'],'listingId','168'],
    ['Pazarama',['product'=>['code'=>'4242006295608']],'listingId','4242006295608'],
    ['idefix',['barcode'=>'4242006295608','productAttributes'=>[['attributeName'=>'Model','attributeValueName'=>'SS1161']]],'model','SS1161'],
    ['PTT AVM',['urunId'=>169,'urunBarkod'=>'4242006295608'],'listingId','169'],
    ['Trendyol',['barcode'=>'4242006295608'],'barcode',$gtin],
];
foreach($sources as [$provider,$raw,$field,$expected])check((ShipmentSourceProduct::read($provider,$raw)['sourceProduct'][$field]??null)===$expected,$provider.' kaynak ürün kimliği');
check(ShipmentSourceProduct::read('Hepsiburada',['sku'=>'SS1161','merchantSKU'=>'SS1161'])===[],'Satıcı SKU değeri katalog kimliği yerine kullanılamaz.');
$returnsPage=new ShipmentInvoicePage(0,10);
$sameOrder=['invoiceOrderIdentity'=>'RETURN-CASE','provider'=>'idefix','quantity'=>1];
$fullReturn=['returnedQuantity'=>1,'partial'=>false];
$returnsPage->add([
    [...$sameOrder,'id'=>1,'marketplaceReturn'=>$fullReturn],
    [...$sameOrder,'id'=>2,'marketplaceReturn'=>$fullReturn],
    [...$sameOrder,'id'=>3],
    [...$sameOrder,'id'=>4,'quantity'=>2,'marketplaceReturn'=>['returnedQuantity'=>1,'partial'=>true]],
    [...$sameOrder,'id'=>5,'quantity'=>2,'marketplaceReturn'=>['returnedQuantity'=>1,'partial'=>true]],
],array_fill_keys([1,2,3,4,5],true));
check($returnsPage->result()['invoiceGroups']===[[1,2],[3],[4],[5]],'Tam iadeler aynı siparişte toplanır, aktif ve kısmi iade kalemleri ayrı kalır.');
echo "Fatura düzeni: kesin sipariş bağı, sayfa sınırı, iptal, fatura ve mağaza ayrımı, kaynak tutarı ve Klasik düzen geçti.\n";
