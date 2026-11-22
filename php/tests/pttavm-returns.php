<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,RecentOrderSchema,IntegrationVault,DeliveryCipher,DeliveryRepository,PttavmOrderMapper,PttavmOrderStore,ReturnRequestList,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fixture(string $number,string $status):array{
 return ['siparisNo'=>$number,'islemTarihi'=>'2026-06-15T09:00:00','tedarikciFirmaAdi'=>'Test Mağaza','musteriAdi'=>'Örnek','musteriSoyadi'=>'Müşteri','telefonNo'=>'05000000000','siparisAdresi'=>'Örnek teslimat adresi','siparisIli'=>'Ankara','siparisIlce'=>'Çankaya','eposta'=>'test@example.invalid','faturaTip'=>'Bireysel','faturaMusteriAdi'=>'Örnek','faturaMusteriSoyadi'=>'Müşteri','faturaAdresi'=>'Örnek adres',
  'siparisUrunler'=>[['lineItemId'=>100,'urunBarkod'=>'P-1','urun'=>'Test ürün','toplamIslemAdedi'=>2,'kdvDahilToplamTutar'=>200,'indirimToplam'=>20,'siparisDurumu'=>$status]]];
}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Başka firma')");
$key=tempnam(sys_get_temp_dir(),'ptt-returns-');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$config=['revision'=>'v1','enabled'=>true,'connectedAt'=>time(),'restoreShipments'=>true];
 $vault->save(1,$config,'pttavm');$cipher=new DeliveryCipher($key);$delivery=new DeliveryRepository($db,$cipher);$store=new PttavmOrderStore($db,$vault,$delivery);
 $state=['phase'=>'ready','startDate'=>'2026-06-01T00:00:00+03:00','watermark'=>gmdate('c'),'lastSuccessAt'=>gmdate('c'),'refundsVerified'=>false];
 $save=static function(array $orders)use($store,$config,$state):void{$store->commit(1,$config,$store->state(1),$state,PttavmOrderMapper::batch($orders,'Test Mağaza'));};
 $save([fixture('PTT-ONE','iade'),fixture('PTT-TWO','gondericisine_teslim_edildi'),fixture('PTT-THREE','tamamlandi')]);
 $returns=new ReturnRequestList($db);$admin=['role'=>'admin'];$result=$returns->listing($admin,1,['provider'=>'pttavm']);
 check($result['total']===2,'Senkronize edilen iki iade durumu güncel sipariş tablosundan listelenir');
 foreach($result['items'] as $item){
  check($item['group']==='approved'&&$item['quantity']===2&&$item['amountCents']===18000,'İade tutarı, adedi ve onay durumu korunur');
  check($item['createdAt']===null&&$item['reason']===''&&$item['recordType']==='order_line','Talep tarihi, gerekçe ve banka ödemesi uydurulmaz');
 }
 check($result['sources'][0]['state']==='partial'&&$result['sources'][0]['coverage']==='order_status_returns','Ayrı talep servisi doğrulanmadan tam kapsam iddia edilmez');
 check($returns->listing($admin,1,['provider'=>'pttavm','status'=>'refunded'])['total']===0,'İade statüsü banka geri ödemesi yapılmış sayılmaz');
 check($returns->listing($admin,1,['provider'=>'pttavm','status'=>'approved','search'=>'PTT-TWO'])['total']===1,'Sipariş ve durum filtresi');
 check($returns->listing($admin,1,['provider'=>'pttavm','search'=>'Test ürün'])['total']===2,'Ürün araması');
 check($delivery->listing(1,'Ankara','PTT AVM','all',0)['items']===[],'İade satırları teslimat evraklarına girmez');
 $foreign=PttavmOrderMapper::map(fixture('PTT-OTHER','iade'))['order'];
 $db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[2,'pttavm','PTT-OTHER',json_encode($foreign),gmdate('c')]);
 check($returns->listing($admin,1,['provider'=>'pttavm'])['total']===2,'Başka firmanın iadesi görünmez');
 $save([fixture('PTT-ONE','iade'),fixture('PTT-TWO','gondericisine_teslim_edildi')]);
 check($returns->listing($admin,1,['provider'=>'pttavm'])['total']===2,'Tekrar tarama iadeleri çoğaltmaz');
 $save([fixture('PTT-ONE','tamamlandi')]);
 check($returns->listing($admin,1,['provider'=>'pttavm'])['total']===1,'Kaynağın düzelttiği iade durumu listede kalmaz');
 // A stale legacy row must not override the canonical status or duplicate a return.
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_pttavm',json_encode(PttavmOrderMapper::map(fixture('PTT-ONE','iade'))['order']),gmdate('c')]);
 check($returns->listing($admin,1,['provider'=>'pttavm'])['total']===1,'Eski kopya güncel sipariş durumu yerine kullanılmaz');
 $db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_pttavm'",[json_encode([...$state,'phase'=>'error','failure'=>['code'=>429]])]);
 $failed=$returns->listing($admin,1,['provider'=>'pttavm']);check($failed['total']===1&&$failed['sources'][0]['state']==='stale','Kesintide son iade korunur, güncel olduğu iddia edilmez');
 try{$returns->listing(['role'=>'client','company_id'=>1],1,['provider'=>'pttavm']);throw new RuntimeException('Yetki sınırı aşılmış');}catch(HttpError $e){check($e->getCode()===403,'Müşteri yetkisi korunur');}
 echo "PTT AVM iade kaynağı, tutar, adet, kapsam, tekrar, durum düzeltmesi, firma/rol sınırı ve kesinti kontrolleri geçti.\n";
}finally{unlink($key);}
