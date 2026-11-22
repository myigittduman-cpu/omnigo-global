<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,SalesProductImages};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);InventorySchema::install($db);$service=new SalesProductImages($db);
$one=str_repeat('a',64);$two=str_repeat('b',64);$image='inventory-image:'.str_repeat('c',48);
foreach([[1,$one,'MODEL-1'],[2,$two,'MODEL-1']] as [$company,$key,$sku])$db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$key,'Tam ürün',$sku,'',$image,gmdate('c')]);
$products=[['sku'=>' model-1 ','title'=>'Tam ürün','netQuantity'=>3,'netCents'=>120000,'imageUrl'=>null],['sku'=>'MODEL-2','title'=>'Başka ürün','imageUrl'=>'https://example.test/existing.png']];
$result=$service->attach(1,'hepsiburada',$products);check(str_contains($result[0]['imageUrl'],'productKey='.$one)&&str_contains($result[0]['imageUrl'],'company=1'),'Yalnız aynı mağazanın doğru ürünü eşleşmeli');check($result[0]['netQuantity']===3&&$result[0]['netCents']===120000,'Görsel satış hesabını değiştirmemeli');check($result[1]['imageUrl']===$products[1]['imageUrl'],'Eşleşmeyen ürünün mevcut görseli korunmalı');
check($service->attach(3,'hepsiburada',$products)===$products,'Başka mağazadan fotoğraf alınmamalı');
$db->run('UPDATE inventory_products SET quantity=0 WHERE company_id=1');check($service->attach(1,'hepsiburada',$products)[0]['imageUrl']===$result[0]['imageUrl'],'Stoğu biten ürün fotoğrafını kaybetmemeli');
$db->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,observed_at) VALUES(?,?,?,?,?,?,?,?)',[1,'n11',str_repeat('d',64),'remote',$one,'SELLER-CODE','{}',gmdate('c')]);
check(str_contains($service->attach(1,'n11',[['sku'=>'SELLER-CODE']])[0]['imageUrl'],$one),'Pazaryerinin birebir kodu eşleşebilmeli');check(!isset($service->attach(1,'hepsiburada',[['sku'=>'SELLER-CODE']])[0]['imageUrl']),'Başka pazaryeri kodu kullanılmamalı');
$db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[1,$two,'Farklı ürün','MODEL-1','',$image,gmdate('c')]);check($service->attach(1,'hepsiburada',$products)[0]['imageUrl']===null,'Birden fazla ürün eşleşmesinde rastgele görsel seçilmemeli');
echo "Satış görselleri: birebir ürün/pazaryeri eşleşmesi, mağaza izolasyonu, belirsiz eşleşme, sıfır stok ve hesap tutarlılığı geçti.\n";
