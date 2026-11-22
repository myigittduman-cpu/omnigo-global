<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryProgress,InventoryRead,InventorySettings};

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
foreach([1,2] as $company){
 $db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);
 (new InventorySettings($db))->ensureJob($company);
 foreach(['hepsiburada','n11'] as $provider)(new InventoryProgress($db))->ensure($company,$provider);
}
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
$catalog=new InventoryCatalog($db);$read=new InventoryRead($db);$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
for($i=1;$i<=51;$i++){
 $code=sprintf('SKU%03d',$i);
 $catalog->ingest(1,$i<=31?'Hepsiburada':'n11',['id'=>'ID'.$i,'sku'=>$code,'title'=>sprintf('Ürün %03d',$i),'quantity'=>2,'saleActive'=>true]);
}
$catalog->ingest(2,'Hepsiburada',['id'=>'ID1','sku'=>'SKU001','title'=>'Başka müşteri ürünü','quantity'=>9,'saleActive'=>true]);
$first=$read->listing(1);
$second=$read->listing(1,'',1);
verify($first['pageSize']===10&&count($first['items'])===10&&$first['total']===51,'İlk sayfa varsayılan 10 ürün ve doğru toplam.');
verify($first['items'][9]['sku']==='SKU010'&&$second['items'][0]['sku']==='SKU011','10/11 sınırında ürün kaybı veya tekrar yok.');
$seen=[];
for($page=0;$page<6;$page++)foreach($read->listing(1,'',$page)['items'] as $item)$seen[]=$item['sku'];
verify(count($seen)===51&&count(array_unique($seen))===51,'Altı sayfa tüm ürünleri bir kez gösterir.');
$fifty=$read->listing(1,'',0,'stocked','',50);
$last=$read->listing(1,'',1,'stocked','',50);
verify($fifty['pageSize']===50&&count($fifty['items'])===50&&$fifty['items'][49]['sku']==='SKU050','50 ürün seçimi.');
verify(count($last['items'])===1&&$last['items'][0]['sku']==='SKU051','51. ürün sonraki sayfada.');
$filtered=$read->listing(1,'',1,'stocked','n11',10);
verify($filtered['total']===20&&count($filtered['items'])===10&&$filtered['items'][0]['sku']==='SKU042','Pazaryeri filtresi sayfalamadan önce uygulanır.');
$searched=$read->listing(1,'SKU051',5,'stocked','n11',50);
verify($searched['total']===1&&$searched['page']===0&&$searched['items'][0]['sku']==='SKU051','Arama sonucunda taşan sayfa ilk sayfaya sınırlandırılır.');
verify(count($read->listing(1,'',0,'stocked','',1000)['items'])===10,'Desteklenmeyen sayfa boyutu sınırlanır.');
verify($read->listing(2)['total']===1&&$read->listing(2)['items'][0]['title']==='Başka müşteri ürünü','Müşteri kayıtları ayrı tutulur.');
verify($read->listing(1,'bulunmayan')['total']===0&&$read->listing(1,'bulunmayan')['page']===0,'Boş sonuç güvenli sayfada.');
echo "inventory pagination: $checks checks passed\n";
