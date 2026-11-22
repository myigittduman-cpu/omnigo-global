<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,KoctasOrderMapper,KoctasAdapter,KoctasHttpClient,SyncFailure};
function ensure(bool $value,string $label): void { if (!$value) throw new RuntimeException($label); }
function mustFail(callable $action,int $status): void { try { $action(); } catch (SyncFailure $error) { ensure($error->httpStatus===$status,'Hata kodu'); return; } throw new RuntimeException('Hata bekleniyordu.'); }
$db=new Database(['dsn'=>'sqlite::memory:']); $db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
\Omnigo\DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);
$company=(new Repository($db))->createCompany('Vizyona'); $other=(new Repository($db))->createCompany('Diğer firma');
$keyPath=tempnam(sys_get_temp_dir(),'omnigo-test-'); file_put_contents($keyPath,random_bytes(32));
try {
 $vault=new IntegrationVault($db,$keyPath); $fakeKey='00000000-0000-0000-0000-000000000001';
 $start=(new DateTimeImmutable('first day of this month 00:00:00',new DateTimeZone('Europe/Istanbul')))->setTimezone(new DateTimeZone('UTC'))->format('c');
 $vault->save($company,['apiKey'=>$fakeKey,'shopId'=>10673,'enabled'=>true,'startDate'=>$start]);
 ensure($vault->get($company)['apiKey']===$fakeKey,'Şifreli anahtar açılmalı'); ensure($vault->get($other)===null,'Firma yalıtımı');
 ensure(!str_contains($db->one("SELECT payload FROM records WHERE kind='integration_koctas'")['payload'],$fakeKey),'Düz metin anahtar yasak');
 $make=static function(string $id,float $price,array $changes=[]) use($start): array { return array_replace_recursive(['order_id'=>$id,'currency_iso_code'=>'TRY','created_date'=>$start,'order_state'=>'SHIPPED','customer'=>['name'=>'Kaydedilmemeli'],'order_lines'=>[['order_line_id'=>$id.'-1','order_line_state'=>'SHIPPED','price'=>$price,'quantity'=>2,'cancelations'=>[],'refunds'=>[]]]],$changes); };
 $order=$make('order-1',100.25,['order_lines'=>[['cancelations'=>[['amount'=>10.10]],'refunds'=>[['amount'=>5.05,'refund_state'=>'REFUNDED'],['amount'=>4.00,'refund_state'=>'WAITING_REFUND']]]]]);
 $mapped=KoctasOrderMapper::map($order);
 ensure($mapped['grossCents']===10025 && $mapped['cancelCents']===1010 && $mapped['returnCents']===505,'Ondalık, adet, iptal ve iade');
 ensure(!str_contains(json_encode($mapped),'Kaydedilmemeli'),'Kişisel bilgi atılmalı');
 ensure(KoctasOrderMapper::map($make('cancel',12.00,['order_lines'=>[['order_line_state'=>'CANCELED']]]))['cancelCents']===1200,'İptal tam düşmeli');
 $zeroCancelled=$make('zero-cancel',0.00,['order_state'=>'CANCELED','order_lines'=>[['order_line_state'=>'CANCELED','quantity'=>0,'cancelations'=>[['quantity'=>1,'amount'=>20671.14]]]]]);
 $zeroMapped=KoctasOrderMapper::map($zeroCancelled);
 ensure($zeroMapped['grossCents']===2067114&&$zeroMapped['cancelCents']===2067114&&$zeroMapped['products'][0]['quantity']===1&&$zeroMapped['products'][0]['netQuantity']===0,'Sıfırlanmış iptal satırı özgün adet ve tutarı iptal geçmişinden alır');
 $zeroDelivery=\Omnigo\DeliveryMapper::koctas($zeroCancelled);
 ensure(!$zeroDelivery['active']&&$zeroDelivery['status']==='İptal edildi'&&$zeroDelivery['cancellation']['cancelledQuantity']===1,'Sıfır adetli iptal teslimata geri girmez');
 $zeroDetails=\Omnigo\OrderDetailMapper::koctas($zeroCancelled,$zeroDelivery);
 $zeroShipment=\Omnigo\ShipmentOrderMapper::koctas($zeroCancelled,$zeroDelivery)[0]['payload'];
 ensure($zeroDetails['products'][0]['quantity']===1&&$zeroDetails['products'][0]['amountCents']===2067114&&$zeroShipment['quantity']===1&&$zeroShipment['invoiceCents']===2067114&&$zeroShipment['marketplaceCancellation']['cancelledQuantity']===1,'Üç bölümde aynı iptal adedi ve tutarı');
 $missingHistory=$zeroCancelled;$missingHistory['order_lines'][0]['cancelations']=[];
 mustFail(fn()=>\Omnigo\ShipmentOrderMapper::koctas($missingHistory,\Omnigo\DeliveryMapper::koctas($missingHistory)),422);
 $zeroActive=$zeroCancelled;$zeroActive['order_state']='SHIPPING';$zeroActive['order_lines'][0]['order_line_state']='SHIPPING';
 mustFail(fn()=>\Omnigo\ShipmentOrderMapper::koctas($zeroActive,\Omnigo\DeliveryMapper::koctas($zeroActive)),422);
 mustFail(fn()=>KoctasOrderMapper::map($make('currency',1.00,['currency_iso_code'=>'USD'])),422);
 mustFail(fn()=>KoctasOrderMapper::map($make('unknown',1.00,['order_state'=>'UNKNOWN'])),422);
 $returned=$make('returned',22.50,['order_state'=>'RECEIVED','order_lines'=>[['order_line_state'=>'REFUNDED','refunds'=>[['amount'=>22.50,'quantity'=>2,'refund_state'=>'REFUNDED']]]]]);
 $returnedValue=KoctasOrderMapper::map($returned);
 ensure($returnedValue['grossCents']===2250&&$returnedValue['returnCents']===2250&&$returnedValue['cancelCents']===0,'İade satırı iptal sayılmadan finans kaydına alınır');
 ensure($returnedValue['products'][0]['netCents']===0&&$returnedValue['products'][0]['netQuantity']===0,'İade edilen ürünün net tutarı ve adedi doğru');
 $returnedDelivery=\Omnigo\DeliveryMapper::koctas($returned);
 ensure(!$returnedDelivery['active']&&!$returnedDelivery['products']&&$returnedDelivery['cancellation']['cancelledQuantity']===0,'İade yeniden sevkiyata veya iptal bildirimine dönüşmez');
 ensure(\Omnigo\ShipmentOrderMapper::koctas($returned,$returnedDelivery)===[],'İade satırı için yeni sevkiyat satırı açılmaz');
 mustFail(fn()=>KoctasOrderMapper::map($make('unknown-line',1.00,['order_lines'=>[['order_line_state'=>'UNKNOWN']]])),422);
 mustFail(fn()=>KoctasOrderMapper::cents(-1),422);
 try { (new KoctasHttpClient($fakeKey))->get('/api/offers',[],time()+20); throw new RuntimeException('İzin verildi'); } catch (LogicException) {}
 $calls=[]; $page=0;
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_koctas',json_encode(['watermark'=>$start,'offset'=>42,'scanEnd'=>$start,'nextRequestAt'=>0]),gmdate('c')]);
 $request=function(string $path,array $query,int $deadline) use(&$calls,&$page,$order,$make): array {
  $calls[]=$query; $page++;
  return ['orders'=>$page===1?[$order]:[$make('order-2',20.00)],'total_count'=>2];
 };
 $delivery=new \Omnigo\DeliveryRepository($db,new \Omnigo\DeliveryCipher($keyPath));
 $adapter=new KoctasAdapter($db,$vault,$request,$delivery); $adapter->syncReadOnly($company,time()+30);
 ensure($db->one("SELECT id FROM records WHERE kind='sales'")===null,'Eksik sayfa satış olarak yayımlanmamalı');
 mustFail(fn()=>$adapter->syncReadOnly($company,time()+30),429);
 // Advance the simulated retry clock without waiting for the production interval.
 $row=$db->one("SELECT id,payload FROM records WHERE kind='sync_koctas'"); $state=json_decode($row['payload'],true); $state['nextRequestAt']=0;
 $db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($state),$row['id']]);
 $adapter->syncReadOnly($company,time()+30);
 $sale=json_decode($db->one("SELECT payload FROM records WHERE kind='sales'")['payload'],true);
 ensure($sale['grossCents']===12025 && $sale['orders']===2,'Tam sayfa toplamı'); ensure($calls[1]['offset']===1 && $calls[1]['shop_id']===10673,'Sayfalama ve mağaza');
 ensure($calls[0]['offset']===0&&!isset($calls[0]['start_update_date'])&&!isset($calls[1]['start_update_date']),'Eski artımlı aktarım yerine ürün geçmişi sayfalı tam okumayla alınır');
 ensure((int)$db->one('SELECT COUNT(*) AS n FROM delivery_orders')['n']===2,'Sayfalı teslimat aktarımı');
 ensure(count($sale['dailySales']['days'])===1,'Günlük toplamlar tarih bazında birleştirilir');
 $reference=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company])['payload'],true)['reconciliationReference'];
 ensure(count($reference['orders'])===2&&(new \Omnigo\ReconciliationCompare($db,new \Omnigo\DeliveryCipher($keyPath)))->inspect($company,'koctas',$reference)['total']===0,'Koçtaş sayfalı tam kaynak mutabakatı');
 $state=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_koctas'")['payload'],true);$state['nextRequestAt']=0;
 ensure($state['detailsVersion']===1&&!$state['detailsBackfill'],'Tamamlanan ürün aktarımı sürümlenir');
 $db->run("UPDATE records SET payload=? WHERE kind='sync_koctas'",[json_encode($state)]);
 (new KoctasAdapter($db,$vault,function($path,$query)use($make){ensure(isset($query['start_update_date']),'Sonraki okuma artımlıdır');return ['orders'=>[$make('order-2',21.00)],'total_count'=>1];}))->syncReadOnly($company,time()+30);
 ensure((int)$db->one("SELECT COUNT(*) AS n FROM records WHERE kind='order_koctas'")['n']===2,'Tekrarlı sipariş çoğalmamalı');
 $sale=json_decode($db->one("SELECT payload FROM records WHERE kind='sales'")['payload'],true);ensure($sale['grossCents']===12125,'Değişiklik özete yansımalı');
 $current=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_koctas'")['payload'],true);$current['nextRequestAt']=0;$current['detailsVersion']=1;unset($current['deliveryVersion']);
 $db->run("UPDATE records SET payload=? WHERE kind='sync_koctas'",[json_encode($current)]);
 (new KoctasAdapter($db,$vault,function($path,$query)use($make){ensure(!isset($query['start_update_date'])&&$query['offset']===0,'Teslimat için ilk okuma geçmiş kayıtları da almalı');return ['orders'=>[$make('order-2',21.00)],'total_count'=>1];},$delivery))->syncReadOnly($company,time()+30);
 $current=json_decode($db->one("SELECT payload FROM records WHERE kind='sync_koctas'")['payload'],true);ensure($current['deliveryVersion']===1&&!empty($current['deliveryLastPageAt']),'Teslimat sürümü ve zamanı kaydedilmeli');
 mustFail(fn()=>(new KoctasAdapter($db,$vault,$request))->syncReadOnly($other,time()+20),403);
 echo "Koçtaş: şifreleme, firma yalıtımı, yalnızca okuma, tutarlar, iade, sayfalama, hız sınırı ve tekrarsız güncelleme geçti.\n";
} finally { unlink($keyPath); }
