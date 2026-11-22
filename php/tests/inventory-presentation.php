<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryProgress,InventoryRead,InventorySettings,InventoryInitialImport,InventoryMarketplaceFilter};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$progress=new InventoryProgress($db);$initial=new InventoryInitialImport($db);
foreach([1,2]as$company){
 $db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);
 (new InventorySettings($db))->ensureJob($company);$progress->ensure($company,'hepsiburada');
}
verify(!$initial->status(1)['complete']&&$initial->status(1)['percent']===null,'İlk aktarım başlamadan ilerleme bilinmez.');
$db->run("UPDATE inventory_catalog_progress SET state='running',pages_done=2,total_pages=4,started_at=? WHERE company_id=1",[time()-120]);
verify($initial->status(1)['percent']===50&&$initial->status(1)['estimatedSeconds']>=120,'İlk sayfalar üzerinden tahmin hesaplanır.');
$db->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',[gmdate('c')]);
foreach(['running','retry','incomplete','queued']as$state){
 $db->run('UPDATE inventory_catalog_progress SET state=?,pages_done=0,total_pages=NULL WHERE company_id=1',[$state]);
 $status=$initial->status(1);verify($status['complete']&&$status['percent']===100,'Periyodik tarama ilk tamamlanmayı sıfırlamaz.');
}
verify(!$initial->status(2)['complete'],'Müşteriler arası ilk aktarım durumu karışmaz.');
$progress->ensure(1,'pttavm');
$db->run("UPDATE inventory_catalog_progress SET state='running',pages_done=2,total_pages=4,started_at=? WHERE company_id=1 AND provider='pttavm'",[time()-120]);
$status=$initial->status(1);verify(!$status['complete']&&$status['completedProviders']===1&&$status['percent']===null,'Yeni kaynak beklenir; PTT bölüm toplamı iş toplamı sayılmaz.');
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
verify($initial->status(1)['complete'],'Bütün kaynaklar en az bir kez tamamlanınca ilk aktarım biter.');
$catalog=new InventoryCatalog($db);$read=new InventoryRead($db);
for($i=1;$i<=43;$i++)$catalog->ingest(1,'Hepsiburada',['id'=>'HB'.$i,'sku'=>'HB-SKU'.$i,'title'=>'Ürün '.sprintf('%03d',$i),'quantity'=>5,'saleActive'=>true]);
$catalog->ingest(1,'PTT AVM',['id'=>'PTT1','sku'=>'PTT-SKU1','title'=>'PTT ürün','quantity'=>4,'saleActive'=>true]);
$catalog->ingest(2,'Hepsiburada',['id'=>'HB1','sku'=>'HB-SKU1','title'=>'Diğer firma ürünü','quantity'=>99,'saleActive'=>true]);
$page=$read->listing(1,'',4,'all','hepsiburada');
verify($page['total']===43&&count($page['items'])===3,'Pazaryeri filtresi sayım ve sayfalamadan önce uygulanır.');
verify($read->listing(1,'',0,'all','PTT AVM')['total']===1,'Görünen pazaryeri adı da kabul edilir.');
verify($read->listing(1,'PTT',0,'all','hepsiburada')['total']===0,'Arama ve pazaryeri filtresi birlikte çalışır.');
verify($read->listing(2,'',0,'all','hepsiburada')['total']===1,'Pazaryeri filtresi müşteri sınırını korur.');
$catalog->ingest(1,'PTT AVM',['id'=>'PTT2','sku'=>'HB-SKU1','title'=>'Ürün 001','quantity'=>5,'saleActive'=>true]);
verify($read->listing(1,'',0,'all','pttavm')['total']===2,'Birden fazla kaynakta eşleşen ürün doğru filtrede görünür.');
$db->run("UPDATE inventory_listings SET remote_id='order-sku:PTT1' WHERE company_id=1 AND remote_id='PTT1'");
verify($read->listing(1,'',0,'all','pttavm')['total']===1,'Yalnız sipariş kaynaklı geçici eşleşme katalog ürünü sayılmaz.');
$before=$db->one('SELECT COUNT(*) n FROM inventory_products')['n'];
try{InventoryMarketplaceFilter::where("' OR 1=1");throw new RuntimeException('Geçersiz filtre kabul edildi.');}catch(InvalidArgumentException){$checks++;}
verify($db->one('SELECT COUNT(*) n FROM inventory_products')['n']===$before,'Salt okunur filtre ürünleri değiştirmez.');
echo "$checks ilk aktarım ve pazaryeri filtre kontrolü geçti.\n";
