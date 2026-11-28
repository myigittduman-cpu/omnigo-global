<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,CustomerPermissions,InventorySchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci'),(4,'Dördüncü')");
$actor=['id'=>5,'role'=>'client','company_id'=>2];
$before=(new CustomerPermissions($db,2))->access($actor);
check($before['overviewLayout']==='separate','Mevcut mağazanın varsayılanı değişmez.');
foreach([2,4] as $id)$db->run('INSERT INTO company_profiles(company_id,payload,version) VALUES(?,?,1)',[$id,json_encode(['overviewLayout'=>'tabs'])]);
$after=(new CustomerPermissions($db,2))->access($actor);
check($after['overviewLayout']==='tabs','Mağaza tercihi müşteri hesabına taşınır.');
unset($before['overviewLayout'],$before['version'],$after['overviewLayout'],$after['version']);
check($before===$after,'Görünüm tercihi yetkileri ve diğer ayarları değiştiremez.');
check((new CustomerPermissions($db,4))->access(['id'=>19,'role'=>'client','company_id'=>4])['overviewLayout']==='tabs','İkinci hedef mağaza aynı tercihi kullanır.');
check((new CustomerPermissions($db,1))->access(['id'=>2,'role'=>'client','company_id'=>1])['overviewLayout']==='separate','Vizyona ayrı görünümü korur.');
check(!isset((new CustomerPermissions($db,2))->access(['id'=>1,'role'=>'admin'])['overviewLayout']),'Yönetici görünümü değişmez.');
echo "Genel Bakış görünümü, mağaza izolasyonu ve yetki sürekliliği doğrulandı.\n";
