<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,FileCenterSchema,IntegrationVault,InventoryCatalog,InventoryProgress,InventorySchema,InventorySettings,NewInventoryRecords};

function check(bool $value,string $message):void {
    if (!$value) throw new RuntimeException($message);
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
InventorySchema::install($db);
FileCenterSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$keyPath=tempnam(sys_get_temp_dir(),'table-catalog');
file_put_contents($keyPath,random_bytes(32));
try {
    $vault=new IntegrationVault($db,$keyPath);
    $source=['id'=>'listing-1','sku'=>'SKU-1','title'=>'Kaynak adı','quantity'=>2,'offerPriceCents'=>10400];
    $catalog=new InventoryCatalog($db);
    $requested=time()-10;
    foreach ([1,2] as $company) {
        $db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$company,json_encode(['workspacePolicy'=>['inventory'=>['mode'=>'both']]])]);
        $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'channels',?,?)",[$company,json_encode(['name'=>'n11','status'=>'active','selected'=>true]),gmdate('c')]);
        $vault->save($company,['enabled'=>true,'revision'=>'v1','startDate'=>'2026-06-01'],'n11');
        (new InventorySettings($db))->ensureJob($company);
        $db->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(?,?)',[$company,gmdate('c',$requested-100)]);
        (new InventoryProgress($db))->ensure($company,'n11');
        $key=$catalog->ingest($company,'n11',$source);
        $db->run('UPDATE inventory_products SET title=?,quantity=77 WHERE company_id=? AND product_key=?',['Elle düzeltilmiş ad',$company,$key]);
        foreach (['inventory_removed_products','file_center_hidden_products'] as $table) {
            $db->run("INSERT INTO $table(company_id,product_key,actor_id,removed_at) VALUES(?,?,1,?)",[$company,$key,gmdate('c',$requested-60)]);
        }
    }
    $sourceRow=['n11ProductId'=>'listing-1','stockCode'=>'SKU-1','title'=>'Yeni pazaryeri adı','quantity'=>9,'salePrice'=>120];
    $newRow=['n11ProductId'=>'listing-2','stockCode'=>'SKU-2','title'=>'Yeni ürün','quantity'=>3];
    $entries=[$sourceRow,$newRow];
    $requestedPage=static function() use (&$entries) {return ['content'=>$entries,'totalPages'=>1];};
    $reader=new NewInventoryRecords($db,$vault,$requestedPage);
    $run=static function(string $table,string $mode) use ($reader,$requested) {
        return $reader->step(1,'n11',['target'=>compact('table','mode')+['requestedAt'=>$requested]],time()+25);
    };
    $hidden=fn(string $table,int $company): bool=>(bool)$db->one("SELECT removed_at FROM $table WHERE company_id=? AND product_key=?",[$company,$key]);
    $first=$run('inventory','incremental');
    check($first['done'] && $first['added']===1 && $hidden('inventory_removed_products',1),'Artımlı aktarımda yalnız yeni ilan alınır ve elle kaldırma korunur');
    check($run('inventory','incremental')['added']===0,'Tekrar kayıt çoğaltamaz');
    $entries=[$newRow];
    check($run('inventory','full')['added']===0 && $hidden('inventory_removed_products',1),'Pazaryerinin artık sunmadığı ürün geri getirilemez');
    $entries=[$sourceRow,$newRow];
    check($run('fileCenter','full')['added']===1 && !$hidden('file_center_hidden_products',1),'Dosya Merkezi gerçek kaynakla eşleşen kendi silmesini geri alır');
    check($hidden('inventory_removed_products',1) && $hidden('file_center_hidden_products',2),'Diğer tablo ve mağaza ayrı tutulur');
    $replayed=$db->one('SELECT title,quantity FROM inventory_products WHERE company_id=1 AND product_key=?',[$key]);
    check($replayed['title']==='Elle düzeltilmiş ad' && (int)$replayed['quantity']===77,'Manuel başlık ve mağaza stoğu korunur');
    check((int)$db->one('SELECT source_quantity FROM inventory_listings WHERE company_id=1 AND product_key=?',[$key])['source_quantity']===9,'Pazaryerinin kendi stok bilgisi güncellenir');
    check($run('inventory','full')['added']===1 && !$hidden('inventory_removed_products',1) && $hidden('inventory_removed_products',2),'Stok hedefi kendi mağazasındaki kaldırmayı yeniden açar');
    $db->run('INSERT INTO inventory_removed_products(company_id,product_key,actor_id,removed_at) VALUES(1,?,1,?)',[$key,gmdate('c',$requested)]);
    check($run('inventory','full')['added']===0 && $hidden('inventory_removed_products',1),'Tıklama ile aynı anda ya da sonrasında yapılan kaldırma geçerlidir');
    echo "Katalog okuyucusu: aktif kaynak, stok ve dosya kapsamı, manuel alanlar, artımlı/yineleme, şirket ve zaman koruması geçti.\n";
} finally {
    unlink($keyPath);
}
