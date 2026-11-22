<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,AccountDirectorySchema,BackupArchive,StreamingBackup,BackupRestore,BackupFiles};
function verify(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fresh():Database{$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);AccountDirectorySchema::ensure($db);return $db;}
$folder=sys_get_temp_dir().'/omnigo-backup-extension-'.bin2hex(random_bytes(6));mkdir($folder,0700);
try{
 $source=fresh();$key=random_bytes(32);$source->run("INSERT INTO companies VALUES(1,'Yedek Testi')");$source->run("INSERT INTO users VALUES(1,'fixture@example.invalid','Test','hash','admin',NULL)");
 $source->run("INSERT INTO account_profiles(user_id,avatar,deleted_at) VALUES(1,'encoded-test-avatar',NULL)");$source->run("INSERT INTO account_audit(actor_id,target_id,action,created) VALUES(1,1,'Rol değişikliği',1)");
 $product=str_repeat('a',64);$source->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,quantity,updated) VALUES(1,?,?,?,?,?,?,?)',[$product,'Ürün','TEST-1','','',7,gmdate('c')]);
 $source->run('INSERT INTO inventory_ledger(company_id,event_key,product_key,kind,delta,quantity_after,request_hash,actor_id,note,created) VALUES(1,?,?,?,?,?,?,?,?,?)',[str_repeat('b',64),$product,'store_sale',-2,7,str_repeat('c',64),1,'Mağaza satışı',gmdate('c')]);
 $source->run("INSERT INTO inventory_jobs(company_id,enabled,lease_token,lease_until) VALUES(1,1,'previous-lease',9999999999)");
 $source->run('INSERT INTO inventory_commands(company_id,request_id,product_key,expected_version,mode,amount,actor_id,note,request_hash,created,updated) VALUES(1,?,?,1,?,5,1,?,?,?,?)',['pending-fixture-command',$product,'set','Sayım',str_repeat('d',64),gmdate('c'),gmdate('c')]);
 $source->run('INSERT INTO inventory_shared_stock(company_id,product_key,adjustment,manual_quantity) VALUES(1,?,-3,NULL)',[$product]);
 $source->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,version,actor_id,updated) VALUES(1,?,3061122,2,1,?)',[$product,gmdate('c')]);
 $source->run('INSERT INTO inventory_command_context(company_id,request_id,stock_mode,source_version) VALUES(1,?,?,?)',['pending-fixture-command','horoz',str_repeat('e',64)]);
 $source->run('INSERT INTO inventory_price_requests(company_id,request_id,request_hash,result) VALUES(1,?,?,?)',['price-fixture-command',str_repeat('f',64),'{"purchasePriceCents":3061122}']);
 $source->run('INSERT INTO inventory_change_events(company_id,event_key,product_key,actor_id,payload,created) VALUES(1,?,?,1,?,?)',[str_repeat('g',64),$product,'{"kind":"purchase_price_updated"}',gmdate('c')]);
 Omnigo\InventoryProductPosition::remember($source,1,$product);
 Omnigo\InventoryPurchaseTax::save($source,1,$product,2,Omnigo\InventoryPurchaseTax::normalize(['amountCents'=>3061122,'vatBasis'=>'included','vatRateBasisPoints'=>2000]));
 $archive=BackupArchive::create($source,$key);$target=fresh();BackupArchive::restore($target,$archive,$key);
 verify($source->all('SELECT * FROM inventory_product_positions')===$target->all('SELECT * FROM inventory_product_positions'),'Ürün sırası yedekten aynı şekilde dönmeli.');
 foreach(['account_profiles','account_audit','inventory_products','inventory_ledger','inventory_shared_stock','inventory_purchase_prices','inventory_purchase_tax','inventory_command_context','inventory_price_requests'] as $table)verify($source->all("SELECT * FROM $table")===$target->all("SELECT * FROM $table"),'Eksiksiz geri yükleme: '.$table);
 verify($target->one('SELECT state FROM inventory_change_events')['state']==='cancelled','Yedek sonrası eski fiyat/stok bildirimleri tekrar gönderilmez');
 verify((int)$target->one('SELECT enabled FROM inventory_jobs')['enabled']===0,'Yedek eski stok işini kendiliğinden başlatmaz');verify($target->one('SELECT state FROM inventory_commands')['state']==='failed','Bekleyen manuel stok hareketi tekrar uygulanmaz');
 $photo=file_get_contents(dirname(__DIR__,2).'/public/assets/app-192.png');$imageKey=(new \Omnigo\InventoryImageFile($folder.'/inventory-images'))->write(1,$photo);$source->run('UPDATE inventory_products SET image_url=?',['inventory-image:'.$imageKey]);
 $path=$folder.'/test.ogbackup';$summary=StreamingBackup::create($source,$key,$path,$folder);verify($summary['rows']['inventory_ledger']===1&&$summary['rows']['account_audit']===1&&$summary['documents']===1,'Akış yedeği yeni tabloları ve ürün fotoğrafını doğrular');
 $streamTarget=fresh();BackupRestore::restore($streamTarget,$path,$key,$folder.'/restored');verify($streamTarget->all('SELECT * FROM inventory_ledger')===$source->all('SELECT * FROM inventory_ledger'),'Akış geri yükleme stok geçmişini korur');verify((int)$streamTarget->one('SELECT enabled FROM inventory_jobs')['enabled']===0,'Akış geri yüklemede kuyruk kapalı kalır');verify((new \Omnigo\InventoryImageFile($folder.'/restored/inventory-images'))->read(1,$imageKey)===$photo,'Ürün fotoğrafı aynı müşteri altında eksiksiz geri gelir');
 foreach(['inventory_shared_stock','inventory_purchase_prices','inventory_purchase_tax','inventory_command_context','inventory_price_requests'] as $table)verify($source->all("SELECT * FROM $table")===$streamTarget->all("SELECT * FROM $table"),'Akış yedeği yeni stok/fiyat bilgilerini korur: '.$table);
 verify($streamTarget->one('SELECT state FROM inventory_change_events')['state']==='cancelled','Akış yedeği eski değişiklik bildirimini yeniden yayımlamaz');
 $source->run('CREATE TABLE unknown_fixture(id INTEGER)');$rejected=false;try{BackupArchive::checkSchema($source);}catch(RuntimeException){$rejected=true;}verify($rejected,'Bilinmeyen tablo sessizce yedek dışında bırakılamaz');
 echo "Stok ve hesap yedeği: iki biçimde kayıt/geçmiş/resim koruma, geri yükleme, kuyruk güvenliği ve bilinmeyen şema reddi geçti.\n";
}finally{BackupFiles::removeStaging($folder);}
