<?php
declare(strict_types=1);
require __DIR__.'/inventory-pagination.php';
$original=array_column($read->listing(1,'',0,'stocked','',50)['items'],'key');
foreach($db->all('SELECT product_key,sku FROM inventory_products WHERE company_id=1') as $row)$db->run('UPDATE inventory_products SET title=? WHERE company_id=1 AND product_key=?',[sprintf('Ürün %03d',100-(int)substr($row['sku'],3)),$row['product_key']]);
verify(array_column($read->listing(1,'',0,'stocked','',50)['items'],'key')===$original,'Canlı aktarımla ürün başlığı değişse de stok koduna göre sıra sabittir.');
$asc=$read->listing(1,'',0,'stocked','',10,'','name_asc');$desc=$read->listing(1,'',0,'stocked','',10,'','name_desc');
verify($asc['items'][0]['sku']==='SKU051'&&$desc['items'][0]['sku']==='SKU001','Ad A-Z ve Z-A sıralaması sayfalama öncesinde uygulanır.');
$db->run("UPDATE inventory_products SET title='Çamaşır Makinesi' WHERE company_id=1 AND sku IN ('SKU001','SKU003')");
$db->run("UPDATE inventory_products SET title='Bulaşık Makinesi' WHERE company_id=1 AND sku IN ('SKU002','SKU004')");
$group=$read->listing(1,'',0,'stocked','',50,'','category');$next=$read->listing(1,'',1,'stocked','',50,'','category');$names=array_column([...$group['items'],...$next['items']],'title');
foreach(['Çamaşır Makinesi','Bulaşık Makinesi'] as $name){$positions=array_keys($names,$name,true);verify(count($positions)===2&&$positions[1]===$positions[0]+1,'Aynı ürün grubu yan yana: '.$name);}
try{$read->listing(1,'',0,'stocked','',10,'','title; DROP TABLE users');throw new RuntimeException('Geçersiz sıralama kabul edildi.');}catch(InvalidArgumentException){}
verify($read->listing(2)['total']===1,'Sıralama başka müşterinin verisini değiştirmez.');
$target=$db->one("SELECT product_key FROM inventory_products WHERE company_id=1 AND sku='SKU003'")['product_key'];
$settings=json_decode($db->one('SELECT payload FROM company_profiles WHERE company_id=1')['payload'],true);$settings['workspacePolicy']['inventory']['mode']='horoz';$db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($settings)]);
$db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,version,actor_id,updated) VALUES(?,?,?,?,?,?)',[1,$target,3061122,1,1,gmdate('c')]);
$catalog->ingest(1,'Hepsiburada',['id'=>'ID3','sku'=>'SKU003','title'=>'Çamaşır Makinesi','quantity'=>0,'saleActive'=>true]);
verify(!array_filter($read->listing(1,'SKU003')['items'],fn($item)=>$item['key']===$target),'Sıfır stok aktif listeden çıkar.');
$depleted=$read->listing(1,'SKU003',0,'depleted')['items'];
verify(count($depleted)===1&&$depleted[0]['key']===$target&&$depleted[0]['purchasePriceCents']===3061122,'Stoğu biten ürünün fiyatı kendi kimliğiyle korunur.');
$catalog->ingest(1,'Hepsiburada',['id'=>'ID3','sku'=>'SKU003','title'=>'Çamaşır Makinesi','quantity'=>7,'saleActive'=>true]);
foreach(['sku','name_asc','name_desc','category'] as $sort){
 $rows=[...$read->listing(1,'',0,'stocked','',50,'',$sort)['items'],...$read->listing(1,'',1,'stocked','',50,'',$sort)['items']];$returned=array_values(array_filter($rows,fn($item)=>$item['key']===$target));
 verify(count($returned)===1&&$returned[0]['sku']==='SKU003'&&$returned[0]['purchasePriceCents']===3061122,'Geri gelen ürün seçili sırada tek satır ve kendi fiyatıyla görünür: '.$sort);
}
echo "Stok sıralaması: güncellenen başlık, tam sonuçta A-Z/Z-A, grup ve güvenli izin listesi geçti.\n";
