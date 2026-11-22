<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryPurchasePrices,InventoryRead,InventoryProgress,NotificationSchema,SupportSchema,DeliveryCipher,InventoryChangeEvents,OperationalNotifications,ActivityNotification,NotificationFeed,MobilePushMessage};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);NotificationSchema::migrate($db);SupportSchema::migrate($db);\Omnigo\ShipmentSchema::install($db);\Omnigo\DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL),(2,'client@example.test','Ayşe','hash','client',1),(3,'other@example.test','Diğer','hash','client',2)");
foreach([1,2] as $company){$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>['pages'=>['inventory'=>'shown'],'inventory'=>['mode'=>'horoz']]])]);(new InventoryProgress($db))->ensure($company,'hepsiburada');}$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
$checks=0;function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejects(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException|OutOfBoundsException){verify(true,$label);return;}throw new RuntimeException($label);}
$catalog=new InventoryCatalog($db);$source=['id'=>'P1','sku'=>'PRICE-1','title'=>'Z ürün','quantity'=>1,'saleActive'=>true];$key=$catalog->ingest(1,'Hepsiburada',$source);$catalog->ingest(2,'Hepsiburada',$source);
$service=new InventoryPurchasePrices($db);$read=new InventoryRead($db);$actor=$db->one('SELECT * FROM users WHERE id=2');$admin=$db->one('SELECT * FROM users WHERE id=1');
$request=['productKey'=>$key,'requestId'=>'purchase-price-test-01','version'=>0,'amountCents'=>3061122];$saved=$service->save(1,$request,$actor);
verify($saved['purchasePriceCents']===3061122&&$saved['purchasePriceVersion']===1,'Küsürat kuruş olarak kayıpsız kaydedilir.');
verify($service->save(1,$request,$actor)['replayed'],'Aynı istek ikinci kayıt veya bildirim oluşturmaz.');
verify(count($db->all('SELECT * FROM inventory_change_events'))===1,'Müşteri fiyat değişikliğinde bir bildirim kuyruğa alınır.');
rejects(fn()=>$service->save(1,[...$request,'requestId'=>'purchase-price-stale-01','amountCents'=>100],$admin),'Eski fiyat sürümü eşzamanlı değişikliği ezmez.');
rejects(fn()=>$service->save(2,[...$request,'requestId'=>'purchase-price-tenant-01'],$actor),'Müşteri başka mağazanın fiyatını değiştiremez.');
$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>0]);$zero=$read->listing(1,'',0,'depleted')['items'][0];
verify($read->listing(1)['total']===0&&$zero['purchasePriceCents']===3061122,'Stoğu biten ürün fiyatını yanında taşır.');
$service->save(1,[...$request,'requestId'=>'purchase-price-zero-01','version'=>1,'amountCents'=>456789],$actor);
verify($read->listing(1,'',0,'depleted')['items'][0]['purchasePriceCents']===456789,'Sıfır stokta alış fiyatı düzenlenebilir.');
$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>3]);$other=$catalog->ingest(1,'Hepsiburada',[...$source,'id'=>'P2','sku'=>'PRICE-2','title'=>'A ürün','quantity'=>2]);
$items=$read->listing(1,'',0,'stocked','',10,'','name_asc')['items'];verify($items[0]['key']===$other&&$items[0]['purchasePriceCents']===null&&$items[1]['key']===$key&&$items[1]['purchasePriceCents']===456789,'Yeni sıralamada fiyat aynı üründe kalır; yeni ürüne sızmaz.');
verify($read->listing(2)['items'][0]['purchasePriceCents']===null,'Aynı ürün anahtarı diğer müşteriye fiyat taşımaz.');
$service->save(1,[...$request,'requestId'=>'purchase-price-admin-01','version'=>2,'amountCents'=>0],$admin);
verify($read->listing(1,'PRICE-1')['items'][0]['purchasePriceCents']===0&&count($db->all('SELECT * FROM inventory_change_events'))===3,'Yöneticinin sıfır maliyet kaydı da tek bildirim oluşturur.');
verify((int)$db->one('SELECT version FROM inventory_products WHERE company_id=1 AND product_key=?',[$key])['version']===1,'Fiyat değişikliği stok sürümünü ve miktarını değiştirmez.');
$cipher=DeliveryCipher::withKey(str_repeat('p',32));$events=new InventoryChangeEvents($db);verify($events->publish(1,$cipher)===3&&$events->publish(1,$cipher)===0,'Bildirim kuyruğu tek sefer gönderilir.');
$row=$db->one("SELECT * FROM order_notifications WHERE json_extract(payload,'$.purchasePriceCents')=3061122");$notice=new OperationalNotifications($db,$cipher);$detail=$notice->detail(1,(int)$row['id']);
verify($detail['actorName']==='Ayşe'&&$detail['purchasePriceCents']===3061122&&str_contains(ActivityNotification::message($detail),'alış fiyatı güncellendi'),'Bildirim doğru ürün, kullanıcı ve fiyatı açar.');
$feed=new NotificationFeed($db,$cipher);verify($feed->listing(1,1,0)['counts']['all']===3&&$feed->listing(2,1,0)['counts']['all']===0,'Alış fiyatı bildirimlerini yalnız yöneticiler görür.');
$message=MobilePushMessage::create([], $row,'price');verify(str_contains($message['url'],'view=inventory')&&str_contains($message['url'],'noticeType=operation'),'Bildirim doğru stok detayına yönlenir.');
$devices=new \Omnigo\PushDevices($db,$cipher);$adminDevice=$devices->save([...$admin,'token'=>str_repeat('a',64)],'desktop',['deviceId'=>str_repeat('a',64)]);
$db->run('INSERT INTO sessions VALUES(?,?,?,?)',[str_repeat('c',64),2,'csrf',time()+3600]);$clientDevice=$devices->save([...$actor,'token'=>str_repeat('c',64)],'desktop',['deviceId'=>str_repeat('c',64)]);
verify(count($devices->poll($adminDevice['token'],0,true,true,true)['items'])===3&&$devices->poll($clientDevice['token'],0,true,true,true)['items']===[],'Masaüstü bildirim aktarımı fiyat değişikliğini yalnız yönetici cihazına verir.');
foreach(['store','both','marketplace'] as $mode){$db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode(['workspacePolicy'=>['inventory'=>['mode'=>$mode]]])]);verify($read->listing(1,'PRICE-1',0,'all')['items'][0]['purchasePriceCents']===0,'Fiyat stok modeli değişse de üründe korunur: '.$mode);}
rejects(fn()=>$service->save(1,[...$request,'requestId'=>'purchase-price-float-01','amountCents'=>30611.22],$admin),'Ondalıklı kuruş veya kayan noktalı tutar API tarafından reddedilir.');
rejects(fn()=>$service->save(1,[...$request,'amountCents'=>1],$actor),'Aynı işlem anahtarının farklı tutarla kullanılması reddedilir.');
echo "Purchase prices: $checks checks passed\n";
