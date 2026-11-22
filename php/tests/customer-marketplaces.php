<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerOnboarding,CustomerMarketplaces,IntegrationVault,MarketplaceConnection,HttpError};
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function fails(callable $action,int $code):void{try{$action();throw new RuntimeException('Hata bekleniyordu.');}catch(HttpError $error){check($error->getCode()===$code,'Beklenen hata: '.$code);}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));\Omnigo\InventorySchema::install($db);
$db->run("INSERT INTO users VALUES(1,'admin@example.invalid','Yönetici','x','admin',NULL)");$actor=['id'=>1,'role'=>'admin'];
$one=CustomerOnboarding::create($db,$actor,'Birinci',null,null,['PTT AVM']);
$two=CustomerOnboarding::create($db,$actor,'İkinci',null,null,['PTT AVM','n11']);
$service=new CustomerMarketplaces($db);
check(count(array_filter($service->listing($one),fn($m)=>$m['selected']))===1,'Yalnız seçilen mağaza oluşturulur.');
$third=CustomerOnboarding::create($db,$actor,'API sonra',null,null,[]);
check(!array_filter($service->listing($third),fn($m)=>$m['selected']),'API olmadan boş kurulum yapılır.');
fails(fn()=>CustomerOnboarding::create($db,$actor,'Hatalı',null,null,['Bilinmeyen']),400);
$key=tempnam(sys_get_temp_dir(),'market-test');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);$connection=new MarketplaceConnection($db,$vault,'pttavm');
 foreach([$one,$two] as $id)$connection->save($id,['apiKey'=>'key-'.$id,'accessToken'=>'token-'.$id,'version'=>0]);
 $snapshot=$db->all('SELECT * FROM records WHERE company_id=? ORDER BY id',[$two]);$otherJobs=$db->all('SELECT * FROM jobs WHERE company_id=?',[$two]);
 $history=json_encode(['externalId'=>'same-order','grossCents'=>12345,'orders'=>1]);
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sales',?,?)",[$one,$history,gmdate('c')]);
 $find=fn($company,$key)=>array_values(array_filter($service->listing($company),fn($m)=>$m['provider']===$key))[0];
 $item=$find($one,'pttavm');$service->change($actor,$one,'pttavm',['action'=>'hide','version'=>$item['version']]);
 check($find($one,'pttavm')['hidden']&&$connection->status($one)['configured'],'Gizleme API bağlantısını kaldırmaz.');
 check($db->one("SELECT state FROM jobs WHERE company_id=? AND provider='PTT AVM'",[$one])['state']==='pending','Gizleme aktarımı durdurmaz.');
 fails(fn()=>$service->change($actor,$one,'pttavm',['action'=>'delete','version'=>$find($one,'pttavm')['version'],'unconfiguredOnly'=>true]),409);
 check($find($one,'pttavm')['selected']&&$connection->status($one)['configured'],'Kurulum ekranı kaydedilmiş API bağlantısını kaldıramaz.');
 fails(fn()=>$service->change($actor,$one,'pttavm',['action'=>'delete','version'=>$item['version']]),409);
 fails(fn()=>$service->change(['id'=>1,'role'=>'client'],$one,'pttavm',['action'=>'delete','version'=>$find($one,'pttavm')['version']]),403);
 $db->run("UPDATE jobs SET state='running',lease_until=? WHERE company_id=?",[time()+300,$one]);
 fails(fn()=>$service->change($actor,$one,'pttavm',['action'=>'delete','version'=>$find($one,'pttavm')['version']]),409);
 check($connection->status($one)['configured'],'Çalışan aktarım silinmez.');
 $db->run("UPDATE jobs SET state='pending',lease_until=NULL WHERE company_id=?",[$one]);
 $service->change($actor,$one,'pttavm',['action'=>'disconnect','version'=>$find($one,'pttavm')['version']]);
 check(!$connection->status($one)['configured']&&$find($one,'pttavm')['selected'],'Kaldırma yalnız API ve kuyruğu kaldırır.');
 $service->change($actor,$one,'pttavm',['action'=>'delete','version'=>$find($one,'pttavm')['version']]);
 check(!$find($one,'pttavm')['selected'],'Mağaza tanımı silinir.');
 check($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sales'",[$one])['payload']===$history,'Geçmiş tutarlar korunur.');
 check($snapshot===$db->all('SELECT * FROM records WHERE company_id=? ORDER BY id',[$two])&&$otherJobs===$db->all('SELECT * FROM jobs WHERE company_id=?',[$two]),'Diğer müşterinin API bilgileri ve işleri birebir korunur.');
 $service->change($actor,$one,'trendyol',['action'=>'add','version'=>0,'storeName'=>'Yeni mağaza']);
 check($find($one,'trendyol')['selected']&&!$find($one,'trendyol')['configured'],'Trendyol seçilebilir; anahtar girilmeden bağlantı var gösterilmez.');
 $service->change($actor,$one,'trendyol',['action'=>'delete','version'=>$find($one,'trendyol')['version'],'unconfiguredOnly'=>true]);
 check(!$find($one,'trendyol')['selected']&&$find($two,'n11')['selected'],'Kurulum seçimi yalnız seçili mağazadan kaldırılır.');
 echo "Pazaryeri yönetimi: seçim, erteleme, müşteri yalıtımı, rol, sürüm, çalışan iş kilidi, kaldırma ve geçmişi koruyan silme geçti.\n";
}finally{unlink($key);}
