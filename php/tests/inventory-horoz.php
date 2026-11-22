<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventorySettings,InventoryProgress,InventoryRead,InventoryStock,InventoryProjection,InventoryCommands,InventoryManual,InventoryStatistics};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Horoz'),(2,'Diğer')");
foreach([1,2] as $company){$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'horoz']]])]);(new InventoryProgress($db))->ensure($company,'hepsiburada');}
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
$checks=0;function verify(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejects(callable $f,string $label):void{try{$f();}catch(DomainException|InvalidArgumentException|OutOfBoundsException){verify(true,$label);return;}throw new RuntimeException($label);}
$catalog=new InventoryCatalog($db);$read=new InventoryRead($db);$stock=new InventoryStock($db);
$source=['id'=>'H1','sku'=>'TEST-1','title'=>'Test ürün','quantity'=>10,'saleActive'=>true];$key=$catalog->ingest(1,'Hepsiburada',$source);$catalog->ingest(2,'Hepsiburada',$source);
$db->run('UPDATE inventory_products SET quantity=99 WHERE company_id=1');
$item=fn()=>$read->listing(1,'',0,'all')['items'][0];
verify($item()['quantity']===10&&$item()['storeQuantity']===null,'Eski mağaza sayımı Horoz stoğuna eklenmez.');
$before=$item();$sale=$stock->change(1,$key,'horoz-manual-sale-01',1,'store_sale',2,1,'Mağaza satışı',null,$before['sourceVersion']);
verify($sale['quantity']===8&&$item()['quantity']===8,'Mağaza satışı tek Horoz havuzunu azaltır.');
verify($stock->change(1,$key,'horoz-manual-sale-01',1,'store_sale',2,1,'Mağaza satışı',null,$before['sourceVersion'])['replayed'],'Tekrarlanan satış ikinci kez düşmez.');
$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>7]);verify($item()['quantity']===5,'Sonraki canlı miktarda manuel satış korunur.');
$projection=new InventoryProjection($db);$line=['provider'=>'Hepsiburada','lineKey'=>hash('sha256','order'),'productKey'=>$key,'orderNumber'=>'O1','placedAt'=>gmdate('c'),'ordered'=>3,'cancelled'=>0,'returned'=>0,'invoiceCents'=>10000];
$projection->apply(1,$line,1);verify($item()['quantity']===5,'Online sipariş projeksiyonu canlı stoktan ikinci kez düşmez.');
$projection->apply(1,[...$line,'cancelled'=>1],2);$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>8]);verify($item()['quantity']===6,'İptal canlı miktarı artırırken manuel satış korunur.');
rejects(fn()=>$stock->change(1,$key,'horoz-stale-source-01',2,'set',100,1,'Eski stok',null,$before['sourceVersion']),'Eski canlı miktar üzerinden mutlak kayıt engellenir.');
$current=$item();$stock->change(1,$key,'horoz-count-update-01',$current['version'],'set',4,1,'Sayım',null,$current['sourceVersion']);
verify($item()['quantity']===4,'Manuel sayım ortak havuzun miktarını ayarlar.');
$current=$item();rejects(fn()=>$stock->change(1,$key,'horoz-excess-return-01',$current['version'],'store_return',3,1,'İade',null,$current['sourceVersion']),'İade kayıtlı mağaza satışını aşamaz.');
$stock->change(1,$key,'horoz-store-return-01',$current['version'],'store_return',1,1,'İade',null,$current['sourceVersion']);
verify($item()['quantity']===5&&(new InventoryStatistics($db))->storeSalesForKeys(1,[$key])[$key]===1,'Mağaza iadesi stoğu artırıp net satışı azaltır.');
$current=$item();$stock->change(1,$key,'horoz-sell-to-zero-01',$current['version'],'store_sale',5,1,'Satış',null,$current['sourceVersion']);
verify($read->listing(1)['total']===0&&$read->listing(1,'',0,'depleted')['total']===1,'Manuel satış sıfırda ürünü stoğu bitenlere taşır.');
$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>10]);verify($read->listing(1)['total']===1&&$read->listing(1,'',0,'depleted')['total']===0,'Canlı stok artınca ürün otomatik aktif listeye döner.');
verify($read->listing(2)['items'][0]['quantity']===10,'Diğer müşterinin stoğu etkilenmez.');
verify((int)$db->one('SELECT quantity FROM inventory_products WHERE company_id=1')['quantity']===99,'Geri dönüş için eski mağaza havuzu korunur.');
$current=$item();(new InventorySettings($db))->ensureJob(1);$db->run('UPDATE inventory_jobs SET lease_token=?,lease_until=? WHERE company_id=1',['lease',time()+100]);
$commands=new InventoryCommands($db);$request=['productKey'=>$key,'requestId'=>'horoz-queued-sale-01','version'=>$current['version'],'sourceVersion'=>$current['sourceVersion'],'mode'=>'store_sale','amount'=>1];
verify($commands->submit(1,$request,1)['state']==='pending','Horoz işlemi kalıcı kuyruğa alınır.');$commands->process(1,'lease');
verify($db->one('SELECT state FROM inventory_commands WHERE company_id=1')['state']==='done'&&$item()['quantity']===1,'Kuyruk aynı kaynak sürümüyle bir kez uygular.');
$current=$item();$commands->submit(1,[...$request,'requestId'=>'horoz-queued-stale-01','version'=>$current['version'],'sourceVersion'=>$current['sourceVersion']],1);$catalog->ingest(1,'Hepsiburada',[...$source,'quantity'=>12]);$commands->process(1,'lease');
verify($db->one('SELECT state FROM inventory_commands WHERE request_id=?',['horoz-queued-stale-01'])['state']==='failed'&&$item()['quantity']===3,'Kuyruk beklerken kaynak değişirse stok yanlış uygulanmaz.');
(new InventorySettings($db))->ensureJob(2);
$manual=(new InventoryManual($db))->create(2,['requestId'=>'horoz-new-manual-01','title'=>'Manuel ürün','sku'=>'MANUAL','quantity'=>0,'connect'=>false,'providers'=>[]],1);
verify($read->listing(2,'MANUAL',0,'depleted')['total']===1,'Kaynağı olmayan manuel sıfır stok da listelenir.');
$db->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',['2000-01-01T00:00:00Z']);verify($item()['quantity']===null&&$read->listing(1,'',0,'depleted')['total']===0,'Eski kaynak yanlış sıfır stok olarak gösterilmez.');
echo "Horoz stock: $checks checks passed\n";
