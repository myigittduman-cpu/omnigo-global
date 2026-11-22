<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventorySettings,InventoryImages,InventoryImageFile,InventoryVisibility,InventoryRead};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yetkili','x','admin',NULL)");
foreach([1,2] as $company)(new InventorySettings($db))->ensureJob($company);
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejected(callable $fn):void{try{$fn();}catch(DomainException|OutOfBoundsException|InvalidArgumentException){verify(true,'Beklenen ret');return;}throw new RuntimeException('İşlem reddedilmedi.');}
function photo(int $w,int $h):string{$im=imagecreatetruecolor($w,$h);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,20,40,80,80));ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;}
$folder=sys_get_temp_dir().'/omnigo-inventory-images-test-'.bin2hex(random_bytes(6));
$files=new InventoryImageFile($folder);$images=new InventoryImages($db,$files);$catalog=new InventoryCatalog($db);
$feed=fn(int $company,int $qty)=>$catalog->ingest($company,'Hepsiburada',['id'=>'HB1','sku'=>'SKU1','title'=>'Buzdolabı','quantity'=>$qty,'saleActive'=>true,'image'=>'https://example.test/ignored.jpg']);
$key=$feed(1,8);$feed(2,8);$product=fn(int $c)=>$db->one('SELECT * FROM inventory_products WHERE company_id=? AND product_key=?',[$c,$key]);
$countFiles=fn()=>count(glob($folder.'/*/*.png')?:[]);
$version=fn()=>(int)$product(1)['version'];
try{
 $before=$product(1);$listing=$db->all('SELECT * FROM inventory_listings ORDER BY company_id');
 $result=$images->change(1,$key,$version(),photo(1600,800).'<script>metadata</script>',1);
 verify(str_starts_with($result['imageUrl'],'/api/inventory/image?company=1&productKey='),'Görsel yalnız yetkili uçtan sunulur.');
 $bytes=$images->read(1,$key);$size=getimagesizefromstring($bytes);
 verify($size[0]===1024&&$size[1]===512,'Oran korunarak yüksek çözünürlükte hazırlanır.');
 verify(!str_contains($bytes,'<script>'),'Dosya yeniden kodlanır, ek içerik saklanmaz.');
 $decoded=imagecreatefromstring($bytes);verify((imagecolorat($decoded,0,0)>>24)>0,'Şeffaflık korunur.');imagedestroy($decoded);
 verify($product(1)['quantity']===$before['quantity']&&$product(1)['baseline_at']===$before['baseline_at'],'Görsel stok ve başlangıç değerini değiştirmez.');
 verify($listing===$db->all('SELECT * FROM inventory_listings ORDER BY company_id'),'Kaynak ilan ve stoklar korunur.');
 verify($product(2)['image_url']==='','Aynı ürünün diğer müşterideki görseli değişmez.');
 rejected(fn()=>$images->read(2,$key));
 rejected(fn()=>$images->change(2,hash('sha256','yok'),1,photo(20,20),1));
 rejected(fn()=>$files->read(1,'../../other'));
 rejected(fn()=>$images->change(1,$key,$version(),'<svg xmlns="http://www.w3.org/2000/svg"></svg>',1));
 rejected(fn()=>$images->change(1,$key,$version(),str_repeat('x',5242881),1));
 rejected(fn()=>$images->change(1,$key,$version(),photo(4097,1),1));
 rejected(fn()=>$images->change(1,$key,$version()+1,photo(20,20),1));
 verify($countFiles()===1,'Reddedilen işlemlerin geçici dosyaları temizlenir.');
 $old=$product(1)['image_url'];$feed(1,11);
 verify($product(1)['image_url']===$old,'Katalog yenilemesi elle yüklenen görseli korur.');
 verify(str_starts_with((new InventoryRead($db))->listing(1,'',0,'all')['items'][0]['imageUrl'],'/api/inventory/image?'),'Liste özel dosya yolunu açığa çıkarmaz.');
 $images->change(1,$key,$version(),photo(100,200),1);
 verify($countFiles()===1&&$product(1)['image_url']!==$old,'Değiştirmede eski görsel temizlenir.');
 $visibility=new InventoryVisibility($db);$visibility->change(1,['productKey'=>$key,'version'=>$version(),'action'=>'remove'],1);
 rejected(fn()=>$images->change(1,$key,$version(),photo(20,20),1));
 verify($countFiles()===1,'Silinmiş ürünün görseli değiştirilemez; dosya sızmaz.');
 $visibility->change(1,['productKey'=>$key,'version'=>$version(),'action'=>'restore'],1);
 $images->change(1,$key,$version(),null,1);
 verify($countFiles()===0&&$product(1)['image_url']==='','Görsel kaldırma dosyayı ve referansı temizler.');
 rejected(fn()=>$images->read(1,$key));
 verify((int)$db->one('SELECT COUNT(*) n FROM inventory_ledger')['n']===0,'Görsel işleminde stok hareketi oluşmaz.');
 verify((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action LIKE 'Stok ürün görseli%'")['n']===3,'Başarılı görsel işlemleri denetlenebilir.');
 echo "inventory-images: $checks checks passed\n";
}finally{
 foreach(glob($folder.'/*/*.png')?:[] as $file)unlink($file);
 foreach(glob($folder.'/*',GLOB_ONLYDIR)?:[] as $dir)rmdir($dir);
 if(is_dir($folder))rmdir($folder);
}
