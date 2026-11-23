<?php
declare(strict_types=1);require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventoryProgress,InventorySettings,ShipmentSchema,OperationSchema,AttentionSources,AttentionCenter,DeliveryCipher};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);ShipmentSchema::install($db);OperationSchema::install($db);Omnigo\DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Omnigo','x','admin',NULL)");$admin=['id'=>1,'role'=>'admin'];$cipher=DeliveryCipher::withKey(random_bytes(32));
foreach([1,2]as$company){$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'horoz']]])]);(new InventoryProgress($db))->ensure($company,'hepsiburada');(new InventorySettings($db))->ensureJob($company);}
$db->run('UPDATE inventory_catalog_jobs SET last_success=?',[gmdate('c')]);$catalog=new InventoryCatalog($db);$source=['id'=>'A','sku'=>'SKU-A','title'=>'Ürün A','quantity'=>5,'saleActive'=>false];$key=$catalog->ingest(1,'Hepsiburada',$source);$catalog->ingest(2,'Hepsiburada',$source);
$center=new AttentionCenter($db,new AttentionSources($db,$cipher));
$tables=['inventory_products','inventory_listings','inventory_jobs','inventory_ledger'];$before=[];foreach($tables as$table)$before[$table]=$db->all('SELECT * FROM '.$table);
foreach([1,2] as $company){
    check($center->listing($admin,$company,['type'=>'inventory'])['total']===0,'Eski eşleşme filtresi boş döner.');
    check($center->pendingCount($admin,$company)['pendingTotal']===0,'Liste ve sayaç satışa kapalı ürün uyarısını içermez.');
}
foreach($tables as$table)check($before[$table]===$db->all('SELECT * FROM '.$table),'Takip değişikliği stok verisini değiştirmez: '.$table);
$catalog->ingest(1,'Hepsiburada',[...$source,'saleActive'=>true]);
$catalog->ingest(1,'Hepsiburada',$source);
check($center->listing($admin,1,['type'=>'inventory'])['total']===0,'Yeni kapanma da artık uyarı üretmez.');
foreach(['2026-06-01 12:00:00','2026-10-01 12:00:00']as$date){$db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated,ordered_at) VALUES(1,'Ankara','n11',?,'{}',?,?,?)",[substr($date,0,7),gmdate('c'),gmdate('c'),$date]);$id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$id,['marketplaceRowStatus'=>'pending','productName'=>'Örnek']),$id]);}
$item=$center->listing($admin,1,['type'=>'shipments'])['items'][0];check($item['due']==='2026-10-01'&&$center->listing($admin,1,['type'=>'shipments'])['total']===1,'Ekim öncesi bekleyiş liste dışında');
$center->save($admin,1,[...$item,'decision'=>'dismiss','note'=>'','assigneeId'=>null]);check($center->listing($admin,1,['type'=>'shipments'])['total']===0,'Sevkiyat uyarısı da çözümlenmeden kaldırılabilir');
$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal(1,'shipment:'.$item['source']['id'],['marketplaceRowStatus'=>'shipped']),$item['source']['id']]);$center->listing($admin,1,['type'=>'shipments']);check(!$db->one('SELECT issue_key FROM operation_attention WHERE issue_key=?',[$item['key']]),'Kaynak sevk edilince kaldırılmış uyarı da canlı değerlendirilir');
check((int)$db->one('SELECT COUNT(*) n FROM shipment_entries')['n']===2,'Eski asıl sipariş silinmez');
echo "Uyarı silme: tüm konu türleri, kaynak verisini koruma, sonraki katalog aktarımı, yeni durum, otomatik temizleme, mağaza ayrımı ve Ekim sınırı geçti.\n";
