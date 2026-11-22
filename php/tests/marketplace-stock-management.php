<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventorySettings,MarketplaceStockSchema,MarketplaceStockPolicy,MarketplaceStockQueue,MarketplaceStockWorker,MarketplaceStockGateway,MarketplaceStockFailure,HepsiburadaStockGateway,HttpError};
$checks=0;function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejects(callable $action,string $message):void{try{$action();}catch(DomainException|InvalidArgumentException|OutOfBoundsException|HttpError){verify(true,$message);return;}throw new RuntimeException($message);}
final class FakeStockGateway implements MarketplaceStockGateway{
 public int $quantity=7,$posts=0,$reads=0;public string $statusValue='done';public ?MarketplaceStockFailure $postError=null,$readError=null;
 public function read(array $configuration,string $sku,int $deadline):array{$this->reads++;if($this->readError)throw $this->readError;return ['merchantSku'=>$sku,'hepsiburadaSku'=>'HBCVTEST1','remoteIds'=>['HBCVTEST1'],'quantity'=>$this->quantity];}
 public function submit(array $configuration,array $listing,int $quantity,int $deadline):string{$this->posts++;if($this->postError)throw $this->postError;$this->quantity=$quantity;return 'receipt-test-0001';}
 public function status(array $configuration,string $receipt,int $deadline):string{return $this->statusValue;}
}
function fixture():array{
 $db=new Database(['dsn'=>'sqlite::memory:']);$db->run('CREATE TABLE companies(id INTEGER PRIMARY KEY,name TEXT NOT NULL)');$db->run('CREATE TABLE company_profiles(company_id INTEGER PRIMARY KEY,payload TEXT,version INT DEFAULT 1)');$db->run('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,name TEXT,email TEXT)');$db->run('CREATE TABLE records(company_id INT,kind TEXT,version INT)');$db->run('CREATE TABLE account_controls(user_id INT,active INT)');$db->run('CREATE TABLE account_profiles(user_id INT,deleted_at TEXT)');$db->run('CREATE TABLE audit(user_id INT,company_id INT,action TEXT,record_id INT,after_value TEXT,created TEXT)');
 InventorySchema::install($db);MarketplaceStockSchema::install($db);
 foreach([1=>'Örnek A',2=>'Örnek B'] as $id=>$name){
  $inventory=['mode'=>'horoz'];
  if($id===2)$inventory['marketplaceManagement']=true;
  $db->run('INSERT INTO companies VALUES(?,?)',[$id,$name]);
  $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$id,json_encode(['workspacePolicy'=>['inventory'=>$inventory]])]);
  $db->run('INSERT INTO records VALUES(?,?,1)',[$id,'integration_hepsiburada']);
 }
 $db->run("INSERT INTO users VALUES(10,'admin','Örnek yönetici','admin@example.test')");$db->run("INSERT INTO users VALUES(20,'client','Örnek müşteri','client@example.test')");$key=str_repeat('a',64);$listing=str_repeat('b',64);
 $db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,quantity,updated) VALUES(2,?,?,?,?,?,5,?)',[$key,'Örnek ürün','SKU-A','','',gmdate('c')]);
 $db->run("INSERT INTO inventory_listings VALUES(2,'Hepsiburada',?,'HBCVTEST1',?,'SKU-A','{}',7,?)",[$listing,$key,gmdate('c')]);
 $db->run('INSERT INTO inventory_shared_stock(company_id,product_key,adjustment) VALUES(2,?,-2)',[$key]);
 $db->run('INSERT INTO inventory_purchase_prices VALUES(2,?,12345,1,10,?)',[$key,gmdate('c')]);
 $policy=new MarketplaceStockPolicy($db);$queue=new MarketplaceStockQueue($db,$policy);$gateway=new FakeStockGateway();$actor=['id'=>10,'role'=>'admin'];
 $worker=new MarketplaceStockWorker($db,$policy,$gateway,static fn()=>['enabled'=>true]);
 $input=['requestId'=>'request-stock-00001','productKey'=>$key,'listingKey'=>$listing,'quantity'=>9,'sourceQuantity'=>7,'confirmed'=>true];
 return [$db,$policy,$queue,$gateway,$worker,$actor,$input];
}
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();
verify($policy->enabled(2)&&!$policy->enabled(1),'Gönderim yalnız özelliği açıkça seçen örnek mağazada etkin olmalı.');
verify(InventorySettings::validate(['mode'=>'both'])===['mode'=>'both'],'Eski model biçimi değişmez.');
rejects(fn()=>$policy->validate(2,['mode'=>'both','marketplaceManagement'=>true]),'Farklı temel modele sessiz geçiş yapılamaz.');
$initial=\Omnigo\CustomerInitialPolicy::template();
verify(empty(\Omnigo\CustomerInitialPolicy::validate($initial)['inventory']['marketplaceManagement']),'Yeni müşteri bu mağazanın yazma yetkisini miras almaz.');
$initial['inventory']['marketplaceManagement']=true;
verify(\Omnigo\CustomerInitialPolicy::validate($initial)['inventory']['marketplaceManagement']===true,'Kurulumda bilinçli seçilen pazaryeri stok yönetimi korunmalı.');
rejects(fn()=>$queue->submit(2,$input,['id'=>20,'role'=>'client']),'Müşteri doğrudan API çağrısıyla stok gönderemez.');
rejects(fn()=>$queue->submit(1,$input,$actor),'Başka müşteriye stok gönderilemez.');
rejects(fn()=>$queue->submit(2,[...$input,'confirmed'=>false],$actor),'Gönderim onaysız yapılamaz.');
rejects(fn()=>$queue->submit(2,[...$input,'quantity'=>-1],$actor),'Negatif pazaryeri stoğu reddedilir.');
$before=serialize([$db->all('SELECT * FROM inventory_products'),$db->all('SELECT * FROM inventory_shared_stock'),$db->all('SELECT * FROM inventory_purchase_prices'),$db->all('SELECT * FROM inventory_ledger')]);
$result=$queue->submit(2,$input,$actor);verify($result['state']==='queued','Kuyruğa alma başarı olarak sunulmaz.');verify($queue->submit(2,$input,$actor)===$result,'Aynı istek ikinci kayıt oluşturmaz.');
rejects(fn()=>$queue->submit(2,[...$input,'quantity'=>10],$actor),'Aynı kimlikle farklı adet reddedilir.');
rejects(fn()=>$queue->submit(2,[...$input,'requestId'=>'request-stock-00002'], $actor),'Aynı ürünün bekleyen ikinci gönderimi reddedilir.');
$worker->tick(time()+30);verify($gateway->posts===1&&$queue->get(2,$input['requestId'],$actor)['state']==='accepted','İlk yanıt yalnız kabul edildi demektir.');
$db->run('UPDATE marketplace_stock_requests SET next_run=0');$gateway->statusValue='pending';$worker->tick(time()+30);verify($gateway->posts===1&&$queue->get(2,$input['requestId'],$actor)['state']==='accepted','İşlem beklerken POST tekrarlanmaz.');
$db->run('UPDATE marketplace_stock_requests SET next_run=0');$gateway->statusValue='done';$worker->tick(time()+30);verify($queue->get(2,$input['requestId'],$actor)['state']==='succeeded'&&$gateway->reads===2,'Tamamlanma ve gerçek adet okunarak doğrulanır.');
verify($before===serialize([$db->all('SELECT * FROM inventory_products'),$db->all('SELECT * FROM inventory_shared_stock'),$db->all('SELECT * FROM inventory_purchase_prices'),$db->all('SELECT * FROM inventory_ledger')]),'Yerel stok, manuel fark, alış fiyatı ve hareket defteri korunur.');
verify(count($db->all('SELECT * FROM audit'))===1,'Doğrulanan işlem yönetici ve müşteri kimliğiyle kaydedilir.');
rejects(fn()=>$queue->get(1,$input['requestId'],$actor),'Gönderim sonucu başka mağazadan okunamaz.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$gateway->quantity=6;$worker->tick(time()+30);verify($gateway->posts===0&&$queue->get(2,$input['requestId'],$actor)['state']==='failed','Canlı adet değişmişse eski onay yeni stoğu ezmez.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$gateway->postError=new MarketplaceStockFailure(uncertain:true);$worker->tick(time()+30);verify($queue->get(2,$input['requestId'],$actor)['state']==='sending','Belirsiz gönderim okunarak uzlaştırılır.');
$db->run('UPDATE marketplace_stock_requests SET next_run=0');$gateway->readError=new MarketplaceStockFailure(retryable:true);$worker->tick(time()+30);verify($gateway->posts===1&&$queue->get(2,$input['requestId'],$actor)['state']==='sending','Belirsiz POST sonrası okuma hatası yeniden yazmaya dönüşmez.');
$db->run('UPDATE marketplace_stock_requests SET next_run=0');$gateway->readError=null;$worker->tick(time()+30);verify($gateway->posts===1&&$queue->get(2,$input['requestId'],$actor)['state']==='uncertain','Farklı adet görülünce yeni POST yapılmaz.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$gateway->postError=new MarketplaceStockFailure(retryable:true,retryAfter:60);$worker->tick(time()+30);verify($queue->get(2,$input['requestId'],$actor)['state']==='queued','429 reddi kontrollü yeniden denemeye alınır.');verify((int)$db->one('SELECT next_run FROM marketplace_stock_requests')['next_run']>=time()+59,'Retry-After korunur.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$db->run('INSERT INTO account_controls VALUES(10,0)');$worker->tick(time()+30);verify($gateway->posts===0,'Yetkisi kapanmış yöneticinin bekleyen işi gönderilmez.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$db->run('UPDATE records SET version=2 WHERE company_id=2');$worker->tick(time()+30);verify($gateway->posts===0,'Bağlantı değişmişse önceki onayla gönderim yapılmaz.');
$configuration=['enabled'=>true,'merchantId'=>'11111111-1111-1111-1111-111111111111','username'=>'test_user','serviceKey'=>'test-secret','userAgent'=>'omnigo_dev'];$calls=[];
$adapter=new HepsiburadaStockGateway(static function($method,$url,$body)use(&$calls){$calls[]=[$method,$url,$body];return $method==='POST'?['id'=>'receipt-test-0001']:['listings'=>[['merchantSku'=>'SKU-A','hepsiburadaSku'=>'HBCVTEST1','availableStock'=>7]]];});
$read=$adapter->read($configuration,'SKU-A',time()+20);$adapter->submit($configuration,$read,9,time()+20);
verify(str_contains($calls[0][1],'merchantSkuList=SKU-A')&&str_starts_with($calls[1][1],'https://listing-external.hepsiburada.com/'),'Resmî canlı uç ve kesin SKU filtresi kullanılır.');
verify(array_keys($calls[1][2][0])===['hepsiburadaSku','merchantSku','availableStock'],'Fiyat, kargo ve satış limiti gönderilmez.');
$duplicate=new HepsiburadaStockGateway(static fn()=>['listings'=>array_fill(0,2,['merchantSku'=>'SKU-A','hepsiburadaSku'=>'HBCVTEST1','availableStock'=>7])]);rejects(fn()=>$duplicate->read($configuration,'SKU-A',time()+20),'Belirsiz mükerrer kaynak ürüne gönderim yapılamaz.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$gateway->quantity=6;$worker->tick(time()+30);
$retry=$queue->retry(2,$input['requestId'],$actor);verify($retry['quantity']===9&&$retry['state']==='queued','Yeniden deneme aynı istenen adedi korur.');
verify($queue->retry(2,$input['requestId'],$actor)['requestId']===$retry['requestId']&&count($db->all('SELECT * FROM marketplace_stock_requests'))===2,'Çift tıklama yalnız bir yeni talep oluşturur.');
rejects(fn()=>$queue->retry(1,$input['requestId'],$actor),'Başka mağazanın talebi yeniden gönderilemez.');rejects(fn()=>$queue->retry(2,$input['requestId'],['id'=>20,'role'=>'client']),'Müşteri yeniden deneme yapamaz.');
$history=(new Omnigo\InventoryHistory($db))->listing(2,$input['productKey'],'','','',null,true);
verify(count($history['items'])===2&&$history['items'][0]['marketplaceRequest']['quantity']===9,'İstenen adet ve başarısızlık ürün hareketlerinde gösterilir.');
verify((new Omnigo\InventoryHistory($db))->listing(2,$input['productKey'])['items']===[],'Eski model ve müşteri hareketleri harici yönetici taleplerini göstermez.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$gateway->postError=new MarketplaceStockFailure(uncertain:true);$worker->tick(time()+30);$db->run('UPDATE marketplace_stock_requests SET next_run=0');$worker->tick(time()+30);
rejects(fn()=>$queue->submit(2,[...$input,'requestId'=>'another-stock-0002'],$actor),'Belirsiz talep varken yeni yazma talebi engellenir.');$queue->retry(2,$input['requestId'],$actor);$worker->tick(time()+30);verify($gateway->posts===1,'Belirsiz talebin yeniden denemesi ikinci POST yerine yalnız doğrulama yapar.');
[$db,$policy,$queue,$gateway,$worker,$actor,$input]=fixture();$queue->submit(2,$input,$actor);$worker->tick(time()+30);$gateway->statusValue='pending';$db->run('UPDATE marketplace_stock_requests SET next_run=0,created=?',[gmdate('c',time()-1000)]);$worker->tick(time()+30);verify($queue->get(2,$input['requestId'],$actor)['state']==='uncertain','Uzun süren kabul edilmiş talep kesin başarısız sayılıp tekrar yazılmaz.');
$queue->retry(2,$input['requestId'],$actor);$worker->tick(time()+30);verify($gateway->posts===1,'Kabul edilmiş eski talepte yeniden deneme yeni stok göndermez.');
echo "Pazaryeri stok yönetimi: $checks kontrol geçti.\n";
