<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryCatalogPage,InventoryMetadataWriter,InventoryMetadataQueue,IntegrationVault,HepsiburadaProductMetadataPage,SyncFailure};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$checks=0;function check(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
$raw=['success'=>true,'data'=>[['merchantSku'=>'A1','hbSku'=>'HB1','productName'=>'Gerçek ürün adı','barcode'=>'8694975450149','brand'=>'Profilo','images'=>['https://example.com/photo.jpg']]],'number'=>0,'last'=>true];
$page=HepsiburadaProductMetadataPage::normalize($raw,0);check(!$page['next']&&$page['items'][0]['title']==='Gerçek ürün adı','Kaynak ürün adı okunur');
$cat=new InventoryCatalog($db);$item=['id'=>'L1','title'=>'Stok kodu: A1','sku'=>'A1','quantity'=>5,'saleActive'=>true,'aliases'=>['HB1']];
$key=$cat->ingest(1,'Hepsiburada',$item);$other=$cat->ingest(2,'Hepsiburada',$item);
$db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,actor_id,updated) VALUES(1,?,12345,1,?)',[$key,gmdate('c')]);
$source=$db->one('SELECT source_quantity,observed_at FROM inventory_listings WHERE company_id=1');$writer=new InventoryMetadataWriter($db);
check($writer->pending(1)===1,'Adsız stoklu ürün kontrol bekler');
$queue=new InventoryMetadataQueue($db,new IntegrationVault($db,sys_get_temp_dir().'/unused-key'));$job=$queue->claim(1);check($job!==null&&$queue->claim(1)===null,'Tek kiralama ile aynı iş iki kez çalışmaz');
$queue->commit(1,$job,$page);
check($db->one('SELECT title FROM inventory_products WHERE company_id=1')['title']==='Gerçek ürün adı','Doğru stok satırının adı tamamlanır');
check($db->one('SELECT title FROM inventory_products WHERE company_id=2')['title']==='Stok kodu: A1','Diğer mağaza etkilenmez');
check($source===$db->one('SELECT source_quantity,observed_at FROM inventory_listings WHERE company_id=1'),'Kaynak miktar ve gözlem tarihi değişmez');
check((int)$db->one('SELECT amount_cents FROM inventory_purchase_prices WHERE company_id=1')['amount_cents']===12345,'Alış fiyatı ürünle aynı kalır');
check(InventoryMetadataQueue::status($db,1)['pending']===0,'İsim tamamlanınca bekleme biter');
$cat->ingest(1,'Hepsiburada',$item);$evidence=json_decode($db->one('SELECT evidence FROM inventory_listings WHERE company_id=1')['evidence'],true);
check($evidence['imageCandidates']===['https://example.com/photo.jpg'],'Sonraki stok sayfası ürün görselini silmez');
check($db->one('SELECT title FROM inventory_products WHERE company_id=1')['title']==='Gerçek ürün adı','Sonraki stok akışı adı tekrar koda döndürmez');
$second=$cat->ingest(1,'Hepsiburada',[...$item,'id'=>'L2','sku'=>'A2','title'=>'Stok kodu: A2','aliases'=>['HB2']]);
check($queue->claim(1)!==null,'Yeni adsız ürün normal bekleme süresini beklemeden kuyruğa girer');
$db->run("UPDATE inventory_metadata_jobs SET lease_until=0,next_run=0 WHERE company_id=1");$retry=$queue->claim(1);$queue->fail(1,$retry,new SyncFailure(429,600));
$state=$db->one('SELECT * FROM inventory_metadata_jobs WHERE company_id=1');check($state['state']==='retry'&&(int)$state['next_run']>=time()+599,'Rate limit gecikmesi korunur');
$ambiguous=[...$page['items'][0],'sku'=>'A1','hbSku'=>'HB2'];check(!$writer->apply(1,$ambiguous),'Çelişkili kimlikler birleştirilmez');
$barcodeKey=$cat->ingest(1,'Hepsiburada',[...$item,'id'=>'L3','sku'=>'TYB40195325D210911125444','title'=>'Stok kodu: TYB40195325D210911125444','aliases'=>[]]);
$barcodeMetadata=[...$page['items'][0],'sku'=>'503','hbSku'=>'','barcode'=>'TYB40195325D210911125444','title'=>'Kaynak barkodu ile tam eşleşen ürün'];
check($writer->apply(1,$barcodeMetadata)&&$db->one('SELECT title FROM inventory_products WHERE company_id=1 AND product_key=?',[$barcodeKey])['title']===$barcodeMetadata['title'],'Kaynak barkodu stok kodu olarak kullanılmışsa tam ve tek mağaza içi eşleşme kabul edilir');
check(!$writer->apply(1,[...$barcodeMetadata,'sku'=>'A1']),'Barkod başka stok koduyla çelişirse hiçbir satır güncellenmez');
$normalized=InventoryCatalogPage::normalize('hepsiburada',['listings'=>[['listingId'=>'L','merchantSku'=>'A','productName'=>'API içindeki ad','availableStock'=>2]],'totalCount'=>1],0);check($normalized['items'][0]['title']==='API içindeki ad','Listing içinde ad varsa doğrudan alınır');
try{HepsiburadaProductMetadataPage::normalize(['success'=>false,'data'=>[],'last'=>true],0);throw new RuntimeException('Hatalı kaynak kabul edildi');}catch(DomainException){$checks++;}
echo "Ürün bilgisi kontrolleri: $checks başarılı.\n";
