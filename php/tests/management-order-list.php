<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliveryCipher,DeliveryFulfillment,DeliveryMapper,DeliverySchema,FinanceSchema,HttpError,ManagementOrderProjection,ManagementSchema,OrderDetailMapper,OrderDetails,OrderList,PazaramaSalesMapper,RecentOrderSchema,ShipmentSchema};

function expect(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
function rejected(callable $read,int $status):void {try{$read();}catch(HttpError $error){expect($error->getCode()===$status,$error->getMessage());return;}throw new RuntimeException('İstek reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);ManagementSchema::install($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?)',[json_encode(['workspacePolicy'=>['pages'=>['orders'=>'hidden']]])]);
$cipher=DeliveryCipher::withKey(str_repeat('Q',32));
$detail=new OrderDetails($db,$cipher);$list=new OrderList($db,$detail);$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'company_id'=>1,'role'=>'client'];
$read=fn(array $params=[])=>$list->listing($admin,1,$params,true);
$order=function(int $company,string $number,array $products,string $state='Aktarıldı',string $date='2026-11-01T12:00:00+03:00')use($db){
    $value=['externalId'=>$number,'createdAt'=>$date,'state'=>$state,'orders'=>1,'grossCents'=>99999,'cancelCents'=>0,'returnCents'=>0,'products'=>$products];
    $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_n11',json_encode($value),$date]);
    return $db->insertId();
};
$package=function(int $company,string $number,string $id,string $city,string $recipient,array $line)use($db,$cipher){
    $external=$number.':'.$id;$details=['recipientName'=>$recipient,'invoiceName'=>'Fatura kişisi','recipientEmail'=>$id.'@test.invalid','customerType'=>'Kurumsal','province'=>$city,'district'=>$id.' ilçe','deliveryAddress'=>$id.' sokak','phone'=>'0500'.$id,'products'=>[$line]];
    $payload=['provider'=>'n11','externalId'=>$external,'orderNumber'=>$number,'buyerName'=>'Sipariş sahibi','address'=>$details['deliveryAddress'],'city'=>$city,'district'=>$details['district'],'phone'=>$details['phone'],'details'=>$details];
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,1,?,?,?)',[$company,'n11',$external,$city,$cipher->seal($company,'order:n11:'.$external,$payload),'hash',gmdate('c')]);
    return $payload;
};
$product=fn(string $id,?int $amount)=>['lineId'=>$id,'quantity'=>1,'title'=>'Ürün '.$id,'sku'=>'SKU-'.$id,'amountCents'=>$amount,'status'=>'Shipped'];
$one=$product('L1',400);$two=$product('L2',900);
$id=$order(1,'BIR',[$one,$two],'Aktarıldı','2021-10-02T12:00:00+03:00');
$p1=$package(1,'BIR','P1','Ankara','Alıcı bir',$one);$p2=$package(1,'BIR','P2','KAYSERİ','Alıcı iki',$two);
foreach([[$p1,$one,450],[$p2,$two,1050]] as [$p,$line,$amount]){
    $key=DeliveryFulfillment::key($p,$line);
    $db->run("INSERT INTO shipment_entries(company_id,provider,city,month,payload,source_key,row_status,created,updated) VALUES(1,'n11','Ankara','2021-10','',?,'shipped','t','t')",[$key]);
    $sid=$db->insertId();$payload=['manualStatusOverride'=>['enabled'=>true],'marketplaceShipping'=>'pending','invoiceCents'=>$amount];
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$sid,$payload),$sid]);
}
$foreign=$order(2,'BIR',[$one]);$package(2,'BIR','P3','İzmir','Diğer mağaza',$one);
for($i=0;$i<30;$i++)$order(1,'DİĞER-'.$i,[$one]);
$order(1,'İPTAL',[$one],'CANCELLED');
$order(1,'BEDEL YOK',[$one,$two]);$package(1,'BEDEL YOK','P4','İzmir','Tek paket',$one);
$row=$read(['province'=>'Kayseri','query'=>'P2@test.invalid']);
expect($row['total']===1&&$row['items'][0]['id']===$id,'İl ve arama sayfalamadan önce tüm dönem içinde birleşmeli.');
$item=$row['items'][0];expect($item['invoiceCents']===1500&&$item['displayRowStatus']==='shipped','Şehir dışı sevkiyat kaydının elle güncel bedelleri ve rengi kullanılmalı.');
expect(array_column($item['destinations'],'recipientName')===['Alıcı bir','Alıcı iki'],'Her adres kendi alıcısıyla ilişkili olmalı.');
expect($item['destinations'][1]===['recipientName'=>'Alıcı iki','email'=>'P2@test.invalid','phone'=>'0500P2','deliveryAddress'=>'P2 sokak','province'=>'Kayseri','district'=>'P2 ilçe','invoiceType'=>'Kurumsal'],'Teslimat ve fatura türü kaynakla eşleşmeli.');
expect($read()['total']===33&&$read()['hasMore']&&$read()['pageSize']===15&&count($read()['items'])===15,'Tümü ve sayfalama seçili mağazayla sınırlı olmalı.');
$second=$read(['page'=>1]);$last=$read(['page'=>2]);
expect(count($second['items'])===15&&count($last['items'])===3&&!$last['hasMore'],'33 sipariş 15, 15 ve 3 kayıt olmalı.');
$ids=array_column([...$read()['items'],...$second['items'],...$last['items']],'id');
expect(count(array_unique($ids))===33,'Sayfalar kayıpsız ve tekrarsız olmalı.');
$searched=$read(['query'=>'DİĞER','page'=>1]);
expect($searched['total']===30&&count($searched['items'])===15&&!$searched['hasMore'],'Arama da 15 kayıtlık sayfaları korumalı.');
expect($read(['query'=>'DİĞER-26','province'=>'Kayseri'])['total']===0,'Arama il filtresini atlamamalı.');
expect($read(['from'=>'2026-01-01','to'=>'2026-11-05','province'=>'Kayseri'])['total']===0,'Tarih ve il filtresi birleşmeli.');
expect($read(['provider'=>'trendyol'])['total']===0,'Pazaryeri filtresi geçerli olmalı.');
expect($read(['query'=>'İPTAL'])['items'][0]['displayRowStatus']==='cancelled','Sonlanmış iptal yöneticiye görünmeli.');
expect($read(['query'=>'BEDEL YOK'])['items'][0]['invoiceCents']===null,'Eksik paketlerden tam fatura tutarı üretilmemeli.');
expect($detail->detail($admin,1,$id)['id']===$id,'Kapalı müşteri menüsü yönetici detayını engellememeli.');
rejected(fn()=>$detail->detail($admin,1,$foreign),404);
rejected(fn()=>$list->listing($client,1,[],true),403);
rejected(fn()=>$list->listing($client,1,['from'=>'2026-01-01','to'=>'2026-01-02']),403);
rejected(fn()=>$read(['province'=>'örnek il']),400);
$old=$list->listing($admin,1,['from'=>'2021-10-01','to'=>'2021-10-31']);
expect(!array_key_exists('destinations',$old['items'][0])&&!array_key_exists('invoiceCents',$old['items'][0]),'Eski görünüm sözleşmesi korunmalı.');
$addresses=ManagementOrderProjection::destinations([['provider'=>'Trendyol','trendyolSourceItems'=>[['base'=>['recipientName'=>'Bir','province'=>'Bolu']],['base'=>['recipientName'=>'İki','province'=>'Bursa']]]]]);
expect(array_column($addresses,'province')===['Bolu','Bursa']&&$addresses[1]['recipientName']==='İki','Trendyol birleşik siparişin paket adresleri kaybolmamalı.');
$pricing=['originalCents'=>1024900,'sellerDiscountCents'=>20000,'platformDiscountCents'=>61494,'discountedCents'=>943406,
    'reportCents'=>1004900,'reportBasis'=>'seller_invoice','platformDiscountReported'=>true];
$oldPackage=['provider'=>'Pazarama','externalId'=>'pz-order','orderNumber'=>'640940970','details'=>['products'=>[$product('line-pz',1004900)]]];
$sale=['provider'=>'Pazarama','externalId'=>'pz-order','products'=>[['lineId'=>'line-pz','quantity'=>1,'grossCents'=>1004900,'pricing'=>$pricing]]];
$projection=new ManagementOrderProjection($db,$cipher);
expect($projection->amountAndStatus($sale,[$oldPackage])['invoiceCents']===943406,'Eski teslimattaki satıcı tutarı yerine aynı Pazarama satırının doğrulanmış alıcı tutarı gösterilmeli.');
expect($projection->amountAndStatus([...$sale,'grossCents'=>1004900],[])['invoiceCents']===943406,'Teslimatı saklanmayan eski siparişte doğrulanmış finans satırları kullanılmalı.');
expect($projection->amountAndStatus([...$sale,'grossCents'=>1004900,'cancelCents'=>200],[])['invoiceCents']===null,'Kısmi iptal içeren paketsiz siparişte fatura tahmin edilmemeli.');
unset($sale['products'][0]['pricing']);
expect($projection->amountAndStatus($sale,[$oldPackage])['invoiceCents']===null,'Kanıt yoksa eski Pazarama satıcı tutarı fatura sayılamaz.');
$oldPackage['details']['products'][0]['invoiceCents']=0;
expect($projection->amountAndStatus($sale,[$oldPackage])['invoiceCents']===0,'Pazaryerinden doğrulanan sıfır kaybolmamalı.');

// Reproduce the buyer-side amounts of archived Pazarama order 855897082 via the regular admin list.
$uuid=static fn(int $n):string=>sprintf('00000000-0000-4000-8000-%012d',$n);
$money=static fn(string $value):array=>['value'=>$value,'currency'=>'TL'];
FinanceSchema::install($db);
foreach ([8,10,7,9] as $status) {
    $approved=in_array($status,[8,10],true);
    $number=$status===8?'855897082':(string)(855898000+$status);
    $external=$uuid($status);
    $raw=['orderId'=>$external,'orderNumber'=>$number,'orderDate'=>'2026-07-20 12:30','currency'=>'TL','orderAmount'=>'86444.90','items'=>[]];
    foreach ([['30847.30','1720.80',11],['19107.60','1065.88',11],['36490.00','2035.56',$status]] as $i=>[$price,$discount,$lineStatus]) {
        $raw['items'][]=['orderItemId'=>$uuid(100*$status+$i),'quantity'=>1,'orderItemStatus'=>$lineStatus,
            'product'=>['name'=>'Test ürünü '.($i+1),'stockCode'=>'PZ-'.$i],
            'salePrice'=>$money($price),'totalPrice'=>$money($price),'discountAmount'=>$money('0.00'),
            'discountDescription'=>'Kampanya (Pazarama İndirimi - '.$discount.' TL)'];
    }
    $financial=PazaramaSalesMapper::order($raw);
    $delivery=DeliveryMapper::pazarama($raw);
    $delivery['details']=OrderDetailMapper::pazarama($raw,$delivery);
    $buyerLines=array_column($delivery['details']['products'],'invoiceCents');
    expect($buyerLines===[2912650,1804172,3445444]&&array_sum($buyerLines)===8162266,'Arşivdeki üç alıcı satırı indirimlerden sonra 81.622,66 ₺ olmalı.');
    expect($financial['products'][2]['returnCents']===($approved?3649000:0),'Finans satırında yalnız onaylı iadenin satıcı bazındaki bedeli yer almalı.');
    $stamp=$financial['createdAt'];
    $db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(1,'pazarama',?,?,?)",[$external,json_encode($financial,JSON_THROW_ON_ERROR),$stamp]);
    $db->run("INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(1,'Pazarama',?,'',0,?,?,?)",
        [$external,$cipher->seal(1,'order:Pazarama:'.$external,$delivery),'test',$stamp]);
    $db->run("INSERT INTO finance_entries(company_id,provider,external_key,transaction_date,expected_date,net_cents,state,updated) VALUES(1,'pazarama',?,'2026-07-21','2026-08-01',4199700,'due',?)",[$external,$stamp]);
    $byExternal=array_column($read(['provider'=>'pazarama','query'=>$number])['items'],null,'externalId');
    $item=$byExternal[$external]??[];
    expect(($item['externalId']??null)===$external,'Arşiv siparişi seçili mağazanın yönetici Siparişler listesinde bulunmalı: '.$number);
    expect($item['invoiceCents']===($approved?4716822:8162266),
        'Pazarama durum '.$status.' için yönetici fatura tutarı hatalı. Görünen kuruş: '.var_export($item['invoiceCents'],true));
    expect($item['displayRowStatus']!=='cancelled','Tek satır iadesi bütün siparişi iptal edilmiş göstermemeli.');
    expect($projection->amountAndStatus(['provider'=>'Pazarama',...$financial],[])['invoiceCents']===$item['invoiceCents'],
        'Teslimatı ayrıca saklanmamış arşiv siparişi aynı kanıtlı tutarı vermeli.');
}
$multiple=['provider'=>'Pazarama','externalId'=>'multi','products'=>[['lineId'=>'multi-line','quantity'=>2]]];
$group=['provider'=>'Pazarama','externalId'=>'multi','orderNumber'=>'multi','details'=>['products'=>[
    ['lineId'=>'multi-line','quantity'=>2,'sku'=>'MULTI','status'=>'8','invoiceCents'=>101]
]]];
expect($projection->amountAndStatus($multiple,[$group])['invoiceCents']===null,'Adedi belirsiz çoklu iade için tutar tahmin edilmemeli.');
$group['details']['products'][0]['refundedQuantity']=1;
expect($projection->amountAndStatus($multiple,[$group])['invoiceCents']===50,'1,01 ₺ tutarlı iki üründen biri iade edilince 0,51 ₺ iade payı bir kez düşülmeli.');
$sourceKey=Omnigo\DeliveryFulfillment::key($group,$group['details']['products'][0]);
$db->run("INSERT INTO shipment_entries(company_id,provider,city,month,ordered_at,payload,source_key,created,updated) VALUES(1,'Pazarama','Ankara','2026-11',?,'',?,?,?)",[$stamp,$sourceKey,$stamp,$stamp]);
$shipmentId=$db->insertId();
unset($group['details']['products'][0]['invoiceCents']);
$payload=['quantity'=>2,'invoiceBasis'=>'buyer_product','invoiceCents'=>943406,
    'marketplaceReturn'=>['quantity'=>2,'returnedQuantity'=>1,'remainingQuantity'=>1,'partial'=>true]];
foreach([[$payload,471703],[[...$payload,'invoiceCents'=>485751,'source'=>['locked'=>['invoiceCents']]],485751]] as [$saved,$expected]){
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$shipmentId,$saved),$shipmentId]);
    $projection->prime(1,[$group]);
    expect($projection->amountAndStatus($multiple,[$group])['invoiceCents']===$expected,
        'Kısmi iadede sevkiyattaki doğrulanmış ürün tutarı bir kez paylaştırılır; elle kaydedilmiş fatura tutarı korunur.');
}
expect($read(['provider'=>'n11','query'=>'BIR'])['items'][0]['invoiceCents']===1500,'n11 elle doğrulanmış sevkiyat tutarı korunmalı.');
echo "Yönetici Siparişler görünümü, tarih, il, arama, sayfalama, mağaza izolasyonu, çoklu paket, gerçek tutar, durum ve eski istemci doğrulandı.\n";
