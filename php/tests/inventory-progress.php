<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryProgress,InventoryCatalogPage};
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Test'),(2,'Other')");
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$fixtures=[
 'n11'=>['content'=>[['n11ProductId'=>'N1','title'=>'Product','stockCode'=>'SKU1','quantity'=>25]],'totalPages'=>2],
 'hepsiburada'=>['listings'=>[['listingId'=>'H1','merchantSku'=>'SKU1','availableStock'=>25]],'totalCount'=>101],
 'pazarama'=>['data'=>[['code'=>'P1','displayName'=>'Product','stockCode'=>'SKU1','stockCount'=>25]],'success'=>true,'hasNextPage'=>true],
 'idefix'=>['products'=>[['barcode'=>'I1','title'=>'Product','vendorStockCode'=>'SKU1','inventoryQuantity'=>25]]],
 'koctas'=>['offers'=>[['offer_id'=>'K1','product_title'=>'Product','shop_sku'=>'SKU1','quantity'=>25]],'total_count'=>101],
 'pttavm'=>[['urunId'=>'T1','urunAdi'=>'Product','urunKodu'=>'SKU1','miktar'=>25]],
];
foreach($fixtures as $provider=>$fixture){
 $page=InventoryCatalogPage::normalize($provider,$fixture,0);
 check(count($page['items'])===1&&$page['items'][0]['quantity']===25,$provider.' quantity');
 check($page['next']===true,$provider.' pagination');
 check(!isset($page['items'][0]['image']),$provider.' images deferred');
}
$progress=new InventoryProgress($db);$progress->ensure(1,'n11');
$job=$progress->claim(1,'n11');check($job!==null,'First device claims');
check($progress->claim(1,'n11')===null,'Second device does not duplicate');
$progress->commit(1,$job,InventoryCatalogPage::normalize('n11',$fixtures['n11'],0));
check((int)$db->one('SELECT page_no FROM inventory_catalog_jobs WHERE company_id=1')['page_no']===1,'Checkpoint committed');
check((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=2')['n']===0,'Tenant isolation');
$db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');
$job=$progress->claim(1,'n11');
try{$progress->commit(1,$job,InventoryCatalogPage::normalize('n11',$fixtures['n11'],1));throw new RuntimeException('Repeated page accepted');}
catch(DomainException $e){$progress->fail(1,$job,$e);}
check((int)$db->one('SELECT page_no FROM inventory_catalog_jobs WHERE company_id=1')['page_no']===1,'Failed page does not skip');
check($progress->status(1)['state']==='retry'&&$progress->status(1)['percent']!==100,'Failure is not success');
$db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');
$job=$progress->claim(1,'n11');$db->run('UPDATE inventory_catalog_progress SET lease_until=0 WHERE company_id=1');
$resumed=$progress->claim(1,'n11');check($resumed!==null&&$resumed['token']!==$job['token'],'Expired worker resumes');
try{$progress->commit(1,$job,['items'=>[],'next'=>false]);throw new RuntimeException('Old worker accepted');}catch(DomainException){$checks++;}
$progress->commit(1,$resumed,['items'=>[['id'=>'N2','title'=>'Missing','sku'=>'SKU2','quantity'=>null]],'next'=>false]);
check($progress->status(1)['state']==='incomplete'&&$progress->status(1)['percent']!==100,'Missing stock never reports 100');
$db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');
$job=$progress->claim(1,'n11');
$progress->commit(1,$job,['items'=>[['id'=>'N1','title'=>'Product','sku'=>'SKU1','quantity'=>24],['id'=>'N2','title'=>'Missing','sku'=>'SKU2','quantity'=>0]],'next'=>false,'totalPages'=>1]);
check($progress->status(1)['percent']===100,'Verified stock completes');
check((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=1')['n']===2,'Retry creates no duplicate product');
check((int)$db->one("SELECT source_quantity FROM inventory_listings WHERE company_id=1 AND sku='SKU1'")['source_quantity']===24,'Source update replaces snapshot');
echo "$checks katalog, kesinti, yeniden deneme ve sayfalama kontrolü geçti.\n";
