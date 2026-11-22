<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,OrderList,OrderRoutes,Security,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$db->run("INSERT INTO companies VALUES(1,'Firma A'),(2,'Firma B')");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];$repo=new OrderList($db);
for($i=1;$i<=29;$i++){$value=['externalId'=>'ORDER-'.$i,'createdAt'=>'2026-10-10T'.sprintf('%02d',intdiv($i,2)).':00:00+03:00','state'=>'Aktarıldı','orders'=>1,'grossCents'=>123456,'cancelCents'=>0,'returnCents'=>0,'products'=>[['title'=>'Örnek ürün','quantity'=>2]]];$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$i===29?2:1,$i%2?'order_n11':'order_pazarama',json_encode($value),gmdate('c')]);}
$value['externalId']='UTC-newest';$value['createdAt']='2026-10-10T12:30:00Z';$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'order_koctas',json_encode($value),gmdate('c')]);
$scope=['from'=>'2026-10-01','to'=>'2026-10-31'];$first=$repo->listing($admin,1,$scope);$last=$repo->listing($admin,1,[...$scope,'page'=>1]);
check($first['total']===29&&$first['pageSize']===15&&count($first['items'])===15&&count($last['items'])===14,'Sayfalama ve firma ayrımı');check($first['items'][0]['externalId']==='UTC-newest','Saat dilimlerinden bağımsız yeni sipariş ilk sırada');
check($repo->listing($admin,1,[...$scope,'provider'=>'n11'])['total']===14,'Pazaryeri filtresi');check($repo->listing($admin,1,['from'=>'2026-09-01','to'=>'2026-09-30'])['total']===0,'Tarih filtresi');
try{$repo->listing($client,1,$scope);throw new RuntimeException('Müşteri erişti');}catch(HttpError $e){check($e->getCode()===403,'Müşteriye kapalı');}
$_GET=['company'=>1,...$scope];try{(new OrderRoutes($db,new Security($db,[])))->handle('/api/admin/orders','GET',$client);throw new RuntimeException('Müşteri rotaya erişti');}catch(HttpError $e){check($e->getCode()===403,'Rota müşteriye kapalı');}
echo "Sipariş listesi: yalnızca yönetici, tarih/pazaryeri, UTC sıralama, sayfalama ve firma ayrımı geçti.\n";

$filtered=$repo->listing($admin,1,[...$scope,'query'=>'ORDER-1']);check($filtered['total']===11,'Arama yalnız ilk sayfaya değil tüm kapsamı uygular');
check($repo->listing($admin,1,[...$scope,'query'=>'Ornek urun','page'=>99])['page']===1,'Türkçe arama ve son sayfa sınırı');
check($repo->listing($admin,1,[...$scope,'query'=>'ORDER-29'])['total']===0,'Arama başka firmayı göstermez');
