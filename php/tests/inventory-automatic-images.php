<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryCatalogPage,InventoryManual,InventorySettings,InventoryImageCandidates,InventoryImageSource,InventoryImageQueue,InventoryImageFile,InventoryImages,InventoryAutomaticImage,InventoryImageHttp,SyncFailure};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Denetim','x','admin',NULL)");
foreach([1,2] as $id){(new InventorySettings($db))->ensureJob($id);$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$id,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);}
$checks=0;function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function photo():string{$im=imagecreatetruecolor(1200,800);imagefill($im,0,0,imagecolorallocate($im,255,255,255));imagefilledrectangle($im,200,100,1000,600,imagecolorallocate($im,20,30,40));ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;}
$raw=photo();$folder=sys_get_temp_dir().'/omnigo-auto-images-'.bin2hex(random_bytes(6));$files=new InventoryImageFile($folder);$queue=new InventoryImageQueue($db);$catalog=new InventoryCatalog($db);$calls=[];
$source=new InventoryImageSource(static function($url)use(&$calls,$raw){$calls[]=$url;return $raw;},[]);
try{
 $row=InventoryCatalogPage::normalize('n11',['totalPages'=>1,'content'=>[['n11ProductId'=>123,'title'=>'Örnek ürün','stockCode'=>'SKU1','quantity'=>5,'imageUrls'=>['https://cdn.example.com/sku1.png']]]],0)['items'][0];
 verify($row['imageCandidates']===['https://cdn.example.com/sku1.png'],'n11 tam ürün satırının görselini taşır.');
 $key=$catalog->ingest(1,'n11',$row);$catalog->ingest(1,'n11',$row);$other=$catalog->ingest(2,'n11',$row);
 verify(count($db->all('SELECT * FROM inventory_image_jobs'))===2,'İşler müşteriye bağlıdır ve tekrarlı aktarım çoğaltmaz.');verify($calls===[],'Katalog aktarımı görsel indirmez.');
 $before=$db->all('SELECT company_id,product_key,title,sku,barcode,quantity,baseline_at,updated FROM inventory_products ORDER BY company_id');$listings=$db->all('SELECT * FROM inventory_listings ORDER BY company_id');
 $result=$queue->run(time()+30,$files,$source);verify($result['saved']===2,'İki müşterinin ürünleri arka planda hazırlanır.');
 verify($before===$db->all('SELECT company_id,product_key,title,sku,barcode,quantity,baseline_at,updated FROM inventory_products ORDER BY company_id'),'Görsel işi stok, ürün alanları ve başlangıç tarihini değiştirmez.');verify($listings===$db->all('SELECT * FROM inventory_listings ORDER BY company_id'),'Kaynak ilanlar değişmez.');
 $images=new InventoryImages($db,$files);$saved=$images->read(1,$key);$size=getimagesizefromstring($saved);verify($size[0]===1024&&$size[1]===1024,'Ortak 1024 kare çerçeve oluşturulur.');
 $decoded=imagecreatefromstring($saved);verify((imagecolorat($decoded,0,0)&0xffffff)===0xffffff,'Zemin beyazdır.');$left=1024;$right=0;$top=1024;$bottom=0;
 for($y=0;$y<1024;$y++)for($x=0;$x<1024;$x++)if((imagecolorat($decoded,$x,$y)&0xffffff)<0xf0f0f0){$left=min($left,$x);$right=max($right,$x);$top=min($top,$y);$bottom=max($bottom,$y);}imagedestroy($decoded);
 verify(abs(($left+$right)/2-512)<3&&$bottom>=920&&$bottom<=930,'Ürün ortalanır ve aynı alt hizayı korur.');
 verify($db->one('SELECT COUNT(*) n FROM inventory_ledger')['n']===0,'Görsel işi stok hareketi oluşturmaz.');
 verify($queue->run(time()+20,$files,$source)['saved']===0&&count($calls)===2,'Tamamlanan görsel tekrar indirilmez.');
 verify($db->one('SELECT image_url FROM inventory_products WHERE company_id=1')['image_url']!==$db->one('SELECT image_url FROM inventory_products WHERE company_id=2')['image_url'],'Müşterilerin özel dosyaları ayrıdır.');
 $manual=(new InventoryManual($db))->create(1,['title'=>'Profilo Yeni','sku'=>'MANUAL1','brand'=>'Profilo','model'=>'TESTMODEL','barcode'=>'','quantity'=>7,'connect'=>false,'requestId'=>'auto-image-manual-1234'],1);
 verify($db->one('SELECT state FROM inventory_image_jobs WHERE company_id=1 AND product_key=?',[$manual['key']])['state']==='queued','Manuel yeni ürün de otomatik görsel işi alır.');
 $job=$queue->claim();$version=(int)$db->one('SELECT version FROM inventory_products WHERE company_id=1 AND product_key=?',[$manual['key']])['version'];$images->change(1,$manual['key'],$version,$raw,1);
 verify(!(new InventoryAutomaticImage($db,$files))->save($job,$saved,'https://cdn.example.com/source'),'Elle yüklenen görsel, geç gelen otomatik işlemi durdurur.');
 $version=(int)$db->one('SELECT version FROM inventory_products WHERE company_id=1 AND product_key=?',[$manual['key']])['version'];$images->change(1,$manual['key'],$version,null,1);verify($queue->claim()===null,'Elle kaldırılan görsel otomatik geri konmaz.');
 $key2=$catalog->ingest(1,'n11',[...$row,'id'=>'456','sku'=>'SKU2']);$job=$queue->claim();$queue->fail($job,new SyncFailure(429,3600));$failed=$db->one('SELECT * FROM inventory_image_jobs WHERE product_key=?',[$key2]);verify($failed['state']==='retry'&&(int)$failed['next_run']>=time()+3599,'Kaynak sınırında Retry-After korunur.');verify($queue->claim()===null,'Bekleme süresinde yeniden çağrı yapılmaz.');
 $db->run('UPDATE inventory_image_jobs SET next_run=0 WHERE product_key=?',[$key2]);$job=$queue->claim();verify($queue->claim()===null,'Aynı ürün eşzamanlı iki işçiye verilmez.');
 $db->run('UPDATE inventory_image_jobs SET lease_until=? WHERE product_key=?',[time()-1,$key2]);verify(!(new InventoryAutomaticImage($db,$files))->save($job,$saved,'https://cdn.example.com/source'),'Süresi bitmiş iş görsel yazamaz.');verify($queue->claim()!==null,'Kesilmiş görev kira süresi sonunda yeniden alınır.');
 $html='<script type="application/ld+json">'.json_encode(['@type'=>'Product','mpn'=>'TEST123','image'=>['https://media3.bsh-group.com/Product_Shots/test_STP_def.webp']]).'</script>';
 verify(InventoryImageSource::productImage($html,'WRONG123')===null,'Benzer modelin resmi yanlış ürüne atanmaz.');verify(InventoryImageSource::productImage($html,'TEST123')==='https://media3.bsh-group.com/Product_Shots/2000x/test_STP_def.webp','Üreticide tam model ve yüksek çözünürlük seçilir.');
 foreach(['http://cdn.example.com/a.png','https://user:secret@example.com/a.png','https://127.0.0.1/a.png','https://example.com:8443/a.png','file:///tmp/a.png'] as $url)verify(!InventoryImageCandidates::valid($url),'Güvensiz URL reddedilir.');
 foreach(['127.0.0.1','10.0.0.1','192.168.1.1','169.254.169.254','100.64.0.1','198.18.0.1','224.0.0.1','203.0.113.1','::1','::ffff:127.0.0.1'] as $ip)verify(!InventoryImageHttp::publicAddress($ip),'Yerel ağ, özel kullanım ve meta veri adresi reddedilir.');
 verify(InventoryImageCandidates::extract(['description'=>'https://wrong.example.com/image.png','images'=>[['url'=>'https://cdn.example.com/a.png'],['url'=>'javascript:bad']]])===['https://cdn.example.com/a.png'],'Açıklama ve geçersiz adreslerden görsel toplanmaz.');
 echo "inventory-automatic-images: $checks checks passed\n";
}finally{foreach(glob($folder.'/*/*.png')?:[] as $file)unlink($file);foreach(glob($folder.'/*',GLOB_ONLYDIR)?:[] as $part)rmdir($part);if(is_dir($folder))rmdir($folder);}
