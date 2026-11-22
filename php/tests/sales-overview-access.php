<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Security,CustomerPermissions,CustomerPermissionRoutes,HttpError,KoctasOrderMapper,SalesTopProducts};
function check(bool $v,string $message):void{if(!$v)throw new RuntimeException($message);}
function denied(callable $call):void{try{$call();throw new RuntimeException('Erişim kabul edildi');}catch(HttpError $e){check($e->getCode()===403,'Erişim 403 ile reddedilmeli');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
\Omnigo\InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','x','admin',NULL),(2,'client@example.test','Müşteri','x','client',1)");
$actor=['id'=>2,'role'=>'client','company_id'=>1];$admin=['id'=>1,'role'=>'admin'];$security=new Security($db,['origin'=>'https://portal.example.invalid']);
foreach(['/api/sales/detail','/api/sales/top-products','/api/sales/product-image'] as $path){$_GET=['company'=>'1'];CustomerPermissionRoutes::enforce($db,$security,$actor,$path,'GET');$_GET=['company'=>'2'];denied(fn()=>CustomerPermissionRoutes::enforce($db,$security,$actor,$path,'GET'));}
$paths=['detail','top-products','product-image'];
$matrix=['overviewSales'=>['detail','product-image'],'overviewTop'=>['top-products','product-image'],
    'inventory'=>['product-image'],'analytics'=>$paths,'none'=>[]];
foreach($matrix as $enabled=>$allowed){
    $policy=new CustomerPermissions($db,1);
    $config=$policy->configuration();
    $grants=$config['defaults'];
    foreach(['overview','channels','analytics','overviewSales','overviewTop','inventory'] as $page){
        $config['pages'][$page]=$enabled===$page?'shown':'removed';
        $grants[$page]['view']=$enabled===$page;
    }
    $policy->save($admin,['version'=>$config['version'],'pages'=>$config['pages'],'userChanges'=>[$actor['id']=>$grants]]);
    foreach($paths as $suffix){
        $read=fn()=>CustomerPermissionRoutes::enforce($db,$security,$actor,'/api/sales/'.$suffix,'GET');
        $_GET=['company'=>'1'];
        if(in_array($suffix,$allowed,true))$read();else denied($read);
        $_GET=['company'=>'2'];denied($read);
    }
}
$report=(new SalesTopProducts($db))->report($admin,2,'2026-10-01','2026-10-03');check($report['products']===[],'Boş mağaza başka mağazanın verilerini alamaz');
$save=fn($kind,$payload)=>$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(2,?,?,?)',[$kind,json_encode($payload),gmdate('c')]);
$save('channels',['name'=>'Koçtaş','status'=>'active']);$save('sync_koctas',['startDate'=>'2026-09-01T00:00:00+03:00','watermark'=>'2026-10-04T10:00:00+03:00']);
$source=['order_id'=>'KOCTAS-001','created_date'=>'2026-10-02T10:00:00+03:00','order_state'=>'SHIPPED','currency_iso_code'=>'TRY',
    'order_lines'=>[['order_line_state'=>'SHIPPED','offer_sku'=>'MODEL','product_title'=>'Örnek ürün','quantity'=>2,'price'=>1000]]];
$order=KoctasOrderMapper::map($source);
$save('order_koctas',$order);$service=new SalesTopProducts($db);$known=$service->report($admin,2,'2026-10-01','2026-10-03');check($known['rankingAvailable']&&count($known['products'])===1&&$known['products'][0]['netQuantity']===2,'TOP 10 mevcut satış hesabını aynen kullanmalı');
$legacy=KoctasOrderMapper::map([...$source,'order_id'=>'KOCTAS-002']);unset($legacy['products']);
$save('order_koctas',$legacy);$missing=$service->report($admin,2,'2026-10-01','2026-10-03');check(!$missing['rankingAvailable']&&$missing['products']===[]&&count($missing['notes'])>0,'Eksik ürün aktarımında yanlış sıralama yayımlanmamalı');
echo "Genel Bakış satış uçları: üç uçta mağaza izolasyonu, gizli bölüm yetkileri ve boş müşteri kontrolü geçti.\n";
