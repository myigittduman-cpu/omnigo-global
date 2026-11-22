<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,RecentOrderSchema,DeliveryCipher,ShipmentLegacyInvoiceAlignment,ShipmentInvoiceAlignment};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);RecentOrderSchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Diğer')");
$folder=sys_get_temp_dir().'/legacy-invoice-'.bin2hex(random_bytes(8));mkdir($folder,0700);file_put_contents($folder.'/key',random_bytes(32));
$cipher=new DeliveryCipher($folder.'/key');$service=new ShipmentLegacyInvoiceAlignment($db,$cipher);
$add=function(int $id,string $provider='Hepsiburada',int $company=1)use($db,$cipher):void{
    $slug=['Hepsiburada'=>'hepsiburada','idefix'=>'idefix'][$provider];$identity='order-'.$id;$lineId='line-'.$id;
    $saved=['orderedAt'=>'2026-09-20T12:34:00+03:00','customerName'=>'Örnek Alıcı','recipientName'=>'Korunacak alıcı',
        'phone'=>'905321234567','province'=>'Ankara','productName'=>'Deneme ürün modeli MODEL'.$id.'XX','quantity'=>2,'invoiceCents'=>12501,'fulfillment'=>['delivered'=>true]];
    $line=['lineId'=>$lineId,'sku'=>'MODEL'.$id.'XX','title'=>$saved['productName'],'quantity'=>2,'amountCents'=>25002];
    $d=['provider'=>$provider,'createdAt'=>'2026-09-20T09:34:52Z','orderNumber'=>$identity,'buyerName'=>$saved['customerName'],'phone'=>'05321234567','city'=>'Ankara','details'=>['products'=>[$line]]];
    $o=['externalId'=>$identity,'orders'=>1,'products'=>[['lineId'=>$lineId,'quantity'=>2,'grossCents'=>25002]]];
    $db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,$slug,$identity,json_encode($o),gmdate('c')]);
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$provider,$identity,'Ankara',1,$cipher->seal($company,'order:'.$provider.':'.$identity,$d),'hash'.$id,gmdate('c')]);
    $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,row_status,completed,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$company,'Ankara',$provider,'2026-09',$cipher->seal($company,'shipment:'.$id,$saved),'completed',1,gmdate('c'),gmdate('c')]);
};
try{
    foreach(range(1,7) as $id)$add($id);$add(8,'idefix');$add(9,'Hepsiburada',2);$add(10);
    $edit=function($id,$field,$value)use($db,$cipher){$row=$db->one('SELECT * FROM shipment_entries WHERE id=?',[$id]);$p=$cipher->open(1,'shipment:'.$id,$row['payload']);$p[$field]=$value;$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,$p),$id]);};
    $edit(2,'customerName','Farklı Alıcı');$edit(3,'phone','');$edit(4,'orderedAt','2026-09-20T12:35:00+03:00');$edit(5,'quantity',3);
    $db->run("INSERT INTO audit(company_id,action,record_id,after_value,created) VALUES(1,'shipment.edit',6,?,?)",[json_encode(['fields'=>['invoiceCents']]),gmdate('c')]);
    $db->run("UPDATE shipment_entries SET removed_at='2026-10-01' WHERE id=7");
    $db->run('UPDATE shipment_entries SET source_key=? WHERE id=10',[ShipmentInvoiceAlignment::sourceKey('Hepsiburada','order-10','line-10')]);
    $plan=$service->plan(1);check(array_column($plan['items'],'id')===[1],'Yanlış alıcı, telefon, dakika, adet, manuel, silinen, idefix, diğer firma ve bağlı kayıt dışarıda.');
    // Two legacy rows for one source must not both become an invoice correction.
    $copy=$db->one('SELECT * FROM shipment_entries WHERE id=1');$value=$cipher->open(1,'shipment:1',$copy['payload']);
    $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(11,1,?,?,?,?,?,?)',['Ankara','Hepsiburada','2026-09',$cipher->seal(1,'shipment:11',$value),gmdate('c'),gmdate('c')]);
    check($service->plan(1)['items']===[],'Mükerrer eski kayıtta fiyat tahmini yapılmaz.');$db->run('DELETE FROM shipment_entries WHERE id=11');
    $db->run('UPDATE shipment_entries SET version=version+1 WHERE id=1');
    try{$service->apply(1,$plan['items'],$folder.'/stale.json');throw new RuntimeException('Eski plan kabul edildi.');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'yeniden doğrulayın'),'Eşzamanlı değişiklikte işlem durur.');}
    check(!is_file($folder.'/stale.json'),'Geçersiz planda yazma başlamaz.');
    $before=$db->all('SELECT * FROM shipment_entries ORDER BY id');$orders=$db->all('SELECT * FROM recent_orders');$deliveries=$db->all('SELECT * FROM delivery_orders');
    $result=$service->apply(1,$service->plan(1)['items'],$folder.'/backup.json');check($result['amountsChanged']===1,'Tek tutar düzeltildi.');
    foreach($db->all('SELECT * FROM shipment_entries ORDER BY id') as $i=>$row){
        $old=$before[$i];if((int)$row['id']!==1){check($row===$old,'Kapsam dışı satırlar korunur.');continue;}
        $a=$cipher->open(1,'shipment:1',$old['payload']);$b=$cipher->open(1,'shipment:1',$row['payload']);$a['invoiceCents']=25002;
        check($a===$b,'Yalnız tam satır fatura toplamı değişir; diğer alanlar korunur.');
        foreach($old as $key=>$value)if(!in_array($key,['payload','updated','version'],true))check($row[$key]===$value,'Kaynak bağlantısı ve durum değişmez.');
    }
    check($service->plan(1)['items']===[],'Tekrar çalışmada fark yok.');
    check($db->all('SELECT * FROM recent_orders')===$orders&&$db->all('SELECT * FROM delivery_orders')===$deliveries,'Sipariş ve teslimat kaynağı korunur.');
    check(count(json_decode(file_get_contents($folder.'/backup.json'),true)['rows'])===1,'Şifreli yedek saklandı.');
    check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='shipment.legacy_invoice_reconcile'")['n']===1,'Düzeltme kaydı saklandı.');
    $near=$cipher->open(1,'shipment:1',$db->one('SELECT payload FROM shipment_entries WHERE id=1')['payload']);
    $delivery=['provider'=>'n11','createdAt'=>'2026-09-20T12:36:32+03:00','buyerName'=>$near['customerName'],'phone'=>$near['phone'],'city'=>'Ankara'];
    $line=['title'=>$near['productName'],'sku'=>'MODEL1XX','quantity'=>2];
    check(ShipmentLegacyInvoiceAlignment::matches($near,$delivery,$line),'n11 sipariş/paket zamanı kısa farkı kimlik doğrulamasıyla kabul edilir.');
    $delivery['provider']='Hepsiburada';check(!ShipmentLegacyInvoiceAlignment::matches($near,$delivery,$line),'Diğer pazaryerlerine saat toleransı uygulanmaz.');
    $delivery['provider']='n11';$delivery['createdAt']='2026-09-20T12:38:00+03:00';check(!ShipmentLegacyInvoiceAlignment::matches($near,$delivery,$line),'Geniş zaman farkı tahmin edilmez.');
    $delivery['provider']='Pazarama';$delivery['createdAt']='2026-09-20 12:34';check(ShipmentLegacyInvoiceAlignment::matches($near,$delivery,$line),'Saat dilimsiz pazaryeri zamanı Türkiye saati olarak karşılaştırılır.');
    $recipientDelivery=$delivery;$recipientDelivery['buyerName']='Başka hesap sahibi';
    $recipientDelivery['details']['recipientName']=$near['recipientName'];
    check(ShipmentLegacyInvoiceAlignment::matches($near,$recipientDelivery,$line),'Hesap sahibi farklıysa aynı teslim alıcı ve diğer kimlik alanları doğrulanır.');
    $recipientDelivery['phone']='905550000000';
    check(!ShipmentLegacyInvoiceAlignment::matches($near,$recipientDelivery,$line),'Teslim alıcı eşleşmesi telefon kontrolünü atlayamaz.');
    $recipientDelivery['phone']=$near['phone'];$recipientDelivery['details']['recipientName']='Başka alıcı';
    check(!ShipmentLegacyInvoiceAlignment::matches($near,$recipientDelivery,$line),'Alıcı ve teslim alıcı farklıysa eşleştirme yapılmaz.');
    $near['productName']='Tarihi ürün modeli MODEL12XX';$near['invoiceCents']=100;$line=['lineId'=>'history-line','title'=>$near['productName'],'sku'=>'MODEL12XX','quantity'=>2,'amountCents'=>200];
    $db->run('INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated) VALUES(12,1,?,?,?,?,?,?)',['Ankara','n11','2026-09',$cipher->seal(1,'shipment:12',$near),gmdate('c'),gmdate('c')]);
    $delivery=['provider'=>'n11','externalId'=>'history-package','orderNumber'=>'history-order','createdAt'=>'2026-09-20T12:34:30+03:00','buyerName'=>$near['customerName'],'phone'=>$near['phone'],'city'=>'Ankara','details'=>['products'=>[$line]]];
    $history=['externalId'=>'history-order','orders'=>1,'products'=>[['lineId'=>'history-line','quantity'=>2,'grossCents'=>200]]];
    $references=[['provider'=>'n11','payload'=>json_encode($history)]];
    $historyPlan=(new ShipmentLegacyInvoiceAlignment($db,$cipher,$references,[$delivery]))->plan(1);
    check(array_column($historyPlan['items'],'id')===[12]&&$historyPlan['items'][0]['after']===200,'Canlı doğrulanan geçmiş sipariş ve teslimat kanıtı veri tabanına eklenmeden kullanılabilir.');
    $history['products'][0]['grossCents']=201;$references[0]['payload']=json_encode($history);
    check((new ShipmentLegacyInvoiceAlignment($db,$cipher,$references,[$delivery]))->plan(1)['items']===[],'Geçmiş finans ve teslimat kaynağı çelişkisi düzeltmeyi engeller.');
    echo "Eski fatura: kesin kimlik, mükerrerlik, kapsam, manuel tutar, eşzamanlılık, yedek ve yalnız tutar değişikliği testleri geçti.\n";
}finally{foreach(glob($folder.'/*') as $file)unlink($file);rmdir($folder);}
