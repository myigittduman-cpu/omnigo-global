<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,MarketplaceCatalog};
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies(id,name) VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'channels',?,?)",[json_encode(['name'=>'Trendyol','status'=>'inactive','storeName'=>'Korunacak mağaza','connection'=>'not_connected']),gmdate('c')]);
$before=$db->all('SELECT * FROM records');
$catalog=new MarketplaceCatalog($db);
$added=$catalog->addMissing(1,['Teknosa','Trendyol','Teknosa']);
check(count($added)===1&&$added[0]['name']==='Teknosa','Yalnız eksik pazaryeri eklenmeli');
check($catalog->addMissing(1,['Teknosa','Trendyol'])===[],'Tekrar çalıştırma çoğaltmamalı');
check($before[0]===$db->one('SELECT * FROM records WHERE id=?',[$before[0]['id']]),'Mevcut ayarlar korunmalı');
check(!$db->one('SELECT id FROM records WHERE company_id=2'),'Diğer müşteri değişmemeli');
$payload=json_decode($db->one('SELECT payload FROM records WHERE id=?',[$added[0]['id']])['payload'],true);
check($payload['connection']==='not_connected','Bağlantı kurulmuş gibi gösterilmemeli');
$id=(new Repository($db))->createCompany('Yeni müşteri');
check(count($db->all('SELECT * FROM records WHERE company_id=?',[$id]))===8,'Yeni müşteri sekiz pazaryeri almalı');
echo "Pazaryeri katalog testleri geçti.\n";
