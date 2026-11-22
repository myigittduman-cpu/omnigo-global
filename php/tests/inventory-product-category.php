<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventorySettings,InventoryProgress,InventoryCatalog,InventoryRead,InventoryProductCategory};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
foreach([1,2] as $company){(new InventorySettings($db))->ensureJob($company);foreach(['hepsiburada','n11'] as $provider)(new InventoryProgress($db))->ensure($company,$provider);}
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);
$catalog=new InventoryCatalog($db);$read=new InventoryRead($db);$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
for($i=1;$i<=26;$i++)$catalog->ingest(1,$i<15?'Hepsiburada':'n11',['id'=>'W'.$i,'sku'=>'W'.$i,'title'=>sprintf('Profilo %02d ÇAMAŞIR MAKİNESİ',$i),'quantity'=>3,'saleActive'=>true]);
foreach([
 ['dishwashers','Profilo BULAŞIK MAKİNASI'],['refrigerators','Alttan Donduruculu Buzdolabı'],['freezers','Derin Dondurucu'],
 ['washer_dryer','Kurutmalı Çamaşır Makinesi'],['dryers','Çamaşır Kurutma Makinesi'],['built_in_sets','Ankastre Set Fırın Ocak Davlumbaz'],['televisions','43 inç Smart QLED TV'],['water_purifiers','Profilo SU01A2VW0N Su Arıtıcı Paslanmaz Çelik'],['water_dispensers','Profilo SBC02AB0N Gizli Damacanalı Su Sebili'],['other','Yeni tür ürün']
] as [$type,$title]){$catalog->ingest(1,'n11',['id'=>$type,'sku'=>$type,'title'=>$title,'quantity'=>4,'saleActive'=>true]);$result=$read->listing(1,'',0,'stocked','',10,$type);verify($result['total']===1&&$result['items'][0]['sku']===$type,'Tür ve Türkçe harf doğru eşleşir: '.$type);}
$catalog->ingest(1,'n11',['id'=>'CLOSED','sku'=>'CLOSED','title'=>'Klima','quantity'=>4,'saleActive'=>false]);
$catalog->ingest(1,'n11',['id'=>'ZERO','sku'=>'ZERO','title'=>'Klima','quantity'=>0,'saleActive'=>true]);
$catalog->ingest(2,'n11',['id'=>'OTHER','sku'=>'OTHER','title'=>'Çamaşır Makinesi','quantity'=>4,'saleActive'=>true]);
$before=$db->all('SELECT * FROM inventory_products ORDER BY company_id,product_key');
$first=$read->listing(1,'',0,'stocked','',10,'washing_machines');$last=$read->listing(1,'',2,'stocked','',10,'washing_machines');
verify($first['total']===26&&count($first['items'])===10&&count($last['items'])===6,'Tür filtresi toplam ve sayfalama öncesinde uygulanır');
verify(count($read->listing(1,'',0,'stocked','',50,'washing_machines')['items'])===26,'50 ürün seçimi tür filtresini korur');
verify($read->listing(1,'W26',0,'stocked','n11',10,'washing_machines')['total']===1,'Arama, pazaryeri ve ürün türü birlikte çalışır');
verify($read->listing(1,'',0,'stocked','Hepsiburada',10,'dishwashers')['total']===0,'Pazaryeri filtresi aşılmaz');
verify(!in_array('air_conditioners',array_column($first['categories'],'value'),true),'Satışa kapalı veya sıfır stoklu ürünün türü aktif listede çıkmaz');
verify(array_sum(array_column($first['categories'],'count'))===36,'Seçenekler ilk 10 satıra sıkışmaz ve diğer firmanın ürününü saymaz');
verify($read->listing(2,'',0,'stocked','',10,'washing_machines')['total']===1,'Müşteriler birbirinden ayrılır');
verify($before===$db->all('SELECT * FROM inventory_products ORDER BY company_id,product_key'),'Filtreleme kayıt veya stok değiştirmez');
foreach(['Profilo 55PA545EQG 55" 140 Ekran 4K Ultra HD Hdr10 Siyah Qled Google Tv 55"','Profilo 65PA535EQG Android TV 65"','Profilo 43 inç LED TV'] as $index=>$title){
 $sku='TV-REGRESSION-'.$index;$catalog->ingest(1,'n11',['id'=>$sku,'sku'=>$sku,'title'=>$title,'quantity'=>1,'saleActive'=>true]);
 verify($read->listing(1,$sku,0,'stocked','',10,'televisions')['total']===1,'Televizyon başlığı Diğer Ürünlere düşmez: '.$title);
}
try{InventoryProductCategory::name("other' OR 1=1");throw new RuntimeException('Geçersiz tür kabul edildi');}catch(InvalidArgumentException){$checks++;}
echo "Ürün türü filtresi: $checks kontrol geçti.\n";
