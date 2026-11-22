<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{CustomerTransferStart,Database,HttpError,IntegrationVault,InventoryActivationRequest,InventorySchema,InventoryActivation,InventorySettings,InventoryManual,InventoryMatching,InventoryCatalog,InventoryRead,UpcomingManualPayout};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yetkili','x','admin',NULL)");
$payload=['workspacePolicy'=>['inventory'=>['mode'=>'both'],'pages'=>['inventory'=>'shown']]];
foreach([1,2] as $id)$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$id,json_encode($payload)]);
$keyPath=tempnam(sys_get_temp_dir(),'inventory-test-');file_put_contents($keyPath,random_bytes(32));
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejected(callable $fn):void{try{$fn();}catch(DomainException){verify(true,'Beklenen ret');return;}throw new RuntimeException('İşlem reddedilmedi.');}
try{
 $vault=new IntegrationVault($db,$keyPath);$activation=new InventoryActivation($db,$vault);
 verify(!$activation->state(1)['canStart'],'Mağaza yokken aktarım başlamaz.');
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'channels',json_encode(['name'=>'Hepsiburada','storeName'=>'Örnek','status'=>'active']),gmdate('c')]);
 verify(!$activation->state(1)['canStart'],'API yokken aktarım başlamaz.');
 $vault->save(1,['enabled'=>true,'merchantId'=>'test'],'hepsiburada');
 verify(!$activation->state(1)['canStart'],'Yalnız API kaydı başarı sayılmaz.');
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'sync_hepsiburada',json_encode(['lastSuccessAt'=>gmdate('c')]),gmdate('c')]);
 $db->run("INSERT INTO jobs(company_id,provider,state,next_run) VALUES(1,'Hepsiburada','idle',0)");
 verify($activation->state(1)['canStart'],'Doğrulanmış bağlantı başlatılabilir.');
 rejected(fn()=>$activation->start(1,1,0));
 verify($activation->start(1,1,1)['marketplaceActive'],'Doğru sürümde açık başlangıç.');
 $started=$db->one('SELECT started_at FROM inventory_tracking WHERE company_id=1')['started_at'];
 $activation->start(1,1,1);
 verify($db->one('SELECT started_at FROM inventory_tracking WHERE company_id=1')['started_at']===$started,'İkinci cihaz geçmiş başlangıcı sıfırlamaz.');
 verify((int)$db->one('SELECT COUNT(*) n FROM inventory_catalog_jobs')['n']===1,'Tek pazaryeri görevi.');
 verify(!$activation->state(2)['marketplaceActive'],'Diğer müşteri başlamaz.');
 $payload['workspacePolicy']['pages']['inventory']='hidden';
 $db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($payload)]);
 verify((new InventorySettings($db))->marketplaceEnabled(1),'Gizlemek aktarımı durdurmaz.');
 rejected(fn()=>$activation->start(1,1,1));
 $manual=(new InventoryManual($db))->create(1,['requestId'=>'manual-request-test-001','title'=>'Ürün','sku'=>'TEST-SKU','quantity'=>12,'connect'=>false],1);
 $productKey=$db->one('SELECT product_key FROM inventory_manual_products WHERE company_id=1')['product_key'];
 $match=new InventoryMatching($db);$match->request(1,$productKey,[['provider'=>'Hepsiburada','code'=>'TEST-SKU']]);
 $db->run('UPDATE inventory_jobs SET lease_token=?,lease_until=? WHERE company_id=1',['test-lease',time()+100]);
 $match->process(1,'test-lease');
 verify($db->one('SELECT state FROM inventory_links WHERE company_id=1')['state']==='pending','Güncel katalog beklenir.');
 (new InventoryCatalog($db))->ingest(1,'Hepsiburada',['id'=>'HB-T1','sku'=>'TEST-SKU','title'=>'Ürün','quantity'=>25]);
 $db->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=1',[gmdate('c')]);
 $match->process(1,'test-lease');
 verify($db->one('SELECT state FROM inventory_links WHERE company_id=1')['state']==='active','Kesin SKU eşleşmesi etkinleşir.');
 verify((int)$db->one('SELECT quantity FROM inventory_products WHERE company_id=1 AND product_key=?',[$productKey])['quantity']===12,'Eşleştirme mağaza başlangıcını değiştirmez.');
 verify((new InventoryRead($db))->listing(2)['total']===0,'Diğer müşteri ürünleri boş kalır.');
 $db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
  [2,'channels',json_encode(['name'=>'PTT AVM','storeName'=>'Diğer mağaza','status'=>'active']),gmdate('c')]);
 $transfer=new CustomerTransferStart($db,$keyPath);
 $request=['profileVersion'=>1,'connections'=>(object)['pttavm'=>(object)['marketVersion'=>1,'version'=>0,'accessToken'=>'fixture-token','apiKey'=>'fixture-key']]];
 try {
    $transfer->request(['id'=>2,'role'=>'client','company_id'=>2],2,$request);
    throw new RuntimeException('Müşteri rolü aktarımı açamadı.');
 }catch(HttpError $error){verify($error->getCode()===403,'Yalnız Omnigo kullanıcıları başlangıcı açabilir.');}
 verify($vault->get(2,'pttavm')===null,'Reddedilen başlangıç anahtar saklamaz.');
 $started=$transfer->request(['id'=>1,'role'=>'admin'],2,$request);
 verify($started['markets']->pttavm['state']==='scheduled'&&$started['inventory']==='scheduled','Müşterinin sipariş ve stok işleri sıraya alınır.');
 verify(!str_contains(json_encode($started),'fixture-'),'Aktarım sonucu API anahtarını döndürmez.');
 verify($vault->get(1,'pttavm')===null,'Bağlantı diğer müşteriye işlenmez.');
 verify(UpcomingManualPayout::channels($db,2,['PTT AVM','Hepsiburada'])===['PTT AVM'],'Manuel kaynak yalnız çalışılan uygun pazaryerlerinde gösterilir.');
 $activationRequests=new InventoryActivationRequest($db);
 $activationRequests->process(2,$activation);
 verify(!$activation->state(2)['marketplaceActive'],'İlk başarılı okuma gelene kadar stok güvenle bekler.');
 $db->run("UPDATE jobs SET next_run=? WHERE company_id=2 AND provider='PTT AVM'",[time()+650]);
 $before=$db->one("SELECT next_run FROM jobs WHERE company_id=2 AND provider='PTT AVM'");
 $transfer->request(['id'=>1,'role'=>'admin'],2,['profileVersion'=>1,'connections'=>[]]);
 verify($db->one("SELECT next_run FROM jobs WHERE company_id=2 AND provider='PTT AVM'")===$before,'Tekrar başlatma mevcut bekleme süresini bozmaz.');
 $db->run("UPDATE records SET payload=? WHERE company_id=2 AND kind='sync_pttavm'",[json_encode(['lastSuccessAt'=>gmdate('c')])]);
 $db->run('UPDATE company_profiles SET version=2 WHERE company_id=2');
 $activationRequests->process(2,$activation);
 verify(!$activation->state(2)['marketplaceActive'],'Arada ayarlar değiştiyse eski stok başlangıç isteği uygulanmaz.');
 $transfer->request(['id'=>1,'role'=>'admin'],2,['profileVersion'=>2,'connections'=>[]]);
 $activationRequests->process(2,$activation);
 verify($activation->state(2)['marketplaceActive'],'İlk olumlu kontrol sonrasında stok sunucuda başlar.');
 $activationRequests->process(2,$activation);
 verify((int)$db->one("SELECT COUNT(*) n FROM audit WHERE company_id=2 AND action='Stok Yönetimi canlı aktarımı başlatıldı'")['n']===1,'Tekrarlanan iş tek başlangıç üretir.');
 echo "$checks başlangıç, kesinti, eşleştirme ve müşteri ayrımı kontrolü geçti.\n";
}finally{unlink($keyPath);}
