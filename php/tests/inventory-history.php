<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventorySettings,InventoryCatalog,InventoryCommands,InventoryHistory};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$db->run("INSERT INTO users VALUES(1,'one@example.test','Birinci Kullanıcı','x','admin',NULL),(2,'two@example.test','İkinci Kullanıcı','x','admin',NULL)");
foreach([1,2] as $company){$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);(new InventorySettings($db))->ensureJob($company);}
$db->run("UPDATE inventory_jobs SET lease_token='test-lease',lease_until=?",[time()+120]);
$catalog=new InventoryCatalog($db);$key=$catalog->ingest(1,'Hepsiburada',['id'=>'1','sku'=>'SKU1','title'=>'Ürün Bir','quantity'=>8,'saleActive'=>true]);
$other=$catalog->ingest(2,'Hepsiburada',['id'=>'2','sku'=>'SKU2','title'=>'Diğer müşteri','quantity'=>9,'saleActive'=>true]);
$commands=new InventoryCommands($db);$history=new InventoryHistory($db);$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn):void{try{$fn();}catch(InvalidArgumentException|DomainException|OutOfBoundsException){check(true,'Reddedildi');return;}throw new RuntimeException('İşlem reddedilmeliydi.');}
$request=['productKey'=>$key,'requestId'=>'history-initialize-0001','mode'=>'initialize','amount'=>12,'version'=>1,'actorId'=>999];
$commands->submit(1,$request,1);$commands->submit(1,$request,1);$commands->process(1,'test-lease');
$initial=$history->listing(1,$key)['items'][0];
check($initial['actor_name']==='Birinci Kullanıcı'&&(int)$initial['actor_id']===1,'Kullanıcı kimliği sunucudan gelir; istek gövdesi değiştiremez.');
check($initial['quantity_before']===null&&$initial['quantity_after']===12&&$initial['note']==='Başlangıç mağaza stoğu','Açıklama olmadan başlangıç kaydı oluşur.');
check(count($history->listing(1,$key)['items'])===1,'Aynı istek iki stok hareketi oluşturmaz.');
$commands->submit(1,[...$request,'requestId'=>'history-store-sale-0002','mode'=>'store_sale','amount'=>3,'version'=>2],2);$commands->process(1,'test-lease');
$commands->submit(1,[...$request,'requestId'=>'history-stock-update-003','mode'=>'set','amount'=>20,'version'=>3],1);$commands->process(1,'test-lease');
$rows=$history->listing(1,$key)['items'];
check($rows[0]['kind']==='set'&&$rows[0]['quantity_before']===9&&$rows[0]['delta']===11&&$rows[0]['quantity_after']===20,'Stok güncellemesi önceki, fark ve son miktarı saklar.');
check($rows[1]['kind']==='store_sale'&&$rows[1]['actor_name']==='İkinci Kullanıcı'&&$rows[1]['delta']===-3&&$rows[1]['quantity_after']===9,'Satılan adet ve kullanıcı ayrı hareket olarak saklanır.');
rejects(fn()=>$commands->submit(1,[...$request,'requestId'=>'history-fraction-00004','amount'=>1.5],1));
rejects(fn()=>$commands->submit(1,[...$request,'requestId'=>'history-stale-00000005'],1));
check(count($history->listing(1,$key)['items'])===3,'Reddedilen işlem sahte hareket oluşturmaz.');
rejects(fn()=>$history->listing(1,$other));
check($history->listing(2)['items']===[],'Başka müşterinin hareketleri görünmez.');
$insert=function(int $company,string $product,int $id,string $created)use($db){$event=hash('sha256','row-'.$id);$db->run('INSERT INTO inventory_ledger(company_id,event_key,product_key,kind,delta,quantity_after,request_hash,actor_id,note,created) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,$event,$product,'set',1,20,$event,1,'Eski kayıt',$created]);return $event;};
foreach(['2026-10-29T20:59:59.000000Z','2026-10-29T21:00:00.000000Z','2026-10-30T20:59:59.000000Z','2026-10-30T21:00:00.000000Z'] as $index=>$date)$insert(1,$key,100+$index,$date);
$day=$history->listing(1,$key,'2026-10-30','2026-10-30')['items'];
$boundary=array_filter($day,fn($row)=>$row['note']==='Eski kayıt');check(count($boundary)===2,'Tarih sınırları Türkiye saatine göre uygulanır.');
rejects(fn()=>$history->listing(1,'','2026-02-30'));rejects(fn()=>$history->listing(1,'','2026-10-31','2026-10-30'));
for($i=0;$i<53;$i++)$insert(1,$key,200+$i,'2026-11-01T10:00:00.000000Z');
$first=$history->listing(1,'','2026-11-01','2026-11-01');check(count($first['items'])===50&&$first['nextCursor']!==null,'Hareketler 50 kayıtla sınırlı sayfalanır.');
$insert(1,$key,999,'2026-11-01T11:00:00.000000Z');
$second=$history->listing(1,'','2026-11-01','2026-11-01',$first['nextCursor']);
$keys=array_merge(array_column($first['items'],'event_key'),array_column($second['items'],'event_key'));
check(count($second['items'])===3&&count(array_unique($keys))===53,'Yeni hareket eklense de sonraki sayfa kayıt atlamaz veya tekrarlamaz.');
$foreign=$insert(2,$other,777,'2026-11-01T10:00:00.000000Z');rejects(fn()=>$history->listing(1,'','','',$foreign));
echo "Stok hareketleri: $checks kayıt, kullanıcı, tarih, tekrar, sayfalama ve müşteri yalıtımı kontrolü geçti.\n";
