<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,RecentOrderSchema,DeliveryCipher,ShipmentInvoiceAlignment};
function verify(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$folder=sys_get_temp_dir().'/invoice-test-'.bin2hex(random_bytes(8));mkdir($folder,0700);file_put_contents($folder.'/key',random_bytes(32));
$cipher=new DeliveryCipher($folder.'/key');$service=new ShipmentInvoiceAlignment($db,$cipher);
$add=function(int $id,string $provider,int $before,int $after,int $quantity=1,int $company=1)use($db,$cipher): void{
    $slug=['Pazarama'=>'pazarama','PTT AVM'=>'pttavm','Hepsiburada'=>'hepsiburada','n11'=>'n11','Koçtaş'=>'koctas','idefix'=>'idefix'][$provider];
    $identity='order-'.$id;$line='line-'.$id;
    $order=['externalId'=>$identity,'orders'=>1,'products'=>[['lineId'=>$line,'quantity'=>$quantity,'grossCents'=>$after]]];
    if($provider==='Pazarama')$order['products'][0]['pricing']=['reportBasis'=>'seller_invoice','reportCents'=>$after,'originalCents'=>1024900,
        'sellerDiscountCents'=>20000,'platformDiscountCents'=>61494,'discountedCents'=>943406,'platformDiscountReported'=>true];
    $db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,$slug,$identity,json_encode($order),gmdate('c')]);
    $delivery=['orderNumber'=>$identity,'details'=>['products'=>[['lineId'=>$line,'quantity'=>$quantity,'amountCents'=>$after]]]];
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$provider,$identity,'Ankara',1,$cipher->seal($company,'order:'.$provider.':'.$identity,$delivery),'hash-'.$id,gmdate('c')]);
    $value=['invoiceCents'=>$before,'quantity'=>$quantity,'customerName'=>'Korunmalı','fulfillment'=>['delivered'=>true],'source'=>['kind'=>'marketplace','provider'=>$provider,'locked'=>['customerName','invoiceCents']]];
    if($provider==='Pazarama'&&$id!==9)$value['source']['locked']=['customerName'];
    $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,source_key,row_status,completed,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$company,'Ankara',$provider,'2026-10',$cipher->seal($company,'shipment:'.$id,$value),ShipmentInvoiceAlignment::sourceKey($provider,$identity,$line),'completed',1,gmdate('c'),gmdate('c')]);
};
try{
    $add(1,'Pazarama',1004900,1004900);$add(2,'PTT AVM',1314501,2629002,2);
    $add(3,'n11',2299000,2299000);$add(4,'Hepsiburada',100,200);$add(5,'idefix',100,200);
    $add(6,'Koçtaş',100,200);$add(7,'PTT AVM',100,200);$add(8,'PTT AVM',100,200,1,2);
    $add(9,'Pazarama',1004900,1004900);
    $db->run('UPDATE shipment_entries SET invoiced=1 WHERE id=1');
    $db->run("INSERT INTO audit(company_id,action,record_id,after_value,created) VALUES(1,'shipment.edit',4,?,?)",[json_encode(['fields'=>['invoiceCents']]),gmdate('c')]);
    $db->run("UPDATE shipment_entries SET removed_at='2026-10-20' WHERE id=6");
    $wrong=json_decode($db->one("SELECT payload FROM recent_orders WHERE external_id='order-7'")['payload'],true);$wrong['products'][0]['quantity']=2;
    $db->run("UPDATE recent_orders SET payload=? WHERE external_id='order-7'",[json_encode($wrong)]);
    $skuOrder=json_decode($db->one("SELECT payload FROM recent_orders WHERE external_id='order-3'")['payload'],true);
    unset($skuOrder['products'][0]['lineId']);$skuOrder['products'][0]['sku']='SKU-3';
    $db->run("UPDATE recent_orders SET payload=? WHERE external_id='order-3'",[json_encode($skuOrder)]);
    $deliveryRow=$db->one("SELECT * FROM delivery_orders WHERE external_id='order-3'");
    $skuDelivery=$cipher->open(1,'order:n11:order-3',$deliveryRow['payload']);$skuDelivery['details']['products'][0]['sku']='SKU-3';
    $db->run('UPDATE delivery_orders SET payload=? WHERE id=?',[$cipher->seal(1,'order:n11:order-3',$skuDelivery),$deliveryRow['id']]);
    $duplicate=$skuOrder;$duplicate['products'][]=$duplicate['products'][0];
    $db->run("UPDATE recent_orders SET payload=? WHERE external_id='order-3'",[json_encode($duplicate)]);
    verify(in_array(3,array_column($service->plan(1)['blocked'],'id'),true),'Tek siparişte aynı ürün kodu tekrarlanırsa tutar tahmin edilmez.');
    $db->run("UPDATE recent_orders SET payload=? WHERE external_id='order-3'",[json_encode($skuOrder)]);
    $plan=$service->plan(1);
    verify(array_column($plan['items'],'id')===[1,2,3],'Yalnız doğrulanmış kayıtlar; idefix, silinmiş, diğer firma ve elle değişiklik dışarıda.');
    verify(array_column($plan['blocked'],'reason','id')===[4=>'manual_invoice',7=>'source_conflict',9=>'protected_invoice'],'Elle düzenleme ve kaynak çelişkisi korunur.');
    $before=$db->all('SELECT * FROM shipment_entries ORDER BY id');
    $db->run('UPDATE shipment_entries SET version=version+1 WHERE id=1');
    try{$service->apply(1,$plan['items'],$folder.'/stale.json');throw new RuntimeException('Eski plan kabul edildi.');}
    catch(RuntimeException $e){verify(str_contains($e->getMessage(),'yeniden doğrulayın'),'Eski sürümde işlem başlamaz.');}
    verify(!file_exists($folder.'/stale.json'),'Eski plan yedek veya değişiklik oluşturmaz.');
    $plan=$service->plan(1);$savedDeliveries=$db->all('SELECT * FROM delivery_orders');$savedOrders=$db->all('SELECT * FROM recent_orders');
    $result=$service->apply(1,$plan['items'],$folder.'/backup.json');
    verify($result['amountsChanged']===2&&$result['invoiceLocksRemoved']===2,'Tutar ve eski kilit sayıları doğru.');
    foreach($db->all('SELECT * FROM shipment_entries ORDER BY id') as $index=>$row){
        $old=$before[$index];$id=(int)$row['id'];$company=(int)$row['company_id'];
        if(!in_array($id,[1,2,3],true)){verify($row===$old,'Kapsam dışı satıra dokunulmaz.');continue;}
        $original=$cipher->open($company,'shipment:'.$id,$old['payload']);$new=$cipher->open($company,'shipment:'.$id,$row['payload']);
        $original['invoiceCents']=[1=>943406,2=>2629002,3=>2299000][$id];$original['source']['locked']=['customerName'];
        verify($new===$original,'Yalnız fatura tutarı ve eski fatura kilidi değişir.');
        foreach($old as $field=>$value)if(!in_array($field,['payload','version','updated'],true))verify($row[$field]===$value,'Durumlar ve diğer sütunlar korunur.');
    }
    verify($service->plan(1)['items']===[],'İkinci taramada fark veya yeniden değişiklik yok.');
    verify($db->all('SELECT * FROM delivery_orders')===$savedDeliveries&&$db->all('SELECT * FROM recent_orders')===$savedOrders,'Sipariş ve teslimat kaynağı değiştirilmez.');
    verify(count(json_decode(file_get_contents($folder.'/backup.json'),true)['rows'])===3,'Şifreli eski satırlar yedeklendi.');
    verify((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='shipment.invoice_reconcile'")['n']===3,'Her değişiklik denetim kaydına işlendi.');
    echo "Fatura eşleme: kaynak/satır/adet, birim-toplam, elle değişiklik, idefix, firma, silinen kayıt, eşzamanlılık, yedek, durum koruma ve tekrar testleri geçti.\n";
}finally{foreach(glob($folder.'/*') as $file)unlink($file);rmdir($folder);}
