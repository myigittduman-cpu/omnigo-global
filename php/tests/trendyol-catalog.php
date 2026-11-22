<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryProgress,IntegrationVault,TrendyolCatalogReadClient,TrendyolCatalogPage,TrendyolCatalogProgress,SyncFailure};
$checks=0;
function tcCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function tcReject(callable $action,int $code,string $message):void{try{$action();}catch(SyncFailure $e){tcCheck($e->httpStatus===$code,$message.' yanlış kod');return;}throw new RuntimeException($message);}
function tcVariant(string $barcode='BC-1',?int $quantity=4,string|int $seller=123):array{return ['supplierId'=>$seller,'barcode'=>$barcode,'stockCode'=>'SKU-'.$barcode,'stock'=>['quantity'=>$quantity],'onSale'=>true,'locked'=>false,'archived'=>false,'blacklisted'=>false,'hasViolation'=>false];}
function tcResponse(array $content,int $total=1,int $page=0,int $size=100):array{return ['content'=>$content,'totalElements'=>$total,'totalPages'=>(int)ceil($total/$size),'page'=>$page,'size'=>$size,'nextPageToken'=>'opaque-page-token'];}
function tcContent(array $variants):array{return ['title'=>'Gerçek Ürün Adı','brand'=>['name'=>'Marka'],'images'=>[['url'=>'https://cdn.example.com/product.jpg']],'variants'=>$variants];}
function tcClient(array $response,array &$requests=[],int $status=200,int $retry=0):TrendyolCatalogReadClient{return new TrendyolCatalogReadClient(static function($url,$headers,$deadline)use($response,&$requests,$status,$retry){$requests[]=['url'=>$url,'headers'=>$headers];return ['status'=>$status,'body'=>json_encode($response,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),'retryAfter'=>$retry];});}
$config=['enabled'=>true,'supplierId'=>'123','apiKey'=>'synthetic-key','apiSecret'=>'synthetic-secret','revision'=>'catalog-revision'];
$raw=tcResponse([tcContent([tcVariant('BC-1',0),tcVariant('BC-2',7)])]);$requests=[];
$reply=tcClient($raw,$requests)->page($config,0,0,null,time()+20);$page=TrendyolCatalogPage::normalize($reply,0,0);
tcCheck(count($page['items'])===2&&$page['items'][0]['quantity']===0&&$page['items'][1]['quantity']===7,'İki varyant ayrı, sıfır stok geçerli.');
tcCheck($page['items'][0]['title']==='Gerçek Ürün Adı'&&$page['items'][0]['sku']==='SKU-BC-1','Ürün adı ile doğru SKU aynı varyantı izler.');
tcCheck($page['items'][0]['imageCandidates']===['https://cdn.example.com/product.jpg'],'Resim adayı doğru içerikten alınır.');
tcCheck(str_contains($requests[0]['url'],'/products/approved?')&&!str_contains($requests[0]['url'],'synthetic'),'Sabit V2 GET adresinde anahtar yok.');
tcCheck(in_array('User-Agent: 123 - OmnigoGlobal',$requests[0]['headers'],true),'User-Agent doğru satıcıya aittir.');
$unknown=$raw;$unknown['content'][0]['variants'][0]['stock']=[];
tcCheck(TrendyolCatalogPage::normalize($unknown,0,0)['items'][0]['quantity']===null,'Kaynakta olmayan stok sıfır yapılmaz.');
$closed=$raw;$closed['content'][0]['variants'][0]['archived']=true;
tcCheck(TrendyolCatalogPage::normalize($closed,0,0)['items'][0]['saleActive']===false,'Arşivli ürün satışa açık sayılmaz.');
$draft=tcResponse([['supplierId'=>123,'barcode'=>'DRAFT','stockCode'=>'SKU-DRAFT','title'=>'Taslak Ürün','quantity'=>2,'status'=>'pendingApproval','media'=>[['url'=>'https://cdn.example.com/draft.png']]]]);
tcCheck(TrendyolCatalogPage::normalize(tcClient($draft)->page($config,0,1,null,time()+20),0,1)['items'][0]['saleActive']===false,'Taslak stok alınır fakat satışa açık sayılmaz.');
foreach([null,true,123.0,'0123','456',[]] as $owner){$bad=$raw;$bad['content'][0]['variants'][1]['supplierId']=$owner;tcReject(fn()=>tcClient($bad)->page($config,0,0,null,time()+20),403,'Yabancı veya bozuk satıcı tüm sayfayı engellemeli.');}
$bad=$raw;unset($bad['content'][0]['variants'][1]['supplierId']);tcReject(fn()=>tcClient($bad)->page($config,0,0,null,time()+20),403,'Eksik varyant satıcısı reddedilmeli.');
$bad=$raw;$bad['content'][0]['title']='';tcReject(fn()=>tcClient($bad)->page($config,0,0,null,time()+20),422,'Boş ad yalnız SKU ile kaydedilmemeli.');
foreach([-1,1.2,true,'broken'] as $quantity){$bad=$raw;$bad['content'][0]['variants'][0]['stock']['quantity']=$quantity;tcReject(fn()=>TrendyolCatalogPage::normalize($bad,0,0),422,'Geçersiz stok reddedilmeli.');}
$bad=$raw;$bad['content'][0]['variants'][1]=$bad['content'][0]['variants'][0];tcReject(fn()=>TrendyolCatalogPage::normalize($bad,0,0),409,'Tek sayfada aynı barkod iki kez sayılmaz.');
$empty=tcResponse([],0);tcCheck(TrendyolCatalogPage::normalize($empty,0,1)['next']===false,'Boş onaysız katalog doğru tamamlanır.');
$huge=tcResponse([tcContent([tcVariant()])],10001,100);$last=[];tcClient($huge,$last)->page($config,100,0,'private-cursor',time()+20);
tcCheck(str_contains($last[0]['url'],'nextPageToken=private-cursor')&&!str_contains($last[0]['url'],'page='),'10.000 içerikten sonra kalıcı token kullanılır.');
tcReject(fn()=>tcClient($huge)->page($config,100,0,null,time()+20),422,'Sınırdan sonra token olmadan sayfa atlanmaz.');
try{tcClient([], $requests,429,120)->page($config,0,0,null,time()+20);throw new RuntimeException('429 kabul edildi.');}catch(SyncFailure $e){tcCheck($e->httpStatus===429&&$e->retryAfter===120,'429 geri çekilme süresi korunur.');}

$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Bir'),(2,'İki')");
$keyPath=tempnam(sys_get_temp_dir(),'tc-key-');file_put_contents($keyPath,random_bytes(32));
try{
    $vault=new IntegrationVault($db,$keyPath);$vault->save(1,$config,'trendyol');$vault->save(2,[...$config,'supplierId'=>'456'],'trendyol');
    $before=$db->all("SELECT * FROM records WHERE kind='integration_trendyol' ORDER BY company_id");
    $progress=new InventoryProgress($db);$writer=new TrendyolCatalogProgress($db,$vault);$progress->ensure(1,'trendyol');$progress->ensure(2,'trendyol');
    $pass=fn(int $company)=>$db->one("SELECT started_at,completed_at FROM inventory_catalog_verified_passes WHERE company_id=? AND provider='trendyol'",[$company]);
    $active=new \Omnigo\ActiveMarketplaceListings($db);
    $count=fn(int $company,string $query='')=>$active->page($company,$query,'Trendyol',0)['total'];
    $job=$progress->claim(1,'trendyol');tcCheck($writer->prepare(1,$job,$config['revision'])['cursor']===null,'İlk sayfa temiz başlar.');
    tcCheck($writer->commit(1,$job,$page,$config['revision']),'Onaylı sayfa kaydedildi.');
    $state=$db->one("SELECT * FROM inventory_catalog_progress WHERE company_id=1");$savedJob=$db->one('SELECT * FROM inventory_catalog_jobs WHERE company_id=1');
    tcCheck($state['state']==='running'&&$savedJob['last_success']===null&&(int)$savedJob['partition_no']===1,'Onaylılar bitince taslaklar bitmeden başarı yok.');
    tcCheck(!$pass(1)&&$count(1,'SKU-BC-1')===0,'İlk onaylı bölüm ve filtreli okuma tamamlanmış tur kanıtı yaratmaz.');
    tcCheck((int)$db->one('SELECT COUNT(*) n FROM inventory_products WHERE company_id=2')['n']===0,'İlk müşteri verisi ikinci müşteriye geçmez.');
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');
    tcCheck(empty($writer->prepare(1,$job,$config['revision'])['restart']),'İkinci partition kesintiden sonra devam eder.');
    $writer->commit(1,$job,TrendyolCatalogPage::normalize($empty,0,1),$config['revision']);
    $state=$db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=1');
    tcCheck($state['state']==='complete'&&(int)$state['items_done']===2,'İki bölüm ve bütün varyantlar tamamlandı.');
    $savedJob=$db->one('SELECT last_success FROM inventory_catalog_jobs WHERE company_id=1');
    $verified=$pass(1);
    tcCheck((int)$verified['started_at']===(int)$state['started_at']&&(int)$verified['completed_at']===strtotime($savedJob['last_success'])&&$count(1)===2,'Katalog son başarısı ve tam tur tek işlemde aynı zamana aittir.');
    tcCheck(!$pass(2)&&$count(2)===0,'Başka mağaza ilk tamamlanan turu kullanamaz.');
    tcCheck($db->all("SELECT * FROM records WHERE kind='integration_trendyol' ORDER BY company_id")===$before,'API bilgileri ve sürümleri değişmedi.');
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');
    tcCheck($count(1)===2&&$count(1,'SKU-BC-2')===1,'İkinci taramanın ilk sayfası henüz gelmeden önceki tam tur listelenir.');
    $db->run("UPDATE records SET version=version+1,updated=? WHERE company_id=1 AND kind='integration_trendyol'",[gmdate('c',(int)$verified['started_at'])]);
    tcCheck($count(1)===0,'Aynı saniyedeki bağlantı değişikliği önceki tam turu kullanamaz.');
    $db->run("UPDATE records SET version=?,updated=? WHERE company_id=1 AND kind='integration_trendyol'",[$before[0]['version'],$before[0]['updated']]);
    $writer->commit(1,$job,$page,$config['revision']);
    tcCheck($pass(1)===$verified&&$count(1)===2,'Son başarılı tur yeni turun iki bölümü arasındayken korundu.');
    $db->run("DELETE FROM inventory_catalog_verified_passes WHERE company_id=1 AND provider='trendyol'");
    tcCheck($count(1)===0,'Eski sürümde kaybolan tam tur, aradaki sayfalardan uydurulmaz.');
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');$writer->commit(1,$job,TrendyolCatalogPage::normalize($empty,0,1),$config['revision']);
    tcCheck((int)$db->one('SELECT COUNT(*) n FROM inventory_listings WHERE company_id=1')['n']===2,'Tekrarlanan tam tarama ürün/listing çoğaltmaz.');
    tcCheck($pass(1)!==null&&$count(1)===2,'İlk gerçek tam turun bitişi eksik eski kanıtı kendiliğinden giderir.');
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');$writer->commit(1,$job,TrendyolCatalogPage::normalize($unknown,0,0),$config['revision']);
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');$writer->commit(1,$job,TrendyolCatalogPage::normalize($empty,0,1),$config['revision']);
    tcCheck($db->one('SELECT state FROM inventory_catalog_progress WHERE company_id=1')['state']==='incomplete','Eksik stoklu tarama tamamlandı sayılmaz.');
    tcCheck($count(1)===0,'Eksik stoklu tarama önceki başarı üzerinden güncel kabul edilmez.');
    $db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=1');$job=$progress->claim(1,'trendyol');$stale=$job;$db->run('UPDATE inventory_catalog_progress SET lease_until=0 WHERE company_id=1');$fresh=$progress->claim(1,'trendyol');
    tcReject(fn()=>$writer->commit(1,$stale,$page,$config['revision']),409,'Süresi geçen işçi yazamaz.');
    tcReject(fn()=>$writer->commit(1,$fresh,$page,'different-revision'),409,'Farklı bağlantı revizyonu yazamaz.');
    tcCheck((int)$db->one('SELECT COUNT(*) n FROM inventory_trendyol_checkpoints WHERE company_id=2')['n']===0,'Checkpoint müşteriye özeldir.');
    $db->run("UPDATE inventory_catalog_jobs SET page_no=100,partition_no=0 WHERE company_id=1");$badJob=[...$fresh,'page_no'=>100,'partition_no'=>0];
    tcCheck(!empty($writer->prepare(1,$badJob,$config['revision'])['restart']),'Kayıp/çelişkili imleçte baştan eksiksiz tarama planlanır.');
    tcCheck((int)$db->one('SELECT page_no FROM inventory_catalog_jobs WHERE company_id=1')['page_no']===0,'Kayıp checkpoint sayfa atlamaz.');
    tcCheck($count(1)===0&&!$pass(2),'Kuyruğa dönen tutarsız tur listelenmez ve diğer mağazanın kanıtını etkilemez.');
    $db->run("INSERT INTO companies VALUES(3,'Silinecek')");$db->run("INSERT INTO inventory_trendyol_checkpoints(company_id,payload,updated) VALUES(3,'{}',?)",[gmdate('c')]);$db->run('DELETE FROM companies WHERE id=3');
    tcCheck(!$db->one('SELECT company_id FROM inventory_trendyol_checkpoints WHERE company_id=3'),'Müşteri silindiğinde türetilmiş checkpoint de silinir.');
    echo "$checks Trendyol V2 ürün/varyant, stok, sahiplik, sayfalama, yeniden deneme ve müşteri ayrımı kontrolü geçti.\n";
}finally{unlink($keyPath);}
