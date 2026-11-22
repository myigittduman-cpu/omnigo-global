<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryIssues};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$issues=new InventoryIssues($db);$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach([['old1',1,'n11'],['old2',1,'n11'],['other-company',2,'n11'],['other-provider',1,'Pazarama']] as [$key,$company,$provider])$db->run('INSERT INTO inventory_issues VALUES(?,?,?,?,?,?)',[$company,hash('sha256',$key),$provider,'ORDER1','Eski uyarı',gmdate('c')]);
$issues->record(1,'n11','ORDER1',hash('sha256','new-row'),'Güncel uyarı');
verify((int)$db->one("SELECT COUNT(*) n FROM inventory_issues WHERE company_id=1 AND provider='n11'")['n']===1,'Değişen kaynak satır kimlikleri uyarıyı çoğaltmaz.');
$issues->record(1,'n11','ORDER1',hash('sha256','newer-row'),'Son uyarı');
verify($db->one("SELECT message FROM inventory_issues WHERE company_id=1 AND provider='n11'")['message']==='Son uyarı','Tek uyarı güncellenir.');
$issues->resolve(1,'n11','ORDER1',hash('sha256','latest-row'));
verify((int)$db->one("SELECT COUNT(*) n FROM inventory_issues WHERE company_id=1 AND provider='n11'")['n']===0,'Çözülen siparişin eski uyarıları temizlenir.');
verify((int)$db->one('SELECT COUNT(*) n FROM inventory_issues')['n']===2,'Başka müşteri ve pazaryeri korunur.');
$issues->record(1,'n11','',hash('sha256','unnumbered'),'Numara eksik');
$issues->resolve(1,'n11','',hash('sha256','unnumbered'));
verify((int)$db->one('SELECT COUNT(*) n FROM inventory_issues')['n']===2,'Numarası olmayan kaynak kendi kimliğiyle çözülür.');
echo "$checks stok uyarısı kontrolü geçti.\n";
